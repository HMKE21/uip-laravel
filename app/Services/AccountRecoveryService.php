<?php

namespace App\Services;

use App\Support\DeletedEmail;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;

/**
 * استرجاع الحسابات المحذوفة اللي إيميلها اتأخد/اتسجّل تاني (أو لسه فاضي).
 *
 * الحذف soft delete (الصف وكل بياناته بتفضل). لما حد يسجّل بنفس الإيميل بنحوّل إيميل المحذوف لـ tombstone
 * ونحفظ الأصلي في users.original_email. السيناريوهات:
 *  1) صاحب الحساب المحذوف جه يسجّل بنفس الإيميل: نقوله "كان عندك حساب محذوف"؛ يسترجعه بـ OTP + كلمة سر جديدة
 *     (بجوجل من غير OTP لأن جوجل أثبت الملكية).
 *  2) الإيميل اتسجّل بحساب جديد فعلًا: صاحبه (وهو مسجّل دخول) يطلب "استرجاع بيانات حسابي القديم" بـ OTP،
 *     فبيانات القديم (اللي يختارها) بتتدمج في الحالي والقديم بيفضل محذوف.
 *  3) حساب جديد "محجوز" (pending ومحدش أكّد إيميله ومفيش جوجل): صاحب الإيميل الحقيقي بالـ OTP بياخد الإيميل.
 *  4) الأدمن بيرجّع حساب والإيميل متاخد: إيميل جديد، أو دمج القديم في الشغّال (AdminUsersApiController::restore).
 *
 * الاسترجاع/الدمج الذاتي للطالب بس وبشروط (selfEligibility)؛ الباقي عن طريق الأدمن.
 */
class AccountRecoveryService
{
    public const STATE_NONE = 'none';
    public const STATE_RESTORABLE = 'restorable';
    public const STATE_NEEDS_LOGIN = 'needs_login_merge';
    public const STATE_NOT_ELIGIBLE = 'not_eligible';

    public function __construct(
        private AccountLifecycleService $lifecycle,
        private AccountMergeService $merger,
        private AccountRecoveryOtp $otp,
        private PasswordPolicyService $passwordPolicy,
        private MailService $mail
    ) {
    }

    // ------------------------------------------------------------ lookup

    /** أحدث حساب محذوف (ومش مدموج) إيميله الأصلي = $email. */
    public function findDeleted(string $email): ?object
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        return DB::table('users')->whereNotNull('deleted_at')->whereNull('merged_into_user_id')
            ->where(function ($q) use ($email) {
                $q->whereRaw('LOWER(original_email) = ?', [$email])
                  ->orWhereRaw('LOWER(email) = ?', [$email])
                  ->orWhereRaw('LOWER(email) LIKE ?', ['deleted-%-' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $email)]);
            })
            ->orderByDesc('deleted_at')->orderByDesc('id')->first() ?: null;
    }

    public function activeByEmail(string $email): ?object
    {
        return DB::table('users')->whereNull('deleted_at')->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])->first() ?: null;
    }

    /** حساب جديد محجوز: pending ومتأكدش إيميله ومفيش جوجل ودوره طالب. */
    public function isSquatter(object $user): bool
    {
        if (!EmailVerificationService::blocksLogin($user)) {
            return false;
        }
        $social = DB::table('user_social_accounts')->where('user_id', $user->id)->exists();

        return !$social && app(RoleService::class)->primaryRoleFor((int) $user->id) === 'student';
    }

    /** @return array{ok:bool, code:?string} */
    public function selfEligibility(object $deleted): array
    {
        $cfg = (array) config('account_recovery.self', []);
        if (!($cfg['enabled'] ?? true)) {
            return ['ok' => false, 'code' => 'self_recovery_disabled'];
        }
        $role = app(RoleService::class)->primaryRoleFor((int) $deleted->id);
        $kind = $this->lifecycle->kindOf((int) $deleted->id);
        if (!in_array($role, (array) ($cfg['roles'] ?? ['student']), true) || in_array($kind, ['university', 'supervisor', 'academic_staff'], true)) {
            return ['ok' => false, 'code' => 'role_not_allowed'];
        }
        $days = (int) ($cfg['retention_days'] ?? 0);
        if ($days > 0 && $deleted->deleted_at && strtotime((string) $deleted->deleted_at) < strtotime("-{$days} days")) {
            return ['ok' => false, 'code' => 'retention_expired'];
        }
        // كان موقوف/محظور قبل الحذف أو مقفول نهائيًا: الأدمن بس يقرر.
        if (in_array((string) $deleted->status_before_delete, ['suspended', 'banned'], true) || !empty($deleted->lock_permanent)) {
            return ['ok' => false, 'code' => 'needs_admin'];
        }
        $uni = $this->lifecycle->memberUniversityId((int) $deleted->id);
        if ($uni && DB::table('universities')->where('id', $uni)->whereNotNull('deleted_at')->exists()) {
            return ['ok' => false, 'code' => 'needs_admin'];
        }

        return ['ok' => true, 'code' => null];
    }

    /** @return array{state:string, deleted:?object, active:?object, code:?string} */
    public function state(string $email): array
    {
        $deleted = $this->findDeleted($email);
        if (!$deleted) {
            return ['state' => self::STATE_NONE, 'deleted' => null, 'active' => null, 'code' => null];
        }
        $el = $this->selfEligibility($deleted);
        if (!$el['ok']) {
            return ['state' => self::STATE_NOT_ELIGIBLE, 'deleted' => $deleted, 'active' => null, 'code' => $el['code']];
        }
        $active = $this->activeByEmail($email);
        if ($active && !$this->isSquatter($active)) {
            return ['state' => self::STATE_NEEDS_LOGIN, 'deleted' => $deleted, 'active' => $active, 'code' => null];
        }

        return ['state' => self::STATE_RESTORABLE, 'deleted' => $deleted, 'active' => $active, 'code' => null];
    }

    public static function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $visible = mb_substr($name, 0, min(2, max(1, mb_strlen($name) - 1)));

        return $visible . str_repeat('*', max(3, mb_strlen($name) - mb_strlen($visible))) . '@' . $domain;
    }

    // ------------------------------------------------------------ restore (self)

    /** @return array{ok:bool, status:int, code:?string, retry_after?:int, email?:string, expires_in_minutes?:int} */
    public function sendRestoreOtp(string $email, string $locale, ?string $ip): array
    {
        $st = $this->state($email);
        if ($st['state'] === self::STATE_NONE) {
            // مبنكشفش وجود/عدم وجود حساب: رد "اتبعت" عام.
            return ['ok' => true, 'status' => 200, 'code' => null, 'email' => self::maskEmail($email), 'expires_in_minutes' => $this->otp->ttl()];
        }
        if ($st['state'] !== self::STATE_RESTORABLE) {
            return $this->no($st['state'] === self::STATE_NEEDS_LOGIN ? 'needs_login_merge' : (string) $st['code'], $st['state'] === self::STATE_NEEDS_LOGIN ? 409 : 403);
        }

        $d = $st['deleted'];

        return $this->issueAndMail('restore:' . $d->id, DeletedEmail::original($d), (string) $d->full_name, 'restore', $locale, $ip);
    }

    /** @return array{ok:bool, status:int, code:?string, message?:string} */
    public function restoreWithOtp(string $email, string $otp, string $password, ?string $ip): array
    {
        $st = $this->state($email);
        if ($st['state'] === self::STATE_NONE) {
            return $this->no('not_found', 404);
        }
        if ($st['state'] === self::STATE_NOT_ELIGIBLE) {
            return $this->no((string) $st['code'], 403);
        }
        if ($st['state'] === self::STATE_NEEDS_LOGIN) {
            return $this->no('needs_login_merge', 409);
        }

        $deleted = $st['deleted'];
        $scope = 'restore:' . $deleted->id;
        $v = $this->otp->verify($scope, $otp);
        if ($v !== AccountRecoveryOtp::OK) {
            return $this->no($v, $v === AccountRecoveryOtp::LOCKED ? 429 : 422);
        }
        try {
            $this->passwordPolicy->assertValid($password);
            $this->passwordPolicy->assertNotReused($deleted->id, $password);
        } catch (\InvalidArgumentException $e) {
            // الرمز صح لكن كلمة السر مرفوضة: منحرقش الرمز.
            return ['ok' => false, 'status' => 422, 'code' => 'password_rejected', 'message' => $e->getMessage()];
        }
        $this->otp->consume($scope);

        $res = $this->restoreVerified($deleted, $st['active'], null);
        if (!$res['ok']) {
            return ['ok' => false, 'status' => 422, 'code' => 'restore_failed', 'message' => (string) $res['error']];
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        DB::table('users')->where('id', $deleted->id)->update(['password_hash' => $hash, 'updated_at' => now()]);
        $this->passwordPolicy->recordPasswordChange($deleted->id, $hash);
        $this->lifecycle->revokeAccess((int) $deleted->id, 'account_recovered');
        SecurityLog::write('Deleted account restored by owner (OTP)', ['user_id' => $deleted->id, 'ip' => $ip]);

        return ['ok' => true, 'status' => 200, 'code' => null];
    }

    /**
     * يرجّع الحساب المحذوف بعد ما ملكية الإيميل اتثبتت (OTP أو جوجل): لو إيميله محجوز بحساب جديد غير مؤكد
     * بنقفل الحساب المحجوز الأول، وبعدين الاسترجاع العادي، والإيميل يتأكد.
     * @return array{ok:bool, error:?string}
     */
    public function restoreVerified(object $deleted, ?object $squatter, ?int $actorId): array
    {
        $email = DeletedEmail::original($deleted);
        if ($squatter) {
            if (!$this->isSquatter($squatter)) {
                return ['ok' => false, 'error' => 'This email is now used by another account.'];
            }
            DB::table('users')->where('id', $squatter->id)->update([
                'deleted_at' => now(), 'status' => 'banned', 'updated_at' => now(),
            ]);
            $this->lifecycle->revokeAccess((int) $squatter->id, 'email_reclaimed');
            DeletedEmail::release($email, (int) $deleted->id);
        }

        $res = $this->lifecycle->restore((int) $deleted->id, $actorId);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error']];
        }
        app(EmailVerificationService::class)->markVerified((int) $deleted->id);

        return ['ok' => true, 'error' => null];
    }

    // ------------------------------------------------------------ merge (self, logged in)

    /** حسابات محذوفة إيميلها الأصلي = إيميل الحساب الحالي ومتاح دمجها ذاتيًا. */
    public function mergeCandidates(object $current, string $locale): array
    {
        $rows = DB::table('users')->whereNotNull('deleted_at')->whereNull('merged_into_user_id')->where('id', '!=', $current->id)
            ->where(function ($q) use ($current) {
                $email = strtolower((string) $current->email);
                $q->whereRaw('LOWER(original_email) = ?', [$email])
                  ->orWhereRaw('LOWER(email) LIKE ?', ['deleted-%-' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $email)]);
            })->orderByDesc('deleted_at')->get();

        $out = [];
        foreach ($rows as $d) {
            if (!$this->selfEligibility($d)['ok']) {
                continue;
            }
            $out[] = [
                'uuid'       => $d->uuid,
                'name'       => $d->full_name,
                'deleted_at' => $d->deleted_at,
                'groups'     => $this->merger->preview((int) $d->id, (int) $current->id, $locale),
            ];
        }

        return $out;
    }

    private function candidate(object $current, string $deletedUuid): ?object
    {
        foreach ($this->mergeCandidates($current, 'en') as $c) {
            if ($c['uuid'] === $deletedUuid) {
                return DB::table('users')->where('uuid', $deletedUuid)->first() ?: null;
            }
        }

        return null;
    }

    public function sendMergeOtp(object $current, string $deletedUuid, string $locale, ?string $ip): array
    {
        $d = $this->candidate($current, $deletedUuid);
        if (!$d) {
            return $this->no('not_found', 404);
        }

        return $this->issueAndMail("merge:{$d->id}:{$current->id}", (string) $current->email, (string) $current->full_name, 'merge', $locale, $ip);
    }

    public function mergeWithOtp(object $current, string $deletedUuid, string $otp, ?array $groups, ?string $ip): array
    {
        $d = $this->candidate($current, $deletedUuid);
        if (!$d) {
            return $this->no('not_found', 404);
        }
        $scope = "merge:{$d->id}:{$current->id}";
        $v = $this->otp->verify($scope, $otp);
        if ($v !== AccountRecoveryOtp::OK) {
            return $this->no($v, $v === AccountRecoveryOtp::LOCKED ? 429 : 422);
        }
        $this->otp->consume($scope);

        $result = $this->merger->merge((int) $d->id, (int) $current->id, (array) $groups, (int) $current->id, 'self');
        app(EmailVerificationService::class)->markVerified((int) $current->id);
        SecurityLog::write('Deleted account data merged by owner (OTP)', ['from' => $d->id, 'into' => $current->id, 'ip' => $ip]);

        return ['ok' => true, 'status' => 200, 'code' => null, 'result' => $result];
    }

    // ------------------------------------------------------------ admin helpers

    /** الحساب الشغّال اللي ماسك إيميل الحساب المحذوف (لو موجود). */
    public function emailConflict(object $deleted): ?object
    {
        return $this->activeByEmail(DeletedEmail::original($deleted));
    }

    // ------------------------------------------------------------ internals

    private function issueAndMail(string $scope, string $toEmail, string $name, string $purpose, string $locale, ?string $ip): array
    {
        $issued = $this->otp->issue($scope);
        if ($issued['otp'] === null) {
            return ['ok' => false, 'status' => 429, 'code' => 'otp_wait', 'retry_after' => $issued['retry_after']];
        }
        $sent = $this->mail->sendAccountRecoveryOtp($toEmail, $name, $issued['otp'], $this->otp->ttl(), $purpose, $ip, $locale);
        if (!$sent) {
            $this->otp->consume($scope);

            return $this->no('mail_failed', 502);
        }

        return ['ok' => true, 'status' => 200, 'code' => null, 'email' => self::maskEmail($toEmail), 'expires_in_minutes' => $this->otp->ttl(), 'retry_after' => (int) config('account_recovery.otp.resend_seconds', 60)];
    }

    private function no(string $code, int $status): array
    {
        return ['ok' => false, 'status' => $status, 'code' => $code];
    }
}
