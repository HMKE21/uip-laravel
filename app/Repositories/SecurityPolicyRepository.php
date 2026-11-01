<?php

namespace App\Repositories;

use App\Models\SecurityPolicy;
use Illuminate\Support\Facades\Cache;

/**
 * منقولة من app/Repositories/SecurityPolicyRepository.php القديمة بالكامل
 * — بند 25 batch 3 (Vulnerabilities + Policies). Data access لـ
 * `security_policies` (migration 041)، بيستخدمها كل *PolicyService
 * (Password/AccountLockout/Session/Mfa/ApiRateLimit/FileUpload/
 * IpRestriction/CountryRestriction/DeviceRestriction) بنفس النمط:
 * findByKey() لقراءة الـ JSON المخزّن، updateValue() للحفظ + تسجيل
 * updated_by.
 */
class SecurityPolicyRepository
{
    /** @return SecurityPolicy[] كل الصفوف، مرتبة زي القديمة (category ثم policy_key) */
    public function all(): array
    {
        return SecurityPolicy::orderBy('category')->orderBy('policy_key')->get()->all();
    }

    /** كاش قصير للسياسات: كانت بتتقرا من الداتابيز عدة مرات في كل ريكويست (rate-limit / device / ip / country / mfa). */
    private const CACHE_TTL = 20;

    /** @var array<string,?SecurityPolicy> memo جوّه نفس الريكويست */
    private static array $memo = [];

    public function findByKey(string $key): ?SecurityPolicy
    {
        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }
        try {
            $attrs = Cache::remember('secpol:' . $key, self::CACHE_TTL, function () use ($key) {
                $row = SecurityPolicy::where('policy_key', $key)->first();
                return $row ? $row->getAttributes() : false; // false = "مفيش صف" (بنخزّنها برضو عشان ميتسألش تاني)
            });
        } catch (\Throwable $e) {
            return self::$memo[$key] = SecurityPolicy::where('policy_key', $key)->first();
        }

        if (!$attrs) {
            return self::$memo[$key] = null;
        }

        return self::$memo[$key] = (new SecurityPolicy())->newFromBuilder($attrs);
    }

    /** يمسح الكاش بعد أي تعديل عشان تغيير الأدمن يسري فورًا. */
    public static function forget(string $key): void
    {
        unset(self::$memo[$key]);
        try {
            Cache::forget('secpol:' . $key);
        } catch (\Throwable $e) {
        }
    }

    /**
     * Structured (JSON) policies managed by the dedicated *PolicyService
     * classes. Their rows are not part of any seeder, so the first save
     * must create the row instead of failing ("Could not save that policy").
     */
    private const STRUCTURED = [
        'login.lockout_policy'       => ['access_control', 'سياسة قفل الحساب',        'Failed login / lockout policy'],
        'password.policy'            => ['authentication', 'سياسة كلمة المرور',       'Password policy'],
        'upload.policy'              => ['data_protection', 'سياسة رفع الملفات',      'File upload policy'],
        'session.policy'             => ['session',        'سياسة الجلسات',           'Session policy'],
        'mfa.policy'                 => ['authentication', 'سياسة المصادقة الثنائية', 'MFA policy'],
        'rate_limit.policy'          => ['access_control', 'سياسة حد الطلبات',        'API rate limit policy'],
        'ip_restriction.policy'      => ['access_control', 'سياسة تقييد IP',          'IP restriction policy'],
        'country_restriction.policy' => ['access_control', 'سياسة تقييد الدول',       'Country restriction policy'],
        'device_restriction.policy'  => ['access_control', 'سياسة تقييد الأجهزة',     'Device restriction policy'],
    ];

    public function updateValue(string $key, string $value, $updatedBy): bool
    {
        // قراءة طازجة من الداتابيز (مش من الكاش) قبل التعديل.
        self::forget($key);
        $policy = $this->findByKey($key);

        if (!$policy) {
            // Unknown generic keys are still rejected; known structured keys are created on first save.
            if (!isset(self::STRUCTURED[$key])) {
                return false;
            }
            [$category, $nameAr, $nameEn] = self::STRUCTURED[$key];
            $policy = new SecurityPolicy([
                'policy_key' => $key,
                'category'   => $category,
                'name_ar'    => $nameAr,
                'name_en'    => $nameEn,
                'is_active'  => true,
            ]);
        }

        $policy->fill(['value' => $value, 'updated_by' => $updatedBy]);
        $ok = $policy->save();
        self::forget($key);

        return $ok;
    }
}
