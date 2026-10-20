<?php

namespace App\Services;

use App\Repositories\SecurityPolicyRepository;

/**
 * منقولة من app/Services/CountryRestrictionPolicyService.php القديمة
 * بالكامل — بند 25 batch 3. بتستخدم GeoIpService (جديدة في نفس الـ
 * batch) لتحويل IP لكود دولة. evaluate() منقولة كاملة، بس نفس فجوة
 * enforcement الموثّقة في IpRestrictionPolicyService/MfaPolicyService —
 * مفيش نقطة في AuthService الحالي بتنادي عليها لسه.
 */
class CountryRestrictionPolicyService
{
    private const POLICY_KEY = 'country_restriction.policy';

    private const DEFAULTS = [
        'mode'              => 'disabled',
        'allowed_countries' => [],
        'blocked_countries' => [],
        'fail_open'         => true,
    ];

    public function __construct(
        private SecurityPolicyRepository $policies,
        private GeoIpService $geoIp,
        private AuditLogService $auditLog
    ) {
    }

    /** @return array{mode:string,allowed_countries:string[],blocked_countries:string[],fail_open:bool} */
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
        $policy['allowed_countries'] = array_values(array_filter((array) $policy['allowed_countries']));
        $policy['blocked_countries'] = array_values(array_filter((array) $policy['blocked_countries']));
        $policy['fail_open'] = (bool) $policy['fail_open'];
        return $policy;
    }

    /** @throws \InvalidArgumentException on bad input */
    public function updatePolicy(array $input, $adminUserId, ?string $ip = null): array
    {
        $before = $this->getPolicy();

        $mode = (string) ($input['mode'] ?? 'disabled');
        if (!in_array($mode, ['disabled', 'allowlist', 'denylist'], true)) {
            throw new \InvalidArgumentException('Invalid country restriction mode.');
        }

        $allowed = $this->parseCodes((string) ($input['allowed_countries'] ?? ''));
        $blocked = $this->parseCodes((string) ($input['blocked_countries'] ?? ''));

        if ($mode === 'allowlist' && !$allowed) {
            throw new \InvalidArgumentException('Add at least one country code before enabling allowlist mode.');
        }
        if ($mode === 'denylist' && !$blocked) {
            throw new \InvalidArgumentException('Add at least one country code before enabling denylist mode.');
        }

        $policy = [
            'mode'              => $mode,
            'allowed_countries' => $allowed,
            'blocked_countries' => $blocked,
            'fail_open'         => !empty($input['fail_open']),
        ];

        // حماية الأدمن من قفل نفسه: بلده الحالية لازم تفضل مسموحة بالسياسة الجديدة.
        if ($ip && !$this->evaluateWith($policy, $ip)['allowed']) {
            throw new \InvalidArgumentException(
                'This policy would block the country you are connecting from right now (or it cannot be determined while "fail open" is off) and you would lose access.'
            );
        }

        $saved = $this->policies->updateValue(self::POLICY_KEY, json_encode($policy, JSON_UNESCAPED_UNICODE), $adminUserId);
        if (!$saved) {
            throw new \RuntimeException('Could not save the country restriction policy.');
        }

        $this->auditLog->record($adminUserId, 'security_policy.country_restriction_updated', 'SecurityPolicy', null, $before, $policy, $ip);

        return $policy;
    }

    /**
     * @return array{allowed:bool,reason:string,country_code:?string} reason
     *         واحدة من 'disabled', 'resolved', 'unresolved_fail_open',
     *         'unresolved_fail_closed'.
     */
    public function evaluate(string $ip): array
    {
        return $this->evaluateWith($this->getPolicy(), $ip);
    }

    private function evaluateWith(array $policy, string $ip): array
    {
        if ($policy['mode'] === 'disabled') {
            return ['allowed' => true, 'reason' => 'disabled', 'country_code' => null];
        }

        $lookup = $this->geoIp->resolve($ip);

        if ($lookup['status'] !== 'ok' || !$lookup['code']) {
            return [
                'allowed'      => $policy['fail_open'],
                'reason'       => $policy['fail_open'] ? 'unresolved_fail_open' : 'unresolved_fail_closed',
                'country_code' => null,
            ];
        }

        $code = $lookup['code'];
        $allowed = $policy['mode'] === 'allowlist'
            ? in_array($code, $policy['allowed_countries'], true)
            : !in_array($code, $policy['blocked_countries'], true);

        return ['allowed' => $allowed, 'reason' => 'resolved', 'country_code' => $code];
    }

    /** @return string[] */
    private function parseCodes(string $raw): array
    {
        $codes = array_map(
            static fn ($c) => strtoupper(trim($c)),
            preg_split('/[,\n\r;\s]+/u', $raw) ?: []
        );
        $codes = array_values(array_unique(array_filter($codes, static fn ($c) => $c !== '')));

        foreach ($codes as $code) {
            if (!preg_match('/^[A-Z]{2}$/', $code)) {
                throw new \InvalidArgumentException("\"{$code}\" is not a valid 2-letter country code.");
            }
        }

        return $codes;
    }
}
