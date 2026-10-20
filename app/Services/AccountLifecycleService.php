<?php

namespace App\Services;

use App\Models\RefreshToken;
use App\Repositories\UniversityRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\DB;

/**
 * حذف/استرجاع أي حساب بغض النظر عن دوره (student / university / supervisor /
 * academic_staff / admin / security_* / data_analyst ...).
 *
 *  - الحذف soft delete (deleted_at) + status=banned، والحالة الأصلية بتتسجّل في
 *    users.status_before_delete عشان الاسترجاع يرجّعها.
 *  - الجلسات والـ refresh tokens بتتقفل فورًا (الـ middleware كمان بيرفض أي token لحساب محذوف/موقوف).
 *  - الحساب المحذوف بيوصله إيميل، والجهة المرتبطة بيه (الجامعة) بيوصلها إشعار.
 *  - حساب الجامعة: بيمشي على UniversityRepository::softDelete() اللي بيوقف كل
 *    المرتبطين بيها (طلاب + مشرفين + هيئة أكاديمية) وبيبلّغهم، والاسترجاع بيرجّعهم.
 *  - أي حساب مرتبط بجامعة محذوفة مينفعش يترجّع/يتفعّل لوحده قبل الجامعة.
 */
class AccountLifecycleService
{
    /** @return 'university'|'supervisor'|'academic_staff'|'student'|'user' */
    public function kindOf(int $userId): string
    {
        if (DB::table('universities')->where('user_id', $userId)->exists()) {
            return 'university';
        }
        if (DB::table('supervisors')->where('user_id', $userId)->exists()) {
            return 'supervisor';
        }
        if (DB::table('academic_staff')->where('user_id', $userId)->exists()) {
            return 'academic_staff';
        }
        if (DB::table('students')->where('user_id', $userId)->exists()) {
            return 'student';
        }
        return 'user';
    }

    /** @return array<int,string> slugs of all roles the user holds */
    private function roleSlugs(int $userId): array
    {
        return DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $userId)->pluck('r.slug')->all();
    }

    /** الجامعة (id) المرتبط بيها الحساب كعضو (طالب/مشرف/هيئة)، لو فيه. */
    public function memberUniversityId(int $userId): ?int
    {
        $id = DB::table('students')->where('user_id', $userId)->value('university_id')
            ?: DB::table('supervisors')->where('user_id', $userId)->value('university_id')
            ?: DB::table('academic_staff')->where('user_id', $userId)->value('university_id');

        return $id ? (int) $id : null;
    }

    /** قفل كل الجلسات والـ refresh tokens لحساب. */
    public function revokeAccess(int $userId, string $reason): void
    {
        try {
            app(UserSessionService::class)->endAllForUser($userId, $reason);
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            RefreshToken::where('user_id', $userId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * @return array{ok:bool, kind:?string, accounts_suspended:int, error:?string}
     */
    public function delete(int $userId, ?int $actorId = null): array
    {
        $row = DB::table('users')->where('id', $userId)->whereNull('deleted_at')->first();
        if (!$row) {
            return $this->fail('User not found.');
        }

        $kind = $this->kindOf($userId);

        // مينفعش نقفل آخر أدمن شغّال على المنصة.
        if (in_array('admin', $this->roleSlugs($userId), true) && $row->status === 'active') {
            $others = DB::table('users as u')
                ->join('user_roles as ur', 'ur.user_id', '=', 'u.id')
                ->join('roles as r', 'r.id', '=', 'ur.role_id')
                ->where('r.slug', 'admin')->where('u.status', 'active')->whereNull('u.deleted_at')
                ->where('u.id', '!=', $userId)->count();
            if ($others === 0) {
                return $this->fail('You cannot delete the last active admin account.');
            }
        }

        // جامعة => الحذف الكامل (الجامعة + المرتبطين بيها).
        if ($kind === 'university') {
            $uniId = DB::table('universities')->where('user_id', $userId)->whereNull('deleted_at')->value('id');
            if ($uniId) {
                $res = app(UniversityRepository::class)->softDelete((int) $uniId, $actorId);
                return [
                    'ok' => $res['ok'], 'kind' => $kind, 'accounts_suspended' => $res['students_suspended'],
                    'error' => $res['ok'] ? null : 'University not found.', 'university_id' => (int) $uniId,
                ];
            }
        }

        DB::transaction(function () use ($row, $userId, $kind) {
            DB::table('users')->where('id', $userId)->update([
                'status_before_delete' => in_array($row->status, ['banned'], true) ? null : $row->status,
            ]);
            app(UserRepository::class)->delete($userId);

            if ($kind === 'supervisor') {
                DB::table('supervisors')->where('user_id', $userId)->update(['status' => 'inactive']);
            } elseif ($kind === 'academic_staff') {
                DB::table('academic_staff')->where('user_id', $userId)->update(['status' => 'inactive']);
            }
        });

        $this->revokeAccess($userId, 'account_deleted');
        try { app(MessagingService::class)->announceAccountEvent($userId, 'account_deleted'); } catch (\Throwable $e) { report($e); }
        $this->notifyAccount($row, 'deleted', $actorId !== null && $actorId === $userId);
        $this->notifyUniversity($userId, $row, $kind, 'deleted');

        return ['ok' => true, 'kind' => $kind, 'accounts_suspended' => 0, 'error' => null];
    }

    /**
     * @return array{ok:bool, kind:?string, accounts_restored:int, error:?string}
     */
    public function restore(int $userId, ?int $actorId = null, ?string $emailOverride = null): array
    {
        $row = DB::table('users')->where('id', $userId)->whereNotNull('deleted_at')->first();
        if (!$row) {
            return ['ok' => false, 'kind' => null, 'accounts_restored' => 0, 'error' => 'User not found in the trash.'];
        }

        $kind = $this->kindOf($userId);

        if ($kind === 'university') {
            $uniId = DB::table('universities')->where('user_id', $userId)->whereNotNull('deleted_at')->value('id');
            if ($uniId) {
                $res = app(UniversityRepository::class)->restore((int) $uniId, $emailOverride);
                return [
                    'ok' => $res['ok'], 'kind' => $kind, 'accounts_restored' => $res['students_restored'],
                    'error' => $res['error'], 'university_id' => (int) $uniId,
                ];
            }
        }

        // عضو في جامعة لسه محذوفة => الجامعة الأول.
        $memberUni = $this->memberUniversityId($userId);
        if ($memberUni && DB::table('universities')->where('id', $memberUni)->whereNotNull('deleted_at')->exists()) {
            return ['ok' => false, 'kind' => $kind, 'accounts_restored' => 0, 'error' => 'This account belongs to a deleted university. Restore the university first.'];
        }

        $res = app(UserRepository::class)->restore($userId, $emailOverride);
        if (!$res['ok']) {
            return ['ok' => false, 'kind' => $kind, 'accounts_restored' => 0, 'error' => $res['error']];
        }

        if ($kind === 'supervisor') {
            DB::table('supervisors')->where('user_id', $userId)->where('invitation_status', 'accepted')->update(['status' => 'active']);
        } elseif ($kind === 'academic_staff') {
            DB::table('academic_staff')->where('user_id', $userId)->where('invitation_status', 'accepted')->update(['status' => 'active']);
        }

        $fresh = DB::table('users')->where('id', $userId)->first();
        try { app(MessagingService::class)->announceAccountEvent($userId, 'account_restored'); } catch (\Throwable $e) { report($e); }
        $this->notifyAccount($fresh, 'restored');
        $this->notifyUniversity($userId, $fresh, $kind, 'restored');

        return ['ok' => true, 'kind' => $kind, 'accounts_restored' => 0, 'error' => null];
    }

    /** إيميل + إشعار منصة لصاحب الحساب نفسه. */
    private function notifyAccount(object $user, string $event, bool $self = false): void
    {
        $ar = ($user->preferred_language ?? 'ar') === 'ar';
        if ($event === 'deleted' && $self) {
            $title = $ar ? 'تم حذف حسابك' : 'Your account was deleted';
            $body  = $ar ? 'حذفت حسابك من إعدادات الحساب. لو ده كان بالغلط تقدر تسترجعه بتسجيل حساب بنفس الإيميل وتأكيد الكود، خلال فترة الاحتفاظ.'
                         : 'You deleted your account from the account settings. If this was a mistake you can restore it by signing up with the same email and confirming the code, within the retention period.';
        } elseif ($event === 'deleted') {
            $title = $ar ? 'تم حذف حسابك من المنصة' : 'Your account was removed from the platform';
            $body  = $ar ? 'تم حذف حسابك بواسطة إدارة المنصة. تواصل مع الدعم لو محتاج مساعدة أو لاسترجاعه.'
                         : 'Your account was removed by the platform administrators. Contact support if you need help or want it restored.';
        } else {
            $title = $ar ? 'تم استرجاع حسابك' : 'Your account was restored';
            $body  = $ar ? 'تم استرجاع حسابك على المنصة. تقدر تسجّل الدخول دلوقتي.'
                         : 'Your account has been restored. You can sign in again.';
        }
        try { app(NotificationService::class)->notify((int) $user->id, 'account_status', $title, $body, null, 'high'); } catch (\Throwable $e) { report($e); }
        try { app(MailService::class)->sendNotificationEmail((string) $user->email, (string) $user->full_name, $title, $body, null, $ar ? 'ar' : 'en'); } catch (\Throwable $e) { report($e); }
    }

    /** لو الحساب طالب/مشرف/هيئة: نبلّغ حساب جامعته (داخل المنصة). */
    private function notifyUniversity(int $userId, object $user, string $kind, string $event): void
    {
        $uniId = $this->memberUniversityId($userId);
        $uniUserId = $uniId ? DB::table('universities')->where('id', $uniId)->whereNull('deleted_at')->value('user_id') : null;
        if (!$uniUserId) {
            return;
        }
        $uni = DB::table('users')->where('id', $uniUserId)->first(['preferred_language']);
        $ar = ($uni->preferred_language ?? 'ar') === 'ar';
        $role = match ($kind) {
            'student' => $ar ? 'الطالب' : 'The student',
            'supervisor' => $ar ? 'المشرف' : 'The supervisor',
            default => $ar ? 'عضو الهيئة الأكاديمية' : 'The academic staff member',
        };
        if ($event === 'deleted') {
            $title = $ar ? "تم حذف حساب: {$user->full_name}" : "Account removed: {$user->full_name}";
            $body  = $ar ? "{$role} {$user->full_name} تم حذف حسابه من المنصة بواسطة الإدارة." : "{$role} {$user->full_name} was removed from the platform by the administrators.";
        } else {
            $title = $ar ? "تم استرجاع حساب: {$user->full_name}" : "Account restored: {$user->full_name}";
            $body  = $ar ? "{$role} {$user->full_name} تم استرجاع حسابه على المنصة." : "{$role} {$user->full_name} was restored on the platform.";
        }
        try { app(NotificationService::class)->notify((int) $uniUserId, 'account_status', $title, $body, null, 'normal'); } catch (\Throwable $e) { report($e); }
    }

    private function fail(string $error): array
    {
        return ['ok' => false, 'kind' => null, 'accounts_suspended' => 0, 'error' => $error];
    }
}
