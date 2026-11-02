<?php

namespace App\Http\Middleware;

use App\Services\ApiRateLimitPolicyService;
use App\Services\UipJwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * نسخة طبق الأصل من app/Middleware/RateLimitMiddleware.php القديمة —
 * نفس fixed-window counter، ونفس actorKey() (bucket لكل مستخدم مسجّل
 * دخول عن طريق الـ Bearer JWT، مش IP بس، عشان أكتر من تبويب/بورتال من
 * نفس الـ IP ميشاركوش bucket واحد) بس باستخدام Cache::add()/increment()
 * بدل كتابة ملفات flat تحت storage/cache/ يدويًا (Laravel's default
 * 'file' cache driver بيعمل نفس الحاجة تحت الغطا من غير إعادة اختراع
 * القفل الملفي).
 *
 * بند 25 batch 3 (Security Portal) قفل الفجوة الموثّقة هنا سابقًا: الحد
 * دلوقتي بييجي من ApiRateLimitPolicyService (سياسة أدمن قابلة للتعديل،
 * `rate_limit.policy`)، مع fallback لـ config('security.rate_limit_per_min')
 * لو الصف مفقود أو السياسة معطّلة (enabled=false) أو الداتابيز مش متاحة
 * لحظيًا — الـ API میفشلش open/غير محمي في أي من الحالتين.
 */
class UipRateLimitMiddleware
{
    public function __construct(
        private ApiRateLimitPolicyService $rateLimitPolicy
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // محاولات الامتحان الشغالة (حفظ إجابة / أحداث أمان / تسليم) ليها bucket
        // مستقل وحد أعلى: الحفظ التلقائي وأحداث الـ secure mode بيستهلكوا طلبات
        // كتير، ولو شاركوا الـ bucket العام (60/دقيقة) طالب في نص امتحان ممكن
        // ياخد 429 ويتأخر حفظ إجاباته. لسه فيه سقف (مش مفتوح) ضد أي إساءة استخدام.
        // السياسة متوقفة من Security > Policies => مفيش حد خالص (قبل كده كان بيرجع لـ 60 من الـ config).
        if ($this->policyDisabled()) {
            return $next($request);
        }

        $isExamAttempt = $this->isExamAttemptRequest($request);
        $isCall = !$isExamAttempt && $this->isCallRequest($request);
        $limit = $isExamAttempt
            ? (int) config('security.exam_attempt_rate_limit_per_min', 240)
            : ($isCall ? (int) config('calls.rate_limit_per_min', 300) : $this->resolveLimit());

        $window = (int) floor(time() / 60);
        $actor = $this->actorKey($request);
        $key = 'ratelimit_' . ($isExamAttempt ? 'exam_' : ($isCall ? 'calls_' : '')) . preg_replace('/[^a-zA-Z0-9]/', '_', $actor) . '_' . $window;

        // قبل تسجيل الدخول مفيش Bearer، فالطلبات كانت بتتعدّ كلها على IP واحد. لو الـ IP مشترك (سيرفر Vercel لو UIP_PROXY_SECRET
        // مش مضبوط، أو واي فاي الجامعة/NAT) كل الناس بتشارك 60 طلب/دقيقة فواحد مابيعملش ولا طلب ويجيله "طلبات كتير".
        // دلوقتي الزائر غير المسجّل ليه bucket خاص بيه (IP + المتصفح + اللغة)، ومعاه سقف أعلى بكتير على الـ IP نفسه ضد الإساءة.
        if (str_starts_with($actor, 'anon_')) {
            $ipCap = max($limit * 10, 600);
            $ipKey = 'ratelimit_ipcap_' . preg_replace('/[^a-zA-Z0-9]/', '_', (string) $request->ip()) . '_' . $window;
            Cache::add($ipKey, 0, 65);
            if ((int) Cache::increment($ipKey) > $ipCap) {
                return $this->tooMany();
            }
        }

        // Cache::add + increment ذريّة (Redis) وبتقلل العمليات من 3 لـ 2 في كل ريكويست؛ والعدّ بعد الزيادة بيمنع تخطّي الحد في السباق.
        Cache::add($key, 0, 65); // TTL شوية أكبر من الدقيقة عشان أي تأخير بسيط في التوقيت بين الطلبات
        $count = (int) Cache::increment($key);

        if ($count > $limit) {
            return $this->tooMany();
        }

        return $next($request);
    }

    private function tooMany(): Response
    {
        return response()->json([
            'success' => false,
            'message' => 'Too many requests.',
            'data'    => null,
            'errors'  => null,
            'meta'    => (object) [],
        ], 429)->header('Retry-After', (string) (60 - (time() % 60)));
    }

    /** مسارات الطالب اللي جوه محاولة امتحان: /api/v1/exam-system/attempts/{id}/... */
    private function isExamAttemptRequest(Request $request): bool
    {
        return $request->is('api/v1/exam-system/attempts/*');
    }

    /** مسارات المكالمات: /api/v1/messaging/calls/... — polling سريع (كل 1-4 ثواني) بيحتاج bucket مستقل عن الحد العام. */
    private function isCallRequest(Request $request): bool
    {
        return $request->is('api/v1/messaging/calls', 'api/v1/messaging/calls/*');
    }

    /** true بس لو السياسة اتقرت بنجاح وenabled=false؛ أي خطأ في القراءة => نفضل محميين بالـ config. */
    private function policyDisabled(): bool
    {
        try {
            return !$this->rateLimitPolicy->getPolicy()['enabled'];
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** السياسة المخزّنة لو متاحة ومفعّلة، وإلا الـ config الثابت — عمرها ما ترمي استثناء يوقف الطلب. */
    private function resolveLimit(): int
    {
        try {
            $policy = $this->rateLimitPolicy->getPolicy();
            if ($policy['enabled']) {
                return $policy['requests_per_minute'];
            }
        } catch (\Throwable $e) {
            // الداتابيز مش متاحة لحظيًا أو أي خطأ تاني — نرجع للـ config الثابت
            // بدل ما نوقع الطلب بالكامل أو نفشل open.
        }

        return (int) config('security.rate_limit_per_min', 60);
    }

    /** نفس actorKey() بتاعة القديم بالظبط — user_{sub} لو فيه Bearer صالح، وإلا ip_{ip}. */
    private function actorKey(Request $request): string
    {
        $header = (string) $request->header('Authorization', '');
        if (str_starts_with($header, 'Bearer ')) {
            $claims = UipJwtService::decodeAccess(substr($header, 7));
            if ($claims && !empty($claims['sub'])) {
                return 'user_' . $claims['sub'];
            }
        }
        return 'anon_' . sha1(implode('|', [
            (string) $request->ip(),
            (string) $request->userAgent(),
            (string) $request->header('Accept-Language', ''),
        ]));
    }
}
