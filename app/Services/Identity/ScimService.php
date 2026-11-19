<?php

namespace App\Services\Identity;

use App\Services\AcademicStaffManagementService;
use App\Services\Mail\MailMembershipService;
use App\Services\StudentManagementService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * UIPE Mail — المرحلة 5: SCIM 2.0 (RFC 7643/7644) لكل جامعة (tenant). الـ IdP (Entra/Okta/Keycloak) بيدير دورة حياة الطلاب وهيئة التدريس:
 * إنشاء (دعوة + صندوق تلقائي)، تعطيل/تفعيل (حساب + صندوق + قطع الجلسات)، وتاريخ انتهاء العضوية.
 * مقصود: تعديل الأسماء/الإيميل من الـ IdP بعد الإنشاء مش بيتطبّق، و DELETE = تعطيل مش حذف.
 */
class ScimService
{
    public const EXT = 'urn:uipe:scim:schemas:extension:1.0:User';
    public const CORE = 'urn:ietf:params:scim:schemas:core:2.0:User';
    public const ERR = 'urn:ietf:params:scim:api:messages:2.0:Error';
    public const MAX_TOKENS = 10;

    public function __construct(
        private StudentManagementService $students,
        private AcademicStaffManagementService $staff,
        private TokenRevocationService $revoker,
        private MailMembershipService $membership
    ) {
    }

    /** @return never */
    private function fail(int $status, string $detail, ?string $scimType = null): never
    {
        throw new ScimException($status, $detail, $scimType);
    }

    private static function utcTs(?string $v): int
    {
        return $v ? (int) strtotime($v . ' UTC') : 0;
    }

    /* ------------------------------------------------------------------ توكنز (إدارة الجامعة) */

    public function tokens(int $tenantId): array
    {
        return DB::table('scim_tokens')->where('tenant_id', $tenantId)->orderByDesc('id')->get()->map(fn ($t) => [
            'id' => (int) $t->id, 'name' => $t->name,
            'active' => $t->revoked_at === null && (!$t->expires_at || self::utcTs($t->expires_at) > time()),
            'last_used_at' => $t->last_used_at ? Carbon::parse($t->last_used_at, 'UTC')->toIso8601String() : null,
            'expires_at' => $t->expires_at ? Carbon::parse($t->expires_at, 'UTC')->toIso8601String() : null,
            'created_at' => Carbon::parse($t->created_at, 'UTC')->toIso8601String(),
        ])->all();
    }

    /** @return array{id:int,name:string,token:string} التوكن بيظهر مرة واحدة بس. */
    public function createToken(int $tenantId, string $name, ?int $createdBy, ?int $expiresInDays): array
    {
        $name = mb_substr(trim($name), 0, 80);
        if ($name === '') {
            throw new \DomainException('Name is required.', 422);
        }
        if (DB::table('scim_tokens')->where('tenant_id', $tenantId)->whereNull('revoked_at')->count() >= self::MAX_TOKENS) {
            throw new \DomainException('Too many active SCIM tokens. Revoke one first.', 409);
        }
        $raw = 'scim_' . bin2hex(random_bytes(24));
        $exp = $expiresInDays && $expiresInDays > 0 ? now()->addDays(min($expiresInDays, 3650)) : null;
        $id = DB::table('scim_tokens')->insertGetId(['tenant_id' => $tenantId, 'name' => $name, 'token_hash' => hash('sha256', $raw), 'created_by' => $createdBy, 'expires_at' => $exp, 'created_at' => now()]);
        return ['id' => (int) $id, 'name' => $name, 'token' => $raw];
    }

    public function revokeToken(int $tenantId, int $id): void
    {
        if (!DB::table('scim_tokens')->where('id', $id)->where('tenant_id', $tenantId)->whereNull('revoked_at')->update(['revoked_at' => now()])) {
            throw new \DomainException('Token not found.', 404);
        }
    }

    /** @return object{tenant_id:int,university_id:int,owner_user_id:int} أو يرمي ScimException 401/403. */
    public function authenticate(?string $bearer): object
    {
        if (!config('identity.scim.enabled', true)) {
            $this->fail(403, 'SCIM is disabled on this server.');
        }
        $bearer = trim((string) $bearer);
        if ($bearer === '' || strlen($bearer) > 200) {
            $this->fail(401, 'Missing bearer token.');
        }
        $t = DB::table('scim_tokens')->where('token_hash', hash('sha256', $bearer))->whereNull('revoked_at')->first();
        if (!$t || ($t->expires_at && self::utcTs($t->expires_at) <= time())) {
            $this->fail(401, 'Invalid or expired token.');
        }
        $tenant = DB::table('mail_tenants')->where('id', $t->tenant_id)->first();
        if (!$tenant || $tenant->status !== 'active') {
            $this->fail(403, 'This university mail service is suspended.');
        }
        if (!$t->last_used_at || self::utcTs($t->last_used_at) < time() - 60) {
            DB::table('scim_tokens')->where('id', $t->id)->update(['last_used_at' => now()]);
        }
        $owner = (int) DB::table('universities')->where('id', $tenant->university_id)->value('user_id');
        return (object) ['tenant_id' => (int) $tenant->id, 'university_id' => (int) $tenant->university_id, 'owner_user_id' => $owner];
    }

    /* ------------------------------------------------------------------ قراءة */

    private function memberUsers(int $uni)
    {
        return DB::table('users as u')->where(fn ($w) => $w
            ->whereIn('u.id', DB::table('students')->where('university_id', $uni)->select('user_id'))
            ->orWhereIn('u.id', DB::table('academic_staff')->where('university_id', $uni)->select('user_id')));
    }

    /** @param int[] $ids @return array<int,array> */
    private function hydrate(array $ids, object $ctx): array
    {
        if (!$ids) {
            return [];
        }
        $users = DB::table('users')->whereIn('id', $ids)->get()->keyBy('id');
        $stu = DB::table('students')->whereIn('user_id', $ids)->where('university_id', $ctx->university_id)->get()->keyBy('user_id');
        $stf = DB::table('academic_staff')->whereIn('user_id', $ids)->where('university_id', $ctx->university_id)->get()->keyBy('user_id');
        $ext = DB::table('scim_identities')->where('tenant_id', $ctx->tenant_id)->whereIn('user_id', $ids)->pluck('external_id', 'user_id');
        $mb = DB::table('mail_accounts')->where('tenant_id', $ctx->tenant_id)->where('kind', 'institutional')->whereIn('user_id', $ids)->get()->groupBy('user_id');
        $out = [];
        foreach ($ids as $id) {
            $u = $users[$id] ?? null;
            if (!$u) {
                continue;
            }
            $isStaff = isset($stf[$id]) && !isset($stu[$id]);
            $m = $stu[$id] ?? $stf[$id] ?? null;
            $box = ($mb[$id] ?? collect())->first();
            $active = !in_array($u->status, ['suspended', 'banned'], true) && $u->deleted_at === null && (!$isStaff || ($m->status ?? 'active') === 'active');
            $en = trim((string) ($u->name_en ?? ''));
            $parts = $en !== '' ? preg_split('/\s+/', $en) : [];
            $r = [
                'schemas' => [self::CORE, self::EXT],
                'id' => (string) $id,
                'userName' => $u->email,
                'name' => array_filter(['formatted' => $u->full_name, 'givenName' => $parts[0] ?? null, 'familyName' => count($parts) > 1 ? end($parts) : null]) ?: (object) [],
                'displayName' => $u->full_name,
                'emails' => [['value' => $u->email, 'primary' => true, 'type' => 'work']],
                'userType' => $isStaff ? 'staff' : 'student',
                'active' => $active,
                'meta' => ['resourceType' => 'User', 'created' => !empty($u->created_at) ? Carbon::parse($u->created_at, 'UTC')->toIso8601String() : null, 'location' => url("/api/scim/v2/Users/{$id}")],
                self::EXT => array_filter([
                    'nameAr' => $u->name_ar ?? null, 'nameEn' => $u->name_en ?? null,
                    'studentNumber' => $isStaff ? null : ($m->student_number ?? null), 'staffNumber' => $isStaff ? ($m->staff_number ?? null) : null,
                    'facultyId' => $m->faculty_id ?? null, 'departmentId' => $m->department_id ?? null,
                    'mailAddress' => $box->address ?? null, 'mailStatus' => $box->status ?? null,
                    'membershipExpiresAt' => !empty($box->membership_expires_at) ? Carbon::parse($box->membership_expires_at, 'UTC')->toIso8601String() : null,
                ], fn ($v) => $v !== null),
            ];
            if (isset($ext[$id]) && $ext[$id] !== null) {
                $r['externalId'] = $ext[$id];
            }
            $out[$id] = $r;
        }
        return $out;
    }

    public function get(object $ctx, int $userId): array
    {
        if ($userId < 1 || !$this->memberUsers($ctx->university_id)->where('u.id', $userId)->exists()) {
            $this->fail(404, 'User not found.');
        }
        return $this->hydrate([$userId], $ctx)[$userId];
    }

    /** @return array{total:int,items:array} */
    public function list(object $ctx, ?string $filter, int $startIndex, int $count): array
    {
        $q = $this->memberUsers($ctx->university_id);
        if ($filter !== null && trim($filter) !== '') {
            if (!preg_match('/^\s*(userName|externalId|emails\.value|id)\s+eq\s+"([^"]*)"\s*$/i', $filter, $m)) {
                $this->fail(400, 'Unsupported filter. Use: userName|externalId|emails.value|id eq "value".', 'invalidFilter');
            }
            $val = $m[2];
            switch (strtolower($m[1])) {
                case 'externalid':
                    $q->whereIn('u.id', DB::table('scim_identities')->where('tenant_id', $ctx->tenant_id)->where('external_id', $val)->select('user_id'));
                    break;
                case 'id':
                    $q->where('u.id', ctype_digit($val) ? (int) $val : 0);
                    break;
                default:
                    $q->whereRaw('LOWER(u.email) = ?', [mb_strtolower($val)]);
            }
        }
        $total = (clone $q)->count();
        $count = max(0, min(200, $count));
        $ids = $count === 0 ? [] : $q->orderBy('u.id')->offset(max(0, $startIndex - 1))->limit($count)->pluck('u.id')->map(fn ($x) => (int) $x)->all();
        return ['total' => $total, 'items' => array_values($this->hydrate($ids, $ctx))];
    }

    /* ------------------------------------------------------------------ كتابة */

    private static function boolish($v): ?bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_string($v) && in_array(strtolower($v), ['true', 'false'], true)) {
            return strtolower($v) === 'true';
        }
        return null;
    }

    private function link(object $ctx, int $userId, $externalId): void
    {
        $ext = ($externalId === null || $externalId === '') ? null : mb_substr((string) $externalId, 0, 190);
        if ($ext !== null && DB::table('scim_identities')->where('tenant_id', $ctx->tenant_id)->where('external_id', $ext)->where('user_id', '!=', $userId)->exists()) {
            $this->fail(409, 'externalId is already used by another user.', 'uniqueness');
        }
        $has = DB::table('scim_identities')->where('tenant_id', $ctx->tenant_id)->where('user_id', $userId)->exists();
        if ($has) {
            DB::table('scim_identities')->where('tenant_id', $ctx->tenant_id)->where('user_id', $userId)->update(['external_id' => $ext]);
        } else {
            DB::table('scim_identities')->insert(['tenant_id' => $ctx->tenant_id, 'user_id' => $userId, 'external_id' => $ext, 'created_at' => now()]);
        }
    }

    /** POST /Users */
    public function create(object $ctx, array $b): array
    {
        $email = mb_strtolower(trim((string) ($b['userName'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->fail(400, 'userName must be a valid email address.', 'invalidValue');
        }
        if (DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            $this->fail(409, 'A user with this userName already exists.', 'uniqueness');
        }
        $type = strtolower((string) ($b['userType'] ?? 'student'));
        if (!in_array($type, ['student', 'staff'], true)) {
            $this->fail(400, 'userType must be student or staff.', 'invalidValue');
        }
        $x = (array) ($b[self::EXT] ?? []);
        $display = trim((string) ($b['displayName'] ?? ($b['name']['formatted'] ?? '')));
        $nameAr = isset($x['nameAr']) ? trim((string) $x['nameAr']) : null;
        $nameEn = isset($x['nameEn']) ? trim((string) $x['nameEn']) : null;
        if ($display === '' && !($nameAr && $nameEn)) {
            $this->fail(400, 'displayName (or the extension attributes nameAr and nameEn) is required.', 'invalidValue');
        }
        $faculty = isset($x['facultyId']) && is_numeric($x['facultyId']) ? (int) $x['facultyId'] : null;
        $dept = isset($x['departmentId']) && is_numeric($x['departmentId']) ? (int) $x['departmentId'] : null;
        if ($faculty && !DB::table('faculties')->where('id', $faculty)->where('university_id', $ctx->university_id)->exists()) {
            $this->fail(400, 'facultyId does not belong to this university.', 'invalidValue');
        }
        $expiry = $x['membershipExpiresAt'] ?? null;
        if ($expiry !== null && $expiry !== '' && !strtotime((string) $expiry)) {
            $this->fail(400, 'membershipExpiresAt must be an ISO 8601 date.', 'invalidValue');
        }

        if ($type === 'student') {
            $res = $this->students->invite($ctx->university_id, $ctx->owner_user_id, $display ?: (string) $nameEn, $email, ($x['studentNumber'] ?? null) ?: null, $faculty, $dept, null, null, 'en',
                null, null, null, true, $nameAr, $nameEn);
        } else {
            $res = $this->staff->invite($ctx->university_id, $ctx->owner_user_id, $display ?: (string) $nameEn, $email, $faculty, $dept, null, ($x['staffNumber'] ?? null) ?: null, null, null, 'en', $nameAr, $nameEn);
        }
        if (empty($res['success'])) {
            $this->fail(400, (string) ($res['message'] ?? 'Could not create the user.'), 'invalidValue');
        }
        $uid = (int) DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->value('id');
        if (!$uid) {
            $this->fail(500, 'The user was created but could not be loaded.');
        }
        $this->link($ctx, $uid, $b['externalId'] ?? null);
        $this->applyExpiry($ctx, $uid, $x, array_key_exists('membershipExpiresAt', $x));
        if (self::boolish($b['active'] ?? true) === false) {
            $this->setActive($ctx, $uid, false);
        }
        DB::table('provisioning_runs')->insert(['tenant_id' => $ctx->tenant_id, 'university_id' => $ctx->university_id, 'kind' => 'scim', 'member_type' => $type, 'total' => 1, 'created' => 1, 'failed' => 0, 'created_at' => now()]);
        return $this->hydrate([$uid], $ctx)[$uid];
    }

    /** PUT /Users/{id} و PATCH (بعد ما يتحوّل لخريطة سمات). @param array<string,mixed> $attrs */
    public function apply(object $ctx, int $userId, array $attrs): array
    {
        $this->get($ctx, $userId);   // 404 لو مش عضو في الجامعة
        if (array_key_exists('externalId', $attrs)) {
            $this->link($ctx, $userId, $attrs['externalId']);
        }
        $ext = (array) ($attrs[self::EXT] ?? []);
        foreach ($attrs as $k => $v) {   // path كامل: urn:...:User:membershipExpiresAt
            if (str_starts_with((string) $k, self::EXT . ':')) {
                $ext[substr((string) $k, strlen(self::EXT) + 1)] = $v;
            }
        }
        if (array_key_exists('membershipExpiresAt', $ext)) {
            $this->applyExpiry($ctx, $userId, $ext, true);
        }
        if (array_key_exists('active', $attrs)) {
            $a = self::boolish($attrs['active']);
            if ($a === null) {
                $this->fail(400, 'active must be true or false.', 'invalidValue');
            }
            $this->setActive($ctx, $userId, $a);
        }
        return $this->hydrate([$userId], $ctx)[$userId];
    }

    /** PATCH: Operations → خريطة سمات (بيقبل value نصية "True"/"False" زي Entra). */
    public function patchToAttrs(array $body): array
    {
        $ops = $body['Operations'] ?? $body['operations'] ?? null;
        if (!is_array($ops) || !$ops) {
            $this->fail(400, 'Operations is required.', 'invalidSyntax');
        }
        $attrs = [];
        foreach ($ops as $op) {
            $kind = strtolower((string) ($op['op'] ?? ''));
            if (!in_array($kind, ['add', 'replace', 'remove'], true)) {
                $this->fail(400, 'Unsupported patch op.', 'invalidSyntax');
            }
            $path = isset($op['path']) ? (string) $op['path'] : null;
            $val = $kind === 'remove' ? null : ($op['value'] ?? null);
            if ($path !== null && $path !== '') {
                $attrs[$path] = $val;
            } elseif (is_array($val)) {
                foreach ($val as $k => $v) {
                    $attrs[$k] = $v;
                }
            }
        }
        return $attrs;
    }

    private function applyExpiry(object $ctx, int $userId, array $ext, bool $present): void
    {
        if (!$present) {
            return;
        }
        $val = $ext['membershipExpiresAt'] ?? null;
        if ($val !== null && $val !== '' && !strtotime((string) $val)) {
            $this->fail(400, 'membershipExpiresAt must be an ISO 8601 date.', 'invalidValue');
        }
        $ids = DB::table('mail_accounts')->where('tenant_id', $ctx->tenant_id)->where('kind', 'institutional')->where('user_id', $userId)->pluck('id');
        foreach ($ids as $id) {
            $this->membership->setExpiry($ctx->university_id, (int) $id, $val === '' ? null : ($val === null ? null : (string) $val));
        }
    }

    /** تفعيل/تعطيل: حساب المنصة + الصندوق المؤسسي + قطع كل الجلسات عند التعطيل. */
    public function setActive(object $ctx, int $userId, bool $active): void
    {
        $stu = DB::table('students')->where('user_id', $userId)->where('university_id', $ctx->university_id)->value('id');
        $stf = DB::table('academic_staff')->where('user_id', $userId)->where('university_id', $ctx->university_id)->value('id');
        if ($stu) {
            $active ? $this->students->activate($stu, $ctx->university_id, $ctx->owner_user_id) : $this->students->deactivate($stu, $ctx->university_id, $ctx->owner_user_id);
        }
        if ($stf) {
            $active ? $this->staff->activate($stf, $ctx->university_id, $ctx->owner_user_id) : $this->staff->deactivate($stf, $ctx->university_id, $ctx->owner_user_id);
        }
        $boxes = DB::table('mail_accounts')->where('tenant_id', $ctx->tenant_id)->where('kind', 'institutional')->where('user_id', $userId);
        if ($active) {
            (clone $boxes)->where('status', 'suspended')->where('suspended_by', 'membership')->where('suspended_reason', 'account_suspended')
                ->update(['status' => 'active', 'suspended_reason' => null, 'suspended_by' => null, 'updated_at' => now()]);
        } else {
            (clone $boxes)->where('status', 'active')->update(['status' => 'suspended', 'suspended_reason' => 'account_suspended', 'suspended_by' => 'membership', 'updated_at' => now()]);
            $this->revoker->revokeAll($userId, 'scim_deactivated', $ctx->owner_user_id);
        }
    }
}
