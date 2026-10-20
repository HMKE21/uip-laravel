<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Repositories\BlockedIpRepository;
use App\Repositories\UserRepository;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use App\Services\AccountLifecycleService;
use App\Services\AdminUserAffiliationService;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Controllers/Api/AdminUsersApiController.php القديمة —
 * بند 25 batch 9 (Admin > Users، CRUD + suspend/activate/role/block-ip).
 * سطح /api/v1/admin/users/* واحد لدليل المستخدمين الكامل (كل الأدوار
 * الستة)، بيستخدم نفس UserRepository/AuditLogService/BlockedIpRepository
 * بالظبط زي Admin\AdminUserManagementController القديمة — index/store/
 * show/update/destroy زائد الأكشنز اللي Admin بيمتلكها فعليًا: suspend/
 * activate، تغيير الدور الأساسي، منح/سحب دور إضافي، وحظر آخر IP معروف.
 * كل تعديل بيتسجل في audit log عبر AuditLogService::record()، نفس نداءات
 * الكنترولر القديمة بالظبط.
 *
 * RBAC: middleware uip.auth + uip.admin بيقفلوا المجموعة كلها (routes/api.php)،
 * زي ما القديمة كانت بتعمل بـ Session::hasRole('admin') على كل ميثود.
 */
class AdminUsersApiController extends Controller
{
    private const PER_PAGE_DEFAULT = 20;
    private const PER_PAGE_MAX = 100;

    public function __construct(
        private UserRepository $users,
        private AuditLogService $auditLog,
        private BlockedIpRepository $blockedIps
    ) {
    }

    private function requireAdmin(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'admin') {
            return $this->apiError('Only admin accounts can access the user directory.', null, 403);
        }
        return null;
    }

    /** GET /api/v1/admin/users — spec §7 pagination/search/filter. */
    public function index(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $in = fn (string $k) => trim((string) $request->input($k, ''));
        $date = fn (string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $in($k)) ? $in($k) : null;
        $days = fn (string $k) => ($n = (int) $request->input($k)) > 0 ? min($n, 36500) : null;
        $oneOf = fn (string $k, array $allowed) => in_array($in($k), $allowed, true) ? $in($k) : null;
        $yn = fn (string $k) => $oneOf($k, ['yes', 'no']);
        $id = fn (string $k) => ($n = (int) $request->input($k)) > 0 ? $n : null;

        $filters = array_filter([
            // role/status بيقبلوا قيمة واحدة أو أكتر مفصولة بفاصلة: role=student,supervisor
            'role'   => $in('role') ?: null,
            'status' => $in('status') ?: null,
            'search' => $in('q') ?: null,
            'university_id' => $id('university_id'),
            'faculty_id'    => $id('faculty_id'),
            'department_id' => $id('department_id'),
            'academic_year' => $id('academic_year'),
            // affiliation: none = طلاب/مشرفين/هيئة مش مرتبطين بأي جامعة، has = مرتبط بجامعة
            'affiliation'   => $oneOf('affiliation', ['none', 'has']),
            // حالة الانضمام
            'invitation_status' => $oneOf('invitation_status', ['pending', 'accepted', 'expired', 'none']),
            'email_verified'    => $yn('email_verified'),
            // أمان
            'two_factor'           => $yn('two_factor'),
            'locked'               => $yn('locked'),
            'must_change_password' => $oneOf('must_change_password', ['yes']),
            'failed_logins_min'    => $days('failed_logins_min'),
            // تاريخ الانضمام
            'joined_from'        => $date('joined_from'),
            'joined_to'          => $date('joined_to'),
            'joined_within_days' => $days('joined_within_days'),
            'joined_before_days' => $days('joined_before_days'),
            // آخر دخول
            'last_login'         => $oneOf('last_login', ['never', 'ever']),
            'active_within_days' => $days('active_within_days'),
            'inactive_days'      => $days('inactive_days'),
            'last_login_from'    => $date('last_login_from'),
            'last_login_to'      => $date('last_login_to'),
            // بيانات الحساب
            'email_domain' => mb_substr($in('email_domain'), 0, 120) ?: null,
            'has_phone'    => $yn('has_phone'),
            'has_avatar'   => $yn('has_avatar'),
            // فرز
            'sort' => $in('sort') ?: null,
            'dir'  => $oneOf('dir', ['asc', 'desc']),
        ], fn ($v) => $v !== null && $v !== '');
        $page = max(1, (int) $request->input('page', 1));
        $perPage = max(1, min(self::PER_PAGE_MAX, (int) $request->input('per_page', self::PER_PAGE_DEFAULT)));

        // status=deleted => سلة المحذوفات (قابلة للاسترجاع).
        $result = ($filters['status'] ?? null) === 'deleted'
            ? $this->users->paginateTrashed((string) ($filters['search'] ?? ''), $page, $perPage)
            : $this->users->paginateWithRoles($filters, $page, $perPage);

        return $this->apiSuccess($result['rows'], 'Users retrieved successfully.', 200, [
            'page' => $page, 'perPage' => $perPage, 'total' => $result['total'],
            'counts_by_status' => $this->users->countsByStatus(),
        ]);
    }

    /** GET /api/v1/admin/users/{id} — {id} is the user's uuid. */
    public function show(Request $request, $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $target = $this->users->findWithRoleByUuid((string) $id);
        if (!$target) {
            return $this->apiError('User not found.', null, 404);
        }

        $target['additional_roles'] = $this->users->additionalRoleSlugs($target['id']);
        $target['is_joker'] = (bool) DB::table('users')->where('id', $target['id'])->value('is_joker');
        $target['affiliation'] = app(AdminUserAffiliationService::class)->describe((int) $target['id']);

        return $this->apiSuccess($target, 'User retrieved successfully.');
    }

    /**
     * POST /api/v1/admin/users — generic "create account" used across
     * Users/Universities/Team pages on the web, preset by role.
     * Optional `is_joker: true` makes the account a Super Admin (all roles + role switcher).
     */
    public function store(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $request->validate([
            'email'     => ['required', 'email', new \App\Rules\RealEmail(\App\Support\BilingualName::localeOf($request))],
            'password'  => 'required|string',
            'role'      => 'required',
        ]);

        $res = $this->createAccount($request, $request->all());
        if (!$res['ok']) {
            return $this->apiError($res['message'], $res['errors'] ?? null, 422);
        }

        return $this->apiSuccess($res['user']->toArray(), 'Account created successfully.', 201);
    }

    /**
     * POST /api/v1/admin/users/bulk — create many accounts in one request.
     * Body: { users: [ {name_ar, name_en, email, phone?, password, role, is_joker?}, ... ] } (max 200).
     * Each row is independent: one bad row never blocks the others. Returns a per-row result.
     */
    public function bulkStore(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $request->validate([
            'users'   => 'required|array|min:1|max:200',
            'users.*' => 'array',
        ]);

        $results = [];
        $created = 0;
        $seen = [];
        foreach ($request->input('users') as $i => $row) {
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            $line  = $i + 1;

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $results[] = ['row' => $line, 'email' => $email, 'ok' => false, 'message' => 'Invalid or missing email.'];
                continue;
            }
            if ($why = \App\Support\DisposableEmail::problem($email, \App\Support\BilingualName::localeOf($request))) {
                $results[] = ['row' => $line, 'email' => $email, 'ok' => false, 'message' => $why];
                continue;
            }
            if (isset($seen[$email])) {
                $results[] = ['row' => $line, 'email' => $email, 'ok' => false, 'message' => 'Duplicate email inside this list.'];
                continue;
            }
            $seen[$email] = true;

            if (empty($row['password']) || empty($row['role'])) {
                $results[] = ['row' => $line, 'email' => $email, 'ok' => false, 'message' => 'Password and role are required.'];
                continue;
            }

            try {
                $res = $this->createAccount($request, array_merge($row, ['email' => $email]));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Bulk user create failed: ' . $e->getMessage());
                $res = ['ok' => false, 'message' => 'Unexpected error while creating this account.'];
            }

            if ($res['ok']) {
                $created++;
                $results[] = ['row' => $line, 'email' => $email, 'ok' => true, 'uuid' => $res['user']->uuid];
            } else {
                $results[] = ['row' => $line, 'email' => $email, 'ok' => false, 'message' => $res['message']];
            }
        }

        $this->auditLog->record($request->attributes->get('uip_user_id'), 'user.bulk_create', 'User', null, null, [
            'requested' => count($results), 'created' => $created,
        ]);

        return $this->apiSuccess(
            ['created' => $created, 'failed' => count($results) - $created, 'results' => $results],
            "{$created} account(s) created.",
            $created > 0 ? 201 : 422
        );
    }

    /**
     * Shared creation logic for store() and bulkStore().
     * @return array{ok:bool, user?:\App\Models\User, message?:string, errors?:mixed}
     */
    private function createAccount(Request $request, array $in): array
    {
        $email = trim((string) ($in['email'] ?? ''));
        $role  = (string) ($in['role'] ?? '');

        try {
            app(\App\Services\PasswordPolicyService::class)->assertValid((string) ($in['password'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $names = \App\Support\BilingualName::resolve($in['name_ar'] ?? null, $in['name_en'] ?? null, \App\Support\BilingualName::localeOf($request));
        if (!$names['ok']) {
            return ['ok' => false, 'message' => 'Please provide the name in both Arabic and English.', 'errors' => $names['errors']];
        }

        if ($this->users->emailExists($email)) {
            return ['ok' => false, 'message' => 'An account with this email already exists.', 'errors' => ['email' => 'An account with this email already exists.']];
        }

        if (!Role::where('slug', $role)->exists()) {
            return ['ok' => false, 'message' => 'That role does not exist.'];
        }

        if ($role === 'university') {
            $dup = \App\Support\UniversityName::conflicts($names['name_ar'], $names['name_en'], null, \App\Support\BilingualName::localeOf($request));
            if ($dup) {
                return ['ok' => false, 'message' => (string) reset($dup), 'errors' => $dup];
            }
        }

        $passwordHash = password_hash((string) $in['password'], PASSWORD_BCRYPT);

        $user = $this->users->createWithRole([
            'full_name'          => $names['full_name'],
            'name_ar'            => $names['name_ar'],
            'name_en'            => $names['name_en'],
            'email'              => $email,
            'phone'              => ($in['phone'] ?? null) ?: null,
            'password_hash'      => $passwordHash,
            'preferred_language' => 'ar',
            'status'             => 'active',
            'email_verified_at'  => now(),
        ], $role);

        app(\App\Services\PasswordPolicyService::class)->recordPasswordChange($user->id, $passwordHash);

        $this->auditLog->record($request->attributes->get('uip_user_id'), 'user.create', 'User', $user->id, null, [
            'email' => $user->email, 'role' => $role,
        ]);

        if (filter_var($in['is_joker'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $this->makeJoker($request, (int) $user->id, true);
        }

        return ['ok' => true, 'user' => $user];
    }

    /** PUT /api/v1/admin/users/{id}/joker — body { enabled: bool }. */
    public function setJoker(Request $request, $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $target = $this->users->findWithRoleByUuid((string) $id);
        if (!$target) {
            return $this->apiError('User not found.', null, 404);
        }

        $enabled = filter_var($request->input('enabled', true), FILTER_VALIDATE_BOOLEAN);
        $this->makeJoker($request, (int) $target['id'], $enabled);

        return $this->apiSuccess(['is_joker' => $enabled], $enabled ? 'Super Admin access enabled.' : 'Super Admin access removed.');
    }

    /**
     * Joker on: grant every role in config (keeps the primary role first) and set the flag.
     * Joker off: clear the flag and drop the extra roles again (primary stays).
     */
    private function makeJoker(Request $request, int $userId, bool $enabled): void
    {
        $actor = $request->attributes->get('uip_user_id');
        $roleSvc = app(\App\Services\RoleService::class);

        if ($enabled) {
            foreach (Role::pluck('slug') as $slug) {
                $this->users->grantAdditionalRole($userId, (string) $slug);
            }
            // Profile rows the role-specific pages expect.
            if (!DB::table('students')->where('user_id', $userId)->exists()) {
                DB::table('students')->insert(['user_id' => $userId]);
            }
            DB::table('users')->where('id', $userId)->update(['is_joker' => 1]);
        } else {
            $primary = $roleSvc->primaryRoleFor($userId);
            DB::table('users')->where('id', $userId)->update(['is_joker' => 0]);
            foreach ($roleSvc->allRolesFor($userId) as $slug) {
                if ($slug !== $primary) {
                    $this->users->revokeAdditionalRole($userId, $slug);
                }
            }
            DB::table('user_sessions')->where('user_id', $userId)->update(['active_role' => null]);
        }

        $this->auditLog->record($actor, $enabled ? 'user.joker_enable' : 'user.joker_disable', 'User', $userId, null, null);
    }

    /** PATCH /api/v1/admin/users/{id} — profile fields + optional role change. */
    public function update(Request $request, $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $target = $this->users->findWithRoleByUuid((string) $id);
        if (!$target) {
            return $this->apiError('User not found.', null, 404);
        }

        $data = $request->validate([
            'email'     => ['required', 'email', new \App\Rules\RealEmail(\App\Support\BilingualName::localeOf($request))],
        ]);

        $names = \App\Support\BilingualName::fromRequest($request);
        if (!$names['ok']) {
            return $this->apiError('Please provide the name in both Arabic and English.', $names['errors'], 422);
        }
        $data += \App\Support\BilingualName::columns($names);

        $newRoleForCheck = (string) $request->input('role', $target['role']);
        if ($newRoleForCheck === 'university' || $target['role'] === 'university') {
            $dup = \App\Support\UniversityName::conflicts($names['name_ar'], $names['name_en'], (int) $target['id'], \App\Support\BilingualName::localeOf($request));
            if ($dup) {
                return $this->apiError(reset($dup), $dup, 422);
            }
        }

        $ok = $this->users->updateProfile($target['id'], [
            'full_name' => $names['full_name'],
            'name_ar'   => $names['name_ar'],
            'name_en'   => $names['name_en'],
            'email'     => $data['email'],
            'phone'     => $request->input('phone') ?: null,
            'status'    => $request->input('status') ?: $target['status'],
        ]);

        $newRole = (string) $request->input('role', $target['role']);
        if ($newRole !== $target['role']) {
            $this->users->changeRole($target['id'], $newRole);
        }

        // A university account's official names always follow the account's names.
        if ($ok && $newRole === 'university') {
            $uni = DB::table('universities')->where('user_id', $target['id'])->first();
            if ($uni) {
                DB::table('universities')->where('id', $uni->id)->update(['official_name_ar' => $names['name_ar'], 'official_name_en' => $names['name_en']] + \App\Support\UniversityName::keys($names['name_ar'], $names['name_en']));
            } else {
                DB::table('universities')->insert(['user_id' => $target['id'], 'official_name_ar' => $names['name_ar'], 'official_name_en' => $names['name_en'], 'country' => ''] + \App\Support\UniversityName::keys($names['name_ar'], $names['name_en']));
            }
        }

        if ($ok) {
            $this->auditLog->record($request->attributes->get('uip_user_id'), 'user.update', 'User', $target['id'], [
                'full_name' => $target['full_name'], 'email' => $target['email'],
            ], $data);
        }

        return $ok
            ? $this->apiSuccess(null, 'User updated successfully.')
            : $this->apiError('Could not update this user.', null, 422);
    }

    /** DELETE /api/v1/admin/users/{id} — soft delete; an admin cannot delete their own account. */
    public function destroy(Request $request, $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $target = $this->users->findWithRoleByUuid((string) $id);
        if (!$target) {
            return $this->apiError('User not found.', null, 404);
        }

        if ((int) $target['id'] === (int) $request->attributes->get('uip_user_id')) {
            return $this->apiError('You cannot delete your own account.', null, 422);
        }

        // كل الأدوار بتمشي على نفس الخدمة: soft delete + قفل الجلسات + إشعارات،
        // والجامعة بتوقف وتبلّغ كل المرتبطين بيها (طلاب/مشرفين/هيئة أكاديمية).
        $res = app(AccountLifecycleService::class)->delete((int) $target['id'], (int) $request->attributes->get('uip_user_id'));

        if (!$res['ok']) {
            return $this->apiError($res['error'] ?: 'Could not delete this user.', null, 422);
        }

        if ($res['kind'] === 'university') {
            $this->auditLog->record($request->attributes->get('uip_user_id'), 'university.delete', 'University', (int) ($res['university_id'] ?? 0), ['email' => $target['email']], ['accounts_suspended' => $res['accounts_suspended']]);
        } else {
            $this->auditLog->record($request->attributes->get('uip_user_id'), 'user.delete', 'User', $target['id'], ['email' => $target['email'], 'role' => $target['role'] ?? null], null);
        }

        return $this->apiSuccess(
            ['accounts_suspended' => $res['accounts_suspended'], 'students_suspended' => $res['accounts_suspended'], 'kind' => $res['kind']],
            $res['accounts_suspended'] > 0 ? 'Deleted. Linked accounts were suspended and notified.' : 'User deleted successfully.'
        );
    }

    /**
     * POST /api/v1/admin/users/{id}/restore — يرجّع أي حساب محذوف (ولو جامعة: بجامعتها وكل المرتبطين بيها).
     *
     * لو إيميل الحساب المحذوف بقى ماسكه حساب شغّال تاني: بيرجّع 409 (code=email_conflict) فيه بيانات الحساب
     * الشغّال والاختيارات، والأدمن يعيد الطلب بـ resolution:
     *   - new_email          { email }  : يرجّع القديم بإيميل جديد.
     *   - merge_into_current { groups[] }: يدمج بيانات القديم (المجموعات المختارة) في الحساب الشغّال، والقديم يفضل محذوف.
     */
    public function restore(Request $request, $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $target = $this->users->findTrashedByUuid((string) $id);
        if (!$target) {
            return $this->apiError('User not found in the trash.', null, 404);
        }

        $adminId = (int) $request->attributes->get('uip_user_id');
        $recovery = app(\App\Services\AccountRecoveryService::class);
        $merger = app(\App\Services\AccountMergeService::class);
        $deletedRow = \Illuminate\Support\Facades\DB::table('users')->where('id', $target['id'])->first();
        $conflict = $deletedRow ? $recovery->emailConflict($deletedRow) : null;
        $resolution = (string) $request->input('resolution', '');
        $emailOverride = null;

        if ($conflict && $resolution === '') {
            $isUniversity = app(AccountLifecycleService::class)->kindOf((int) $target['id']) === 'university';
            return $this->apiError('This email is now used by another account.', [
                'code'     => 'email_conflict',
                'conflict' => [
                    'uuid'   => $conflict->uuid,
                    'name'   => $conflict->full_name,
                    'email'  => $conflict->email,
                    'status' => $conflict->status,
                    'role'   => app(\App\Services\RoleService::class)->primaryRoleFor((int) $conflict->id),
                    'created_at' => $conflict->created_at,
                ],
                'options' => $isUniversity ? ['new_email'] : ['new_email', 'merge_into_current'],
                'groups'  => $isUniversity ? [] : $merger->preview((int) $target['id'], (int) $conflict->id, 'en', true),
            ], 409);
        }

        if ($conflict && $resolution === 'merge_into_current') {
            if (app(AccountLifecycleService::class)->kindOf((int) $target['id']) === 'university') {
                return $this->apiError('A university account cannot be merged. Restore it with a new email instead.', null, 422);
            }
            try {
                $result = $merger->merge((int) $target['id'], (int) $conflict->id, (array) $request->input('groups', []), $adminId, 'admin');
            } catch (\Throwable $e) {
                return $this->apiError($e->getMessage(), null, 422);
            }
            $this->auditLog->record($adminId, 'user.merge', 'User', $target['id'], null, ['into' => $conflict->uuid, 'groups' => $result['groups']]);

            return $this->apiSuccess(
                ['merged' => true, 'into' => $conflict->uuid, 'moved' => $result['moved'], 'left_behind' => $result['left_behind']],
                'Old account data was merged into the active account. The old account stays deleted.'
            );
        }

        if ($conflict && $resolution === 'new_email') {
            $v = \Illuminate\Support\Facades\Validator::make($request->all(), ['email' => ['required', 'email', 'max:190', new \App\Rules\RealEmail(\App\Support\BilingualName::localeOf($request))]]);
            if ($v->fails()) {
                return $this->apiError($v->errors()->first(), null, 422);
            }
            $emailOverride = strtolower(trim((string) $request->input('email')));
        } elseif ($conflict) {
            return $this->apiError('Unknown resolution.', null, 422);
        }

        $res = app(AccountLifecycleService::class)->restore((int) $target['id'], $adminId, $emailOverride);
        if (!$res['ok']) {
            return $this->apiError($res['error'], null, 422);
        }

        if ($res['kind'] === 'university') {
            $this->auditLog->record($adminId, 'university.restore', 'University', (int) ($res['university_id'] ?? 0), null, ['accounts_restored' => $res['accounts_restored']]);
        } else {
            $this->auditLog->record($adminId, 'user.restore', 'User', $target['id'], null, ['email' => $emailOverride ?? $target['email'], 'new_email' => $emailOverride !== null]);
        }

        return $this->apiSuccess(
            ['accounts_restored' => $res['accounts_restored'], 'students_restored' => $res['accounts_restored'], 'kind' => $res['kind']],
            $res['accounts_restored'] > 0 ? 'Restored with its linked accounts.' : 'User restored successfully.'
        );
    }

    /** POST /api/v1/admin/users/{id}/suspend */
    public function suspend(Request $request, $id)
    {
        return $this->setStatus($request, $id, 'suspended', 'User suspended.', 'user.suspend');
    }

    /** POST /api/v1/admin/users/{id}/activate */
    public function activate(Request $request, $id)
    {
        return $this->setStatus($request, $id, 'active', 'User reactivated.', 'user.activate');
    }

    /** PATCH /api/v1/admin/users/{id}/role — replaces the user's primary role entirely. */
    public function changeRole(Request $request, $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $target = $this->users->findWithRoleByUuid((string) $id);
        if (!$target) {
            return $this->apiError('User not found.', null, 404);
        }

        $newRole = (string) $request->input('role', '');
        if ($newRole === 'university' && $target['role'] !== 'university') {
            $dup = \App\Support\UniversityName::conflicts($target['name_ar'] ?? $target['full_name'], $target['name_en'] ?? $target['full_name'], (int) $target['id'], \App\Support\BilingualName::localeOf($request));
            if ($dup) {
                return $this->apiError(reset($dup), $dup, 422);
            }
        }
        $ok = $this->users->changeRole($target['id'], $newRole);

        if ($ok) {
            $this->auditLog->record($request->attributes->get('uip_user_id'), 'user.role_change', 'User', $target['id'], ['role' => $target['role']], ['role' => $newRole]);
        }

        return $ok
            ? $this->apiSuccess(null, 'Role updated successfully.')
            : $this->apiError('That role does not exist.', null, 422);
    }

    /** POST /api/v1/admin/users/{id}/roles — grants an ADDITIONAL role on top of the user's existing access. */
    public function assignRole(Request $request, $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $target = $this->users->findWithRoleByUuid((string) $id);
        if (!$target) {
            return $this->apiError('User not found.', null, 404);
        }

        $roleSlug = (string) $request->input('role', '');
        $ok = $this->users->grantAdditionalRole($target['id'], $roleSlug);

        if ($ok) {
            $this->auditLog->record($request->attributes->get('uip_user_id'), 'user.additional_role_grant', 'User', $target['id'], null, ['role_granted' => $roleSlug]);
        }

        return $ok
            ? $this->apiSuccess(null, 'Additional access granted successfully.', 201)
            : $this->apiError('That role does not exist.', null, 422);
    }

    /** DELETE /api/v1/admin/users/{id}/roles/{role} — revokes an additional role (never the user's primary/only one). */
    public function revokeRole(Request $request, $id, $role)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $target = $this->users->findWithRoleByUuid((string) $id);
        if (!$target) {
            return $this->apiError('User not found.', null, 404);
        }

        $roleSlug = (string) $role;
        $ok = $this->users->revokeAdditionalRole($target['id'], $roleSlug);

        if ($ok) {
            $this->auditLog->record($request->attributes->get('uip_user_id'), 'user.additional_role_revoke', 'User', $target['id'], ['role_revoked' => $roleSlug], null);
        }

        return $ok
            ? $this->apiSuccess(null, 'Access revoked successfully.')
            : $this->apiError('That access can\'t be revoked (it\'s their primary role, or their only remaining one).', null, 422);
    }

    /** POST /api/v1/admin/users/{id}/block-ip — blocks the user's last-known login IP and suspends the account as a precaution. */
    public function blockIp(Request $request, $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $target = $this->users->findWithRoleByUuid((string) $id);
        if (!$target || empty($target['last_login_ip'])) {
            return $this->apiError('No known IP address for this user.', null, 422);
        }

        $ip = $target['last_login_ip'];
        $ok = $this->blockedIps->block($ip, 'Blocked from user #' . $target['id'] . ' (' . $target['email'] . ')', $request->attributes->get('uip_user_id'));
        $this->users->updateStatus($target['id'], 'suspended');

        if ($ok) {
            $this->auditLog->record($request->attributes->get('uip_user_id'), 'ip.block', 'User', $target['id'], null, ['ip' => $ip]);
        }

        return $ok
            ? $this->apiSuccess(['ip' => $ip], 'IP blocked successfully.', 201)
            : $this->apiError('That IP is already blocked.', null, 422);
    }

    /** GET /api/v1/admin/users/filter-options — دومينات الإيميل الأكتر استخدامًا + السنوات الدراسية (لقوائم الفلاتر). */
    public function filterOptions(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        return $this->apiSuccess($this->users->filterFacets(), 'Filter options retrieved successfully.');
    }

    /** GET /api/v1/admin/users/affiliation-options — الجامعات الشغالة وهيكلها (كليات/أقسام/برامج). */
    public function affiliationOptions(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        return $this->apiSuccess(app(AdminUserAffiliationService::class)->options(), 'Universities retrieved successfully.');
    }

    /** PUT /api/v1/admin/users/{id}/affiliation — نقل/ربط طالب أو مشرف أو عضو هيئة بجامعة (وكلية/قسم/برنامج). */
    public function updateAffiliation(Request $request, $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $target = $this->users->findWithRoleByUuid((string) $id);
        if (!$target) {
            return $this->apiError('User not found.', null, 404);
        }

        $data = $request->validate([
            'university_id' => 'required|integer',
            'faculty_id'    => 'nullable|integer',
            'department_id' => 'nullable|integer',
        ]);

        $svc = app(AdminUserAffiliationService::class);
        $res = $svc->assign(
            (int) $target['id'], (int) $data['university_id'],
            !empty($data['faculty_id']) ? (int) $data['faculty_id'] : null,
            !empty($data['department_id']) ? (int) $data['department_id'] : null,
            (int) $request->attributes->get('uip_user_id')
        );

        if (!$res['ok']) {
            return $this->apiError($res['error'], null, 422);
        }

        return $this->apiSuccess(
            ['affiliation' => $svc->describe((int) $target['id']), 'changed' => $res['changed']],
            'Affiliation updated successfully.'
        );
    }

    /** DELETE /api/v1/admin/users/{id}/affiliation — يشيل الطالب من جامعته خالص. */
    public function removeAffiliation(Request $request, $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $target = $this->users->findWithRoleByUuid((string) $id);
        if (!$target) {
            return $this->apiError('User not found.', null, 404);
        }

        $svc = app(AdminUserAffiliationService::class);
        $res = $svc->detach((int) $target['id'], (int) $request->attributes->get('uip_user_id'));
        if (!$res['ok']) {
            return $this->apiError($res['error'], null, 422);
        }

        return $this->apiSuccess(
            ['affiliation' => $svc->describe((int) $target['id']), 'changed' => $res['changed']],
            'Removed from the university.'
        );
    }

    // -- helpers --------------------------------------------------------------

    private function setStatus(Request $request, $id, string $status, string $successMessage, string $action)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $target = $this->users->findWithRoleByUuid((string) $id);
        if (!$target) {
            return $this->apiError('User not found.', null, 404);
        }

        if ($status === 'active') {
            // حساب اتوقف بسبب حذف جامعته => مينفعش يتفعّل قبل ما الجامعة تترجّع.
            $uniId = DB::table('users')->where('id', $target['id'])->value('suspended_by_university_id');
            if ($uniId && DB::table('universities')->where('id', $uniId)->whereNotNull('deleted_at')->exists()) {
                return $this->apiError('This account was suspended because its university was removed. Restore the university first.', null, 422);
            }
            DB::table('users')->where('id', $target['id'])->update(['suspended_by_university_id' => null, 'status_before_university_removal' => null]);
        }

        $ok = $this->users->updateStatus($target['id'], $status);

        if ($ok && $status !== 'active') {
            app(AccountLifecycleService::class)->revokeAccess((int) $target['id'], 'account_suspended');
        }

        if ($ok) {
            $this->auditLog->record($request->attributes->get('uip_user_id'), $action, 'User', $target['id'], ['status' => $target['status']], ['status' => $status]);
        }

        return $ok
            ? $this->apiSuccess(null, $successMessage)
            : $this->apiError('Could not update this user.', null, 422);
    }
}
