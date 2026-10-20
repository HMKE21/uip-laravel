<?php

namespace App\Services;

use App\Repositories\SecurityPolicyRepository;

/**
 * منقولة من app/Services/IpRestrictionPolicyService.php القديمة بالكامل
 * — بند 25 batch 3. سياسة allow/deny-list بدعم CIDR (IPv4 بس — IPv6
 * بتتطابق كعنوان كامل من غير bitmask، موثّق زي القديمة بالظبط). isAllowed()
 * منقولة كاملة (منطق ذاتي الاكتفاء، بلا اعتماديات) — بس مفيش نقطة في
 * AuthService الحالي بتنادي عليها لسه (نفس فجوة enforcement الموثّقة في
 * SessionPolicyService/MfaPolicyService)، جاهزة لما توصيل اللوجين يتعمل.
 */
class IpRestrictionPolicyService
{
    private const POLICY_KEY = 'ip_restriction.policy';

    private const DEFAULTS = [
        'mode'      => 'disabled',
        'allowlist' => [],
        'denylist'  => [],
    ];

    public function __construct(
        private SecurityPolicyRepository $policies,
        private AuditLogService $auditLog
    ) {
    }

    /** @return array{mode:string,allowlist:string[],denylist:string[]} */
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
        $policy['allowlist'] = array_values(array_filter((array) $policy['allowlist']));
        $policy['denylist'] = array_values(array_filter((array) $policy['denylist']));
        return $policy;
    }

    /** @throws \InvalidArgumentException on bad input */
    public function updatePolicy(array $input, $adminUserId, ?string $ip = null): array
    {
        $before = $this->getPolicy();

        $mode = (string) ($input['mode'] ?? 'disabled');
        if (!in_array($mode, ['disabled', 'allowlist', 'denylist'], true)) {
            throw new \InvalidArgumentException('Invalid IP restriction mode.');
        }

        $allowlist = $this->parseEntries((string) ($input['allowlist'] ?? ''));
        $denylist = $this->parseEntries((string) ($input['denylist'] ?? ''));

        if ($mode === 'allowlist' && !$allowlist) {
            throw new \InvalidArgumentException('Add at least one IP/CIDR range before enabling allowlist mode.');
        }
        if ($mode === 'denylist' && !$denylist) {
            throw new \InvalidArgumentException('Add at least one IP/CIDR range before enabling denylist mode.');
        }

        $policy = ['mode' => $mode, 'allowlist' => $allowlist, 'denylist' => $denylist];

        // حماية الأدمن من قفل نفسه: عنوانه الحالي لازم يفضل مسموح بالسياسة الجديدة.
        if ($ip && !$this->allowsWith($policy, $ip)) {
            throw new \InvalidArgumentException(
                "This policy would block your current IP address ({$ip}) and you would lose access. Add your address to the allowlist (or remove it from the denylist) first."
            );
        }

        $saved = $this->policies->updateValue(self::POLICY_KEY, json_encode($policy, JSON_UNESCAPED_UNICODE), $adminUserId);
        if (!$saved) {
            throw new \RuntimeException('Could not save the IP restriction policy.');
        }

        $this->auditLog->record($adminUserId, 'security_policy.ip_restriction_updated', 'SecurityPolicy', null, $before, $policy, $ip);

        return $policy;
    }

    /** True = هذا العنوان مسموح له يكمل اللوجين، حسب السياسة الحالية. */
    public function isAllowed(string $ip): bool
    {
        if ($ip === '') {
            return true;
        }

        return $this->allowsWith($this->getPolicy(), $ip);
    }

    private function allowsWith(array $policy, string $ip): bool
    {
        if ($policy['mode'] === 'allowlist') {
            return $this->matchesAny($ip, $policy['allowlist']);
        }
        if ($policy['mode'] === 'denylist') {
            return !$this->matchesAny($ip, $policy['denylist']);
        }

        return true;
    }

    /** @param string[] $entries */
    private function matchesAny(string $ip, array $entries): bool
    {
        foreach ($entries as $entry) {
            if ($this->matches($ip, $entry)) {
                return true;
            }
        }
        return false;
    }

    private function matches(string $ip, string $entry): bool
    {
        $entry = trim($entry);
        if ($entry === '') {
            return false;
        }

        if (!str_contains($entry, '/')) {
            return $entry === $ip;
        }

        [$subnet, $maskBits] = explode('/', $entry, 2);
        if (!filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $maskBits = (int) $maskBits;
        if ($maskBits < 0 || $maskBits > 32) {
            return false;
        }

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = $maskBits === 0 ? 0 : (-1 << (32 - $maskBits));
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    /** @return string[] */
    private function parseEntries(string $raw): array
    {
        $entries = array_map('trim', preg_split('/[,\n\r;\s]+/u', $raw) ?: []);
        $entries = array_values(array_unique(array_filter($entries, static fn ($e) => $e !== '')));

        foreach ($entries as $entry) {
            $addr = str_contains($entry, '/') ? explode('/', $entry, 2)[0] : $entry;
            if (!filter_var($addr, FILTER_VALIDATE_IP)) {
                throw new \InvalidArgumentException("\"{$entry}\" is not a valid IP address or CIDR range.");
            }
        }

        return $entries;
    }
}
