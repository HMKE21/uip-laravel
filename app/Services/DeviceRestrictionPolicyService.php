<?php

namespace App\Services;

use App\Repositories\SecurityPolicyRepository;

/**
 * منقولة من app/Services/DeviceRestrictionPolicyService.php القديمة
 * بالكامل — بند 25 batch 3. classify()/isAllowed() منقولين كاملين.
 *
 * الإنفاذ (enforcement): isAllowed() بتتنادى من
 *   - LoginController::submit()          (منع الدخول من نوع جهاز محظور)
 *   - RefreshTokenController::submit()   (منع تجديد جلسة على جهاز محظور)
 *   - UipAuthMiddleware                  (كل ريكوست محمي — يقطع الجلسات الشغالة فورًا)
 *   - UipDeviceRestrictionMiddleware     (كل ريكوست /api/* بما فيه register والـ endpoints العامة)
 *   - RegisterController::submit()       (طبقة تانية على إنشاء الحساب)
 *   - DeviceStatusController             (GET /v1/device-status — الفرونت يعرض صفحة الحظر)
 * التصنيف مبني على User-Agent، يعني بيمنع الاستخدام العادي من الجهاز
 * بس مش حماية قوية ضد حد بيزوّر الـ User-Agent عمدًا.
 */
/**
 * أوضاع السياسة (mode):
 *   disabled  — مفيش حظر.
 *   allowlist — (عام لكل الحسابات) بس الأنواع في allowed_device_types مسموحة.
 *   denylist  — (عام لكل الحسابات) الأنواع في blocked_device_types ممنوعة.
 *   by_role   — حظر حسب نوع الحساب: role_rules[<role>] = أنواع الأجهزة الممنوعة لدور ده بس
 *               (مثلًا الطالب ممنوع موبايل والأدمن لأ). الأدوار اللي مش في القاعدة مفيش عليها حظر.
 * ملحوظة: الدور مش معروف قبل تسجيل الدخول، فالأوضاع العامة بتتفحص بدون دور (isAllowed($ua))،
 * وby_role بتتفحص لما الدور يتعرف: اللوجين بعد التحقق من الحساب، التسجيل من role المختار،
 * تجديد التوكن، وكل ريكوست محمي (من claims التوكن).
 */
class DeviceRestrictionPolicyService
{
    private const POLICY_KEY = 'device_restriction.policy';

    public const DEVICE_TYPES = ['desktop', 'mobile', 'tablet', 'unknown'];

    private const DEFAULTS = [
        'mode'                 => 'disabled',
        'allowed_device_types' => [],
        'blocked_device_types' => [],
        'role_rules'           => [],
    ];

    private const MODES = ['disabled', 'allowlist', 'denylist', 'by_role'];

    public function __construct(
        private SecurityPolicyRepository $policies,
        private AuditLogService $auditLog
    ) {
    }

    /** @return string[] الأدوار اللي ينفع تتحط عليها قاعدة (من config/roles.php). */
    public function knownRoles(): array
    {
        return array_values((array) config('roles.available', []));
    }

    /** @return array<string,string[]> role => blocked device types (متنضّف: أدوار معروفة وأنواع صالحة ومش فاضية بس). */
    private function sanitizeRoleRules($rules): array
    {
        $out = [];
        $roles = $this->knownRoles();
        foreach ((array) $rules as $role => $types) {
            if (!in_array($role, $roles, true)) {
                continue;
            }
            $types = array_values(array_intersect((array) (is_array($types) && array_key_exists('blocked_device_types', $types) ? $types['blocked_device_types'] : $types), self::DEVICE_TYPES));
            if ($types) {
                $out[$role] = $types;
            }
        }
        return $out;
    }

    /** @return array{mode:string,allowed_device_types:string[],blocked_device_types:string[],role_rules:array<string,string[]>} */
    public function getPolicy(): array
    {
        $row = $this->policies->findByKey(self::POLICY_KEY);
        $stored = [];
        if ($row) {
            $decoded = json_decode((string) $row->value, true);
            if (is_array($decoded)) {
                $stored = $decoded;
            }
        }
        $policy = array_merge(self::DEFAULTS, $stored);
        $policy['allowed_device_types'] = array_values(array_intersect((array) $policy['allowed_device_types'], self::DEVICE_TYPES));
        $policy['blocked_device_types'] = array_values(array_intersect((array) $policy['blocked_device_types'], self::DEVICE_TYPES));
        $policy['role_rules'] = $this->sanitizeRoleRules($policy['role_rules'] ?? []);
        if (!in_array($policy['mode'], self::MODES, true)) {
            $policy['mode'] = 'disabled';
        }
        return $policy;
    }

    /**
     * @param string|null $userAgent الـ User-Agent بتاع الأدمن اللي بيحفظ السياسة — لو السياسة
     *                               الجديدة هتحظر جهازه هو نفسه بنرفض الحفظ (حماية من قفل الأدمن برا).
     * @throws \InvalidArgumentException on bad input
     */
    public function updatePolicy(array $input, $adminUserId, ?string $ip = null, ?string $userAgent = null): array
    {
        $before = $this->getPolicy();

        $mode = (string) ($input['mode'] ?? 'disabled');
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('Invalid device restriction mode.');
        }

        $allowed = array_values(array_intersect((array) ($input['allowed_device_types'] ?? []), self::DEVICE_TYPES));
        $blocked = array_values(array_intersect((array) ($input['blocked_device_types'] ?? []), self::DEVICE_TYPES));

        if ($mode === 'allowlist' && !$allowed) {
            throw new \InvalidArgumentException('Select at least one device type before enabling allowlist mode.');
        }
        if ($mode === 'denylist' && !$blocked) {
            throw new \InvalidArgumentException('Select at least one device type before enabling denylist mode.');
        }

        $roleRules = $this->sanitizeRoleRules($input['role_rules'] ?? []);
        if ($mode === 'by_role' && !$roleRules) {
            throw new \InvalidArgumentException('Block at least one device type for at least one account type before enabling per-role mode.');
        }

        $policy = ['mode' => $mode, 'allowed_device_types' => $allowed, 'blocked_device_types' => $blocked, 'role_rules' => $roleRules];

        // حماية الأدمن من قفل نفسه: بدوره الحالي (للوضع by_role) ونوع جهازه الحالي.
        if ($userAgent !== null) {
            $adminRole = $adminUserId ? app(RoleService::class)->primaryRoleFor((int) $adminUserId) : null;
            if (!$this->allows($policy, $userAgent, $adminRole)) {
                throw new \InvalidArgumentException(
                    'This policy would block the device you are using right now (' . $this->classify($userAgent) . ') for your own account type, and you would lose access. Save it from a device type that stays allowed.'
                );
            }
        }

        $saved = $this->policies->updateValue(self::POLICY_KEY, json_encode($policy, JSON_UNESCAPED_UNICODE), $adminUserId);
        if (!$saved) {
            throw new \RuntimeException('Could not save the device restriction policy.');
        }

        $this->auditLog->record($adminUserId, 'security_policy.device_restriction_updated', 'SecurityPolicy', null, $before, $policy, $ip);

        return $policy;
    }

    public function classify(?string $userAgent): string
    {
        if (!$userAgent) {
            return 'unknown';
        }

        if (preg_match('/iPad|Android(?!.*Mobile)|Tablet|Kindle|PlayBook/i', $userAgent)) {
            return 'tablet';
        }
        if (preg_match('/Mobile|iPhone|iPod|Android|BlackBerry|IEMobile|Opera Mini/i', $userAgent)) {
            return 'mobile';
        }
        if (preg_match('/Windows|Macintosh|Mac OS X|X11|Linux/i', $userAgent)) {
            return 'desktop';
        }

        return 'unknown';
    }

    /**
     * True = هذا النوع من الأجهزة مسموح، حسب السياسة الحالية.
     * $role = دور الحساب لو معروف (للوضع by_role)؛ من غيره بتتفحص الأوضاع العامة بس.
     */
    public function isAllowed(?string $userAgent, ?string $role = null): bool
    {
        return $this->allows($this->getPolicy(), $userAgent, $role);
    }

    /** نفس منطق isAllowed() على سياسة معيّنة (عشان نقدر نفحص سياسة لسه ماتحفظتش). */
    private function allows(array $policy, ?string $userAgent, ?string $role = null): bool
    {
        if ($policy['mode'] === 'disabled') {
            return true;
        }

        $type = $this->classify($userAgent);

        if ($policy['mode'] === 'by_role') {
            if ($role === null || $role === '') {
                return true; // الدور لسه مجهول — هيتفحص أول ما يتعرف
            }
            return !in_array($type, $policy['role_rules'][$role] ?? [], true);
        }

        if ($policy['mode'] === 'allowlist') {
            return in_array($type, $policy['allowed_device_types'], true);
        }

        return !in_array($type, $policy['blocked_device_types'], true);
    }

    /** رسالة الرفض للمستخدم (عربي/إنجليزي حسب X-Locale). */
    public function blockedMessage(string $locale = 'en', ?string $role = null): string
    {
        if ($role) {
            $labels = [
                'student' => ['الطلاب', 'Students'], 'university' => ['حسابات الجامعات', 'University accounts'],
                'faculty' => ['حسابات الكليات', 'Faculty accounts'], 'academic_staff' => ['أعضاء هيئة التدريس', 'Academic staff'],
                'supervisor' => ['المشرفين', 'Supervisors'], 'admin' => ['مسؤولي النظام', 'Administrators'],
                'security_admin' => ['مسؤولي الأمان', 'Security administrators'], 'security_officer' => ['ضباط الأمان', 'Security officers'],
                'data_analyst' => ['محللي البيانات', 'Data analysts'],
            ];
            $who = $labels[$role][$locale === 'ar' ? 0 : 1] ?? $role;
            return $locale === 'ar'
                ? "استخدام المنصة من هذا النوع من الأجهزة غير مسموح لـ{$who} حاليًا. جرّب من جهاز آخر أو تواصل مع الدعم."
                : "{$who} aren't allowed to use the platform from this type of device. Try another device or contact support.";
        }

        return $locale === 'ar'
            ? 'استخدام المنصة من هذا النوع من الأجهزة غير مسموح حاليًا. جرّب من جهاز آخر أو تواصل مع الدعم.'
            : 'Using the platform from this type of device is not allowed. Try another device or contact support.';
    }
}
