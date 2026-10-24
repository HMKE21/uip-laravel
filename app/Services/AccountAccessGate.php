<?php

namespace App\Services;

use App\Support\PlatformPolicy;
use Illuminate\Support\Facades\DB;

/**
 * بوابة "الحساب لازم يكون مرتبط بجامعة".
 *
 * حساب الطالب اللي students.university_id بتاعه فاضي مايقدرش يستخدم المنصة غير:
 *   - البروفايل + طلب الانضمام + الإشعارات + تسجيل الخروج (ALLOW_PREFIXES)،
 * إلا لما:
 *   - الجامعة أو الكلية توافق على طلب الانضمام (أو الأدمن يربطه) => students.university_id يتحط، أو
 *   - أدمن/جامعة/كلية يديله منحة (account_access_grants) فيها مميزات محددة أو 'all'، مجانية أو مدفوعة، بمدة أو من غير.
 * باقي الأدوار مرتبطة بطبيعتها => مش متأثرة. الأدمن يقفل البوابة كلها من Platform Policy (require_university_link).
 */
class AccountAccessGate
{
    /** مفاتيح المميزات اللي ينفع تتمنح. 'all' = كل حاجة. */
    public const FEATURES = [
        'messaging' => ['en' => 'Messaging & calls', 'ar' => 'الرسائل والمكالمات'],
        'meetings'  => ['en' => 'Meetings',          'ar' => 'الاجتماعات'],
        'projects'  => ['en' => 'Projects',          'ar' => 'المشاريع'],
        'my_pc'     => ['en' => 'My PC (drive & desktop)', 'ar' => 'جهازي (الملفات والسطح)'],
        'learning'  => ['en' => 'Courses & exams',   'ar' => 'المواد والامتحانات'],
        'social'    => ['en' => 'Feed & stories',    'ar' => 'الفيد والستوريز'],
        'ai'        => ['en' => 'AI assistant',      'ar' => 'المساعد الذكي'],
        'all'       => ['en' => 'Everything',        'ar' => 'كل المميزات'],
    ];

    /** أول segment بعد api/v1 => الميزة. */
    private const PATH_FEATURE = [
        'messaging' => 'messaging', 'group-chat' => 'messaging', 'group-hub' => 'messaging', 'groups' => 'messaging',
        'contacts' => 'messaging', 'people' => 'messaging', 'chat-reports' => 'messaging',
        'meetings' => 'meetings',
        'projects' => 'projects', 'project-deadlines' => 'projects', 'portfolios' => 'projects', 'patents' => 'projects',
        'drive' => 'my_pc', 'drive-share' => 'my_pc', 'files' => 'my_pc', 'desktop' => 'my_pc',
        'courses' => 'learning', 'assignments' => 'learning', 'exams' => 'learning', 'exam-system' => 'learning',
        'grading' => 'learning', 'attendance' => 'learning', 'academic-calendar' => 'learning', 'graduation' => 'learning', 'summer' => 'learning',
        'feed' => 'social', 'stories' => 'social', 'comments' => 'social', 'shares' => 'social',
        'ai-assistant' => 'ai', 'ai-code' => 'ai', 'ai-insights' => 'ai',
    ];

    /** مسموحة دايمًا للحساب المقيّد: عشان يقدّم الطلب ويشوف حالته ويخرج. */
    private const ALLOW_PREFIXES = [
        'auth', 'session', 'sessions', 'students', 'notifications', 'push', 'account', 'settings',
        'universities', 'app-config', 'platform', 'public', 'v1',
    ];

    /** @var array<string,array> */
    private array $memo = [];

    /** @return string|null  null = مسموح للمقيّد؛ غير كده اسم الميزة المطلوبة ('all' لو مش متصنّفة) */
    public static function featureForPath(string $path): ?string
    {
        $path = ltrim($path, '/');
        if (str_starts_with($path, 'api/v1/')) {
            $path = substr($path, 7);
        }
        $first = explode('/', $path)[0] ?? '';
        if ($first === 'students' && !preg_match('#^students/me(/|$)#', $path)) {
            return 'all'; // قايمة/تفاصيل طلاب غيره مش للمقيّد
        }
        if (in_array($first, self::ALLOW_PREFIXES, true)) {
            return null;
        }

        return self::PATH_FEATURE[$first] ?? 'all';
    }

    public static function validFeatures(array $in): array
    {
        $in = array_values(array_unique(array_map('strval', $in)));
        $bad = array_diff($in, array_keys(self::FEATURES));
        if ($bad) {
            throw new \InvalidArgumentException('Unknown feature: ' . implode(', ', $bad));
        }

        return in_array('all', $in, true) ? ['all'] : $in;
    }

    /** المنح الشغالة دلوقتي (مش ملغية، مدفوعة/مجانية، وجوّه المدة). */
    public function activeGrants(int $userId): array
    {
        return DB::table('account_access_grants')
            ->where('user_id', $userId)->whereNull('revoked_at')
            ->whereIn('payment_status', ['free', 'paid'])
            ->whereNotNull('starts_at')->where('starts_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('id')->get()->map(fn ($g) => [
                'id' => (int) $g->id, 'features' => json_decode($g->features, true) ?: [],
                'expires_at' => $g->expires_at, 'granted_by_role' => $g->granted_by_role, 'plan_id' => $g->plan_id,
            ])->all();
    }

    public function status(int $userId, string $role): array
    {
        $key = $userId . '|' . $role;
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $out = [
            'enabled' => PlatformPolicy::flag('require_university_link'),
            'restricted' => false, 'reason' => null,
            'allowed_features' => [], 'grants' => [], 'request' => null,
        ];

        if ($out['enabled'] && $role === 'student') {
            $s = DB::table('students')->where('user_id', $userId)->first(['id', 'university_id']);
            if (!$s || !$s->university_id) {
                $grants = $this->activeGrants($userId);
                $features = [];
                foreach ($grants as $g) {
                    $features = array_merge($features, $g['features']);
                }
                $features = array_values(array_unique($features));
                $out['grants'] = $grants;
                $out['allowed_features'] = $features;
                $out['restricted'] = !in_array('all', $features, true);
                $out['reason'] = $out['restricted'] ? 'no_university' : null;
                if ($s) {
                    $r = DB::table('student_university_requests')->where('student_id', $s->id)->orderByDesc('id')->first(['status', 'created_at', 'university_id']);
                    $out['request'] = $r ? ['status' => $r->status, 'created_at' => $r->created_at, 'university_id' => $r->university_id] : null;
                }
            }
        }

        return $this->memo[$key] = $out;
    }

    /** هل الحساب ده مسموح له بالمسار ده؟ يرجّع [allowed, status, feature]. */
    public function check(int $userId, string $role, string $path): array
    {
        $st = $this->status($userId, $role);
        if (!$st['restricted'] && !$st['allowed_features']) {
            return [true, $st, null];
        }
        $feature = self::featureForPath($path);
        if ($feature === null) {
            return [true, $st, null];
        }

        return [in_array('all', $st['allowed_features'], true) || in_array($feature, $st['allowed_features'], true), $st, $feature];
    }
}
