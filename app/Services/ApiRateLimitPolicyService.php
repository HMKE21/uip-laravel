<?php

namespace App\Services;

use App\Repositories\SecurityPolicyRepository;

/**
 * منقولة من app/Services/ApiRateLimitPolicyService.php القديمة بالكامل —
 * بند 25 batch 3. بتخلي UipRateLimitMiddleware (بند 2) قابل للتعديل من
 * شاشة Security Policies بدل config('security.rate_limit_per_min')
 * الثابت — القيد الموثّق في docblock الميدلوير بند 2 ("لحد ما بند 25
 * يتعمل") اتقفل هنا. الـ config يفضل fallback لو الصف مفقود أو الداتابيز
 * مش متاحة لحظيًا، عشان الـ API میفشلش open/غير محمي.
 */
class ApiRateLimitPolicyService
{
    private const POLICY_KEY = 'rate_limit.policy';

    public function __construct(
        private SecurityPolicyRepository $policies,
        private AuditLogService $auditLog
    ) {
    }

    /** @return array{enabled:bool,requests_per_minute:int} */
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
        $defaults = [
            'enabled'             => true,
            'requests_per_minute' => (int) config('security.rate_limit_per_min', 60),
        ];
        $policy = array_merge($defaults, $stored);
        return [
            'enabled'             => (bool) $policy['enabled'],
            'requests_per_minute' => (int) $policy['requests_per_minute'],
        ];
    }

    /** @throws \InvalidArgumentException on bad input */
    public function updatePolicy(array $input, $adminUserId, ?string $ip = null): array
    {
        $before = $this->getPolicy();

        $limit = (int) ($input['requests_per_minute'] ?? 0);
        if ($limit < 1 || $limit > 10000) {
            throw new \InvalidArgumentException('Requests per minute must be between 1 and 10000.');
        }

        $policy = [
            'enabled'             => !empty($input['enabled']),
            'requests_per_minute' => $limit,
        ];

        $saved = $this->policies->updateValue(self::POLICY_KEY, json_encode($policy, JSON_UNESCAPED_UNICODE), $adminUserId);
        if (!$saved) {
            throw new \RuntimeException('Could not save the API rate limit policy.');
        }

        $this->auditLog->record($adminUserId, 'security_policy.rate_limit_updated', 'SecurityPolicy', null, $before, $policy, $ip);

        return $policy;
    }
}
