<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\SecurityPolicyRepository;
use App\Services\AccountLockoutService;
use App\Services\ApiRateLimitPolicyService;
use App\Services\CountryRestrictionPolicyService;
use App\Services\DeviceRestrictionPolicyService;
use App\Services\FileUploadPolicyService;
use App\Services\IpRestrictionPolicyService;
use App\Services\MfaPolicyService;
use App\Services\PasswordPolicyService;
use App\Services\SessionPolicyService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/SecurityPoliciesApiController.php
 * القديمة — سطح /api/v1/security/policies/* بالكامل، بند 25 batch 3
 * (Vulnerabilities + Policies). سياسة أمان واحدة (باسورد/lockout/رفع
 * ملفات/جلسة/MFA/rate-limit/IP/دولة/جهاز) + القائمة العامة key/value +
 * فتح قفل حساب يدوي — بتعيد استخدام SecurityPolicyRepository وكل
 * *PolicyService بالظبط زي القديمة.
 *
 * RBAC: كل موظفي الأمان (security_admin/security_officer/admin) يقدروا
 * يشوفوا (index)، بس security_admin/admin بس يقدروا يعدّلوا — نفس
 * canEdit() القديمة بالظبط.
 *
 * فرق شكلي فقط عن القديمة: Session::userId()/userRole() -> $request
 * ->attributes->get('uip_user_id')/'uip_role'.
 */
class SecurityPoliciesApiController extends Controller
{
    public function __construct(
        private SecurityPolicyRepository $policies,
        private AccountLockoutService $lockout,
        private PasswordPolicyService $passwordPolicy,
        private FileUploadPolicyService $uploadPolicy,
        private SessionPolicyService $sessionPolicy,
        private MfaPolicyService $mfaPolicy,
        private ApiRateLimitPolicyService $rateLimitPolicy,
        private IpRestrictionPolicyService $ipRestrictionPolicy,
        private CountryRestrictionPolicyService $countryRestrictionPolicy,
        private DeviceRestrictionPolicyService $deviceRestrictionPolicy
    ) {
    }

    /** GET /api/v1/security/policies — كل الپانلات المهيكلة + القائمة العامة key/value، يقدر يشوفها أي موظف أمان. */
    public function index(Request $request)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can view policies.', null, 403);
        }

        $this->ensureAdvancedRows();

        $excludedKeys = [
            'login.lockout_policy', 'password.policy', 'upload.policy', 'session.policy',
            'mfa.policy', 'rate_limit.policy', 'ip_restriction.policy',
            'country_restriction.policy', 'device_restriction.policy',
        ];
        $generic = array_values(array_map(
            function ($p) {
                $row = $p->toArray();
                // الإعدادات السريعة (Advanced) بتعرض القيمة الحيّة من السياسة المنظّمة اللي بتتطبّق فعلًا.
                $live = $this->aliasValue((string) $p->policy_key);
                if ($live !== null) {
                    $row['value'] = $live;
                }
                return $row;
            },
            array_filter(
                $this->policies->all(),
                fn ($p) => !in_array($p->policy_key, $excludedKeys, true)
            )
        ));

        return $this->apiSuccess([
            'generic_policies'           => $generic,
            'can_edit'                   => $this->canEdit($request),
            'lockout_policy'             => $this->lockout->getPolicy(),
            'locked_accounts'            => $this->lockout->lockedAccounts(),
            'password_policy'            => $this->passwordPolicy->getPolicy(),
            'upload_policy'              => $this->uploadPolicy->getPolicy(),
            'session_policy'             => $this->sessionPolicy->getPolicy(),
            'mfa_policy'                 => $this->mfaPolicy->getPolicy(),
            'mfa_enforceable_roles'      => MfaPolicyService::ENFORCEABLE_ROLES,
            'rate_limit_policy'          => $this->rateLimitPolicy->getPolicy(),
            'ip_restriction_policy'      => $this->ipRestrictionPolicy->getPolicy(),
            'country_restriction_policy' => $this->countryRestrictionPolicy->getPolicy(),
            'device_restriction_policy'  => $this->deviceRestrictionPolicy->getPolicy(),
        ], 'Policies retrieved successfully.');
    }

    /** PATCH /api/v1/security/policies — تحديث عام لسياسة key/value واحدة. */
    public function update(Request $request)
    {
        if (!$this->canEdit($request)) {
            return $this->apiError('Only a Security Administrator can change policies.', null, 403);
        }

        $key = (string) $request->input('policy_key', '');
        $value = (string) $request->input('value', '');

        if (in_array($key, self::ALIAS_KEYS, true)) {
            return $this->applyAlias($request, $key, $value);
        }

        if ($key === '' || !$this->policies->updateValue($key, $value, $request->attributes->get('uip_user_id'))) {
            return $this->apiError('Could not update that policy.', null, 422);
        }

        return $this->apiSuccess(null, 'Policy updated.');
    }

    /** PATCH /api/v1/security/policies/lockout */
    public function updateLockoutPolicy(Request $request)
    {
        return $this->applyStructuredPolicy($request, $this->lockout, 'Failed login / lockout policy updated.');
    }

    /** PATCH /api/v1/security/policies/password */
    public function updatePasswordPolicy(Request $request)
    {
        return $this->applyStructuredPolicy($request, $this->passwordPolicy, 'Password complexity / expiration policy updated.');
    }

    /** PATCH /api/v1/security/policies/upload */
    public function updateUploadPolicy(Request $request)
    {
        return $this->applyStructuredPolicy($request, $this->uploadPolicy, 'File upload restrictions policy updated.');
    }

    /** PATCH /api/v1/security/policies/session */
    public function updateSessionPolicy(Request $request)
    {
        return $this->applyStructuredPolicy($request, $this->sessionPolicy, 'Session timeout / concurrent session policy updated.');
    }

    /** PATCH /api/v1/security/policies/mfa */
    public function updateMfaPolicy(Request $request)
    {
        return $this->applyStructuredPolicy($request, $this->mfaPolicy, 'MFA requirements policy updated.');
    }

    /** PATCH /api/v1/security/policies/rate-limit */
    public function updateRateLimitPolicy(Request $request)
    {
        return $this->applyStructuredPolicy($request, $this->rateLimitPolicy, 'API rate limit policy updated.');
    }

    /** PATCH /api/v1/security/policies/ip-restriction */
    public function updateIpRestrictionPolicy(Request $request)
    {
        return $this->applyStructuredPolicy($request, $this->ipRestrictionPolicy, 'IP restriction policy updated.');
    }

    /** PATCH /api/v1/security/policies/country-restriction */
    public function updateCountryRestrictionPolicy(Request $request)
    {
        return $this->applyStructuredPolicy($request, $this->countryRestrictionPolicy, 'Country restriction policy updated.');
    }

    /** PATCH /api/v1/security/policies/device-restriction */
    public function updateDeviceRestrictionPolicy(Request $request)
    {
        return $this->applyStructuredPolicy($request, $this->deviceRestrictionPolicy, 'Device restriction policy updated.');
    }

    /** POST /api/v1/security/policies/unlock/{id} — فتح قفل يدوي من مسؤول أمان. */
    public function unlockAccount(Request $request, $id)
    {
        if (!$this->canEdit($request)) {
            return $this->apiError('Only a Security Administrator can unlock accounts.', null, 403);
        }

        if (!$id) {
            return $this->apiError('A user id is required.', null, 422);
        }

        $this->lockout->manualUnlock($id, $request->attributes->get('uip_user_id'), $request->ip());

        return $this->apiSuccess(null, 'Account unlocked.');
    }

    // -- helpers --------------------------------------------------------------

    /**
     * مفاتيح Advanced القديمة: بقت "اختصارات" لنفس السياسات المنظّمة (باسورد/جلسة/قفل) اللي بتتطبّق فعلًا،
     * فتعديلها من هنا بيغيّر السياسة الحقيقية (قبل كده كانت بتتخزّن من غير ما أي كود يقراها).
     */
    private const ALIAS_KEYS = [
        'password.min_length', 'password.require_special', 'session.timeout_minutes',
        'login.max_failed_attempts', 'login.lockout_minutes',
    ];

    /** لو أي صف من إعدادات Advanced الافتراضية اتحذف/مكانش متعمل، بنرجّعه (القيمة الحيّة بتتعرض من السياسة الفعلية). */
    private function ensureAdvancedRows(): void
    {
        $defaults = [
            ['password.min_length',        'authentication', 'الحد الأدنى لطول كلمة المرور',        'Minimum password length',        '8'],
            ['password.require_special',   'authentication', 'إلزام رمز خاص في كلمة المرور',        'Require special character',      '1'],
            ['session.timeout_minutes',    'session',        'مهلة انتهاء الجلسة (دقائق)',          'Session timeout (minutes)',      '60'],
            ['login.max_failed_attempts',  'access_control', 'الحد الأقصى لمحاولات الدخول الفاشلة', 'Max failed login attempts',      '5'],
            ['login.lockout_minutes',      'access_control', 'مدة الحظر بعد الفشل (دقائق)',        'Lockout duration (minutes)',     '15'],
            ['monitoring.alert_on_new_ip', 'monitoring',     'تنبيه عند دخول من مكان جديد',        'Alert on login from new IP',     '1'],
        ];
        try {
            foreach ($defaults as [$key, $cat, $ar, $en, $val]) {
                if (!$this->policies->findByKey($key)) {
                    \App\Models\SecurityPolicy::create([
                        'policy_key' => $key, 'category' => $cat, 'name_ar' => $ar, 'name_en' => $en, 'value' => $val, 'is_active' => true,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('ensureAdvancedRows failed: ' . $e->getMessage());
        }
    }

    private function aliasValue(string $key): ?string
    {
        try {
            return match ($key) {
                'password.min_length'       => (string) $this->passwordPolicy->getPolicy()['min_length'],
                'password.require_special'  => !empty($this->passwordPolicy->getPolicy()['require_special']) ? '1' : '0',
                'session.timeout_minutes'   => (string) $this->sessionPolicy->getPolicy()['timeout_minutes'],
                'login.max_failed_attempts' => (string) $this->lockout->getPolicy()['max_attempts'],
                'login.lockout_minutes'     => (string) $this->lockoutMinutes($this->lockout->getPolicy()),
                default                     => null,
            };
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function lockoutMinutes(array $p): int
    {
        $v = (int) ($p['duration_value'] ?? 15);
        return match ($p['duration_unit'] ?? 'minutes') {
            'hours' => $v * 60,
            'days'  => $v * 1440,
            default => $v,
        };
    }

    private function applyAlias(Request $request, string $key, string $value)
    {
        $uid = $request->attributes->get('uip_user_id');
        $ip = $request->ip();

        try {
            switch ($key) {
                case 'password.min_length':
                    $this->passwordPolicy->updatePolicy(array_merge($this->passwordPolicy->getPolicy(), ['min_length' => (int) $value]), $uid, $ip);
                    break;
                case 'password.require_special':
                    $on = in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
                    $this->passwordPolicy->updatePolicy(array_merge($this->passwordPolicy->getPolicy(), ['require_special' => $on]), $uid, $ip);
                    break;
                case 'session.timeout_minutes':
                    $this->sessionPolicy->updatePolicy(array_merge($this->sessionPolicy->getPolicy(), ['timeout_minutes' => (int) $value]), $uid, $ip);
                    break;
                case 'login.max_failed_attempts':
                    $this->lockout->updatePolicy(array_merge($this->lockout->getPolicy(), ['max_attempts' => (int) $value]), $uid, $ip);
                    break;
                case 'login.lockout_minutes':
                    $this->lockout->updatePolicy(array_merge($this->lockout->getPolicy(), ['duration_value' => (int) $value, 'duration_unit' => 'minutes']), $uid, $ip);
                    break;
            }
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Security policy alias save failed', ['key' => $key, 'error' => $e->getMessage()]);
            return $this->apiError('Could not save that policy.', null, 422);
        }

        return $this->apiSuccess(null, 'Policy updated.');
    }

    /** Shared apply/error handling لكل نداء *PolicyService::updatePolicy(). */
    private function applyStructuredPolicy(Request $request, object $service, string $successMessage)
    {
        if (!$this->canEdit($request)) {
            return $this->apiError('Only a Security Administrator can change policies.', null, 403);
        }

        try {
            // الـ 4th arg (User-Agent) بيستخدمه Device policy بس لحماية الأدمن من قفل نفسه؛ باقي الـ services بتتجاهله.
            $service->updatePolicy($request->all(), $request->attributes->get('uip_user_id'), $request->ip(), $request->userAgent());
            return $this->apiSuccess(null, $successMessage);
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Security policy save failed', [
                'policy' => get_class($service),
                'error'  => $e->getMessage(),
            ]);
            return $this->apiError('Could not save that policy.', null, 422);
        }
    }

    private function isSecurityStaff(Request $request): bool
    {
        return in_array($request->attributes->get('uip_role'), ['security_admin', 'security_officer', 'admin'], true);
    }

    private function canEdit(Request $request): bool
    {
        return in_array($request->attributes->get('uip_role'), ['security_admin', 'admin'], true);
    }
}
