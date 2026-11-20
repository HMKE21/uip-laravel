<?php

namespace App\Http\Middleware;

use App\Services\Mail\MailSecurityService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * UIPE Mail — المرحلة 7: IP rules على /api/v1/mail/*.
 *  - قواعد المنصة (block) بتتطبّق على كل الصناديق.
 *  - قواعد الجامعة (block / allow) بتتطبّق لما المستخدم بيستخدم صندوق مؤسسي تابع للجامعة دي (الصندوق الشخصي = قواعد المنصة بس).
 * مش متركّب على /v1/mail-admin عمدًا: لو أدمن الجامعة اتقفل عليه بقاعدة allow غلط لازم يفضل قادر يدخل ويصلّحها.
 * لازم يتحط بعد uip.auth (محتاج uip_user_id). مفيش استعلامات لو مفيش قواعد أصلًا (الكاش 60 ثانية).
 */
class UipMailIpMiddleware
{
    public function __construct(private MailSecurityService $security)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        $ip = (string) $request->ip();
        if ($request->isMethod('OPTIONS') || !$userId || $ip === '' || !$this->security->hasRules()) {
            return $next($request);
        }

        $accountId = (int) ($request->query('account_id') ?: $request->header('X-Mail-Account'));
        $q = DB::table('mail_accounts')->where('user_id', $userId);
        $acc = $accountId
            ? (clone $q)->where('id', $accountId)->first(['id', 'university_id', 'tenant_id'])
            : (clone $q)->orderByDesc('is_default')->orderBy('id')->first(['id', 'university_id', 'tenant_id']);
        $uni = $acc && $acc->tenant_id && $acc->university_id ? (int) $acc->university_id : null;

        $reason = $this->security->ipBlockReason($ip, $uni);
        if ($reason === null) {
            return $next($request);
        }

        $this->security->event('ip_blocked', 'low', ['university_id' => $uni, 'account_id' => $acc->id ?? null, 'user_id' => $userId, 'ip' => $ip, 'details' => ['reason' => $reason]]);

        $ar = $request->header('X-Locale', 'en') === 'ar';
        $msg = $reason === 'not_allowed'
            ? ($ar ? 'البريد متاح من عناوين IP معتمدة فقط، وعنوانك غير مسموح.' : 'Mail is only available from approved IP addresses, and yours is not allowed.')
            : ($ar ? 'تم حظر الوصول للبريد من عنوان الـ IP ده.' : 'Mail access from this IP address is blocked.');

        return response()->json([
            'success' => false, 'message' => $msg, 'data' => ['mail_ip_blocked' => true, 'reason' => $reason],
            'errors' => ['code' => 'mail_ip_blocked'], 'meta' => (object) [],
        ], 403)->header('Cache-Control', 'no-store');
    }
}
