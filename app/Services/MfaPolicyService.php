<?php

namespace App\Services;

use App\Repositories\SecurityPolicyRepository;
use App\Repositories\UserRepository;

/**
 * منقولة من app/Services/MfaPolicyService.php القديمة — بند 25 batch 3.
 * getPolicy()/appliesToRole()/updatePolicy() بس (اللي
 * SecurityPoliciesApiController محتاجاها).
 *
 * الإنفاذ (enforcement): status() بتحسب حالة المستخدم مقابل السياسة،
 * وبتنادى عليها LoginController (علشان تحوّله لصفحة التفعيل) و
 * UipAuthMiddleware (علشان تقفل باقي الـ API لحد ما يفعّل 2FA).
 * عدّاد الـ grace بيبدأ أول مرة نلاقي فيها المستخدم محتاج 2FA ومعندوش،
 * وبيتصفّر لما يفعّل 2FA (UserRepository::enableTwoFactor).
 */
class MfaPolicyService
{
    private const POLICY_KEY = 'mfa.policy';

    private const DEFAULTS = [
        'enforced_roles'    => [],
        'grace_period_days' => 7,
    ];

    public const ENFORCEABLE_ROLES = [
        'student', 'admin',
        'security_admin', 'security_officer', 'data_analyst',
    ];

    public function __construct(
        private SecurityPolicyRepository $policies,
        private UserRepository $users,
        private AuditLogService $auditLog
    ) {
    }

    /** @return array{enforced_roles:string[],grace_period_days:int} */
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
        $policy['enforced_roles'] = array_values(array_intersect(
            (array) $policy['enforced_roles'],
            self::ENFORCEABLE_ROLES
        ));
        return $policy;
    }

    public function appliesToRole(string $role): bool
    {
        return in_array($role, $this->getPolicy()['enforced_roles'], true);
    }


    /**
     * حالة المستخدم مقابل سياسة MFA.
     *
     * @return array{required:bool,enrolled:bool,restricted:bool,grace_days:int,grace_ends_at:?string,days_left:?int}
     */
    public function status(int $userId, string $role): array
    {
        $policy = $this->getPolicy();
        $result = [
            'required'      => false,
            'enrolled'      => false,
            'restricted'    => false,
            'grace_days'    => (int) $policy['grace_period_days'],
            'grace_ends_at' => null,
            'days_left'     => null,
        ];

        if (!in_array($role, $policy['enforced_roles'], true)) {
            return $result;
        }

        $user = $this->users->findById($userId);
        if (!$user) {
            return $result;
        }

        $result['required'] = true;

        if ($user->two_factor_enabled) {
            $result['enrolled'] = true;
            return $result;
        }

        $graceDays = (int) $policy['grace_period_days'];

        $started = $user->mfa_grace_started_at;
        if (!$started) {
            $started = now();
            $this->users->setMfaGraceStart($userId, $started->format('Y-m-d H:i:s'));
        }

        $ends = $started->copy()->addDays($graceDays);
        $result['grace_ends_at'] = $ends->toIso8601String();
        $result['restricted'] = $graceDays === 0 || now()->greaterThanOrEqualTo($ends);
        $result['days_left'] = $result['restricted']
            ? 0
            : (int) ceil(now()->diffInSeconds($ends, false) / 86400);

        return $result;
    }

    /** @throws \InvalidArgumentException on bad input */
    public function updatePolicy(array $input, $adminUserId, ?string $ip = null): array
    {
        $before = $this->getPolicy();

        $roles = (array) ($input['enforced_roles'] ?? []);
        $roles = array_values(array_intersect(array_map('strval', $roles), self::ENFORCEABLE_ROLES));

        $graceDays = (int) ($input['grace_period_days'] ?? 0);
        if ($graceDays < 0 || $graceDays > 90) {
            throw new \InvalidArgumentException('MFA grace period must be between 0 and 90 days.');
        }

        $policy = [
            'enforced_roles'    => $roles,
            'grace_period_days' => $graceDays,
        ];

        $saved = $this->policies->updateValue(self::POLICY_KEY, json_encode($policy, JSON_UNESCAPED_UNICODE), $adminUserId);
        if (!$saved) {
            throw new \RuntimeException('Could not save the MFA requirements policy.');
        }

        $this->auditLog->record($adminUserId, 'security_policy.mfa_updated', 'SecurityPolicy', null, $before, $policy, $ip);

        return $policy;
    }
}
