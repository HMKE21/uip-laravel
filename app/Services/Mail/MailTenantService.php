<?php

namespace App\Services\Mail;

use App\Models\Mail\MailAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * UIPE Mail — المرحلة 4: Multi-Tenant للجامعات.
 * tenant لكل جامعة: نطاقات، سياسات (حصص، حد المرفقات، حد المستلمين، نطاق إرسال الطلاب)، إيقاف الجامعة كلها،
 * وصناديق مؤسسية للطلاب والموظفين والكليات والجامعة نفسها (provisionMembers).
 * بيرمي \DomainException(message, httpCode) لأخطاء الاستخدام (الكونترولر بيحوّلها لـ apiError).
 */
class MailTenantService
{
    public const MEMBER_TYPES = ['student', 'staff', 'faculty', 'university'];
    public const MAX_PROVISION = 2000;
    public const MAX_DOMAINS = 5;
    private const RESERVED = ['postmaster', 'abuse', 'admin', 'administrator', 'root', 'webmaster', 'hostmaster', 'noreply', 'no-reply', 'mailer-daemon', 'support', 'security'];

    private function fail(string $msg, int $code = 422): never
    {
        throw new \DomainException($msg, $code);
    }

    private static function iso($v): ?string
    {
        return $v ? Carbon::parse($v, 'UTC')->toIso8601String() : null;
    }

    /* ------------------------------------------------------------------ tenant & policies */

    public function tenantByUniversity(int $universityId, bool $create = true): ?object
    {
        $t = DB::table('mail_tenants')->where('university_id', $universityId)->first();
        if (!$t && $create) {
            if (!DB::table('universities')->where('id', $universityId)->exists()) {
                $this->fail('University not found.', 404);
            }
            $now = now();
            DB::table('mail_tenants')->insertOrIgnore(['university_id' => $universityId, 'created_at' => $now, 'updated_at' => $now]);
            $t = DB::table('mail_tenants')->where('university_id', $universityId)->first();
        }
        return $t;
    }

    public function tenantPayload(object $t): array
    {
        return [
            'id' => (int) $t->id, 'university_id' => (int) $t->university_id, 'status' => $t->status, 'suspended_reason' => $t->suspended_reason,
            'policies' => [
                'quota_student_mb' => (int) $t->quota_student_mb, 'quota_staff_mb' => (int) $t->quota_staff_mb, 'quota_admin_mb' => (int) $t->quota_admin_mb,
                'max_attachment_mb' => (int) $t->max_attachment_mb, 'max_recipients' => (int) $t->max_recipients,
                'student_send_scope' => $t->student_send_scope, 'allow_external' => (bool) $t->allow_external,
                // المرحلة 5
                'auto_provision' => (bool) ($t->auto_provision ?? true), 'suspend_on_expiry' => (bool) ($t->suspend_on_expiry ?? true),
                'graduate_grace_days' => (int) ($t->graduate_grace_days ?? 180),
            ],
        ];
    }

    /** tenant + نطاقاته + إحصائيات (عدد الصناديق حسب النوع، المستخدم من التخزين). */
    public function overview(int $universityId): array
    {
        $t = $this->tenantByUniversity($universityId);
        $uni = DB::table('universities')->where('id', $universityId)->first(['id', 'official_name_ar', 'official_name_en']);
        $byType = DB::table('mail_accounts')->where('tenant_id', $t->id)->selectRaw('member_type, status, COUNT(*) as c')->groupBy('member_type', 'status')->get();
        $stats = ['accounts' => 0, 'suspended' => 0, 'by_type' => array_fill_keys(self::MEMBER_TYPES, 0)];
        foreach ($byType as $r) {
            $stats['accounts'] += (int) $r->c;
            if ($r->status !== 'active') {
                $stats['suspended'] += (int) $r->c;
            }
            if (isset($stats['by_type'][$r->member_type])) {
                $stats['by_type'][$r->member_type] += (int) $r->c;
            }
        }
        $ids = DB::table('mail_accounts')->where('tenant_id', $t->id)->pluck('id')->map(fn ($x) => (int) $x)->all();
        $stats['used_bytes'] = array_sum($this->usageForAccounts($ids));
        return ['tenant' => $this->tenantPayload($t), 'university' => ['id' => (int) $uni->id, 'name_ar' => $uni->official_name_ar, 'name_en' => $uni->official_name_en],
            'domains' => $this->domains($t), 'stats' => $stats];
    }

    public function updatePolicies(int $universityId, array $in): array
    {
        $t = $this->tenantByUniversity($universityId);
        $upd = [];
        foreach (['quota_student_mb' => [1, 102400], 'quota_staff_mb' => [1, 102400], 'quota_admin_mb' => [1, 102400], 'max_attachment_mb' => [1, 50], 'max_recipients' => [1, 200]] as $k => [$lo, $hi]) {
            if (array_key_exists($k, $in)) {
                $v = (int) $in[$k];
                if ($v < $lo || $v > $hi) {
                    $this->fail("{$k} must be between {$lo} and {$hi}.");
                }
                $upd[$k] = $v;
            }
        }
        if (array_key_exists('student_send_scope', $in)) {
            if (!in_array($in['student_send_scope'], ['platform', 'university'], true)) {
                $this->fail('student_send_scope must be platform or university.');
            }
            $upd['student_send_scope'] = $in['student_send_scope'];
        }
        if (array_key_exists('allow_external', $in)) {
            $upd['allow_external'] = filter_var($in['allow_external'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        // المرحلة 5: الـ Provisioning التلقائي وسياسة انتهاء العضوية (من غير الـ migration بتاعتها الأعمدة مش موجودة → بنتجاهلها).
        if (\Illuminate\Support\Facades\Schema::hasColumn('mail_tenants', 'auto_provision')) {
            foreach (['auto_provision', 'suspend_on_expiry'] as $k) {
                if (array_key_exists($k, $in)) {
                    $upd[$k] = filter_var($in[$k], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                }
            }
            if (array_key_exists('graduate_grace_days', $in)) {
                $v = (int) $in['graduate_grace_days'];
                if ($v < 0 || $v > 3650) {
                    $this->fail('graduate_grace_days must be between 0 and 3650.');
                }
                $upd['graduate_grace_days'] = $v;
            }
        }
        if ($upd) {
            DB::table('mail_tenants')->where('id', $t->id)->update($upd + ['updated_at' => now()]);
        }
        return $this->tenantPayload(DB::table('mail_tenants')->where('id', $t->id)->first());
    }

    /** إيقاف/تشغيل الجامعة كلها (أدمن المنصة). */
    public function setTenantStatus(int $universityId, bool $suspend, ?string $reason = null): array
    {
        $t = $this->tenantByUniversity($universityId);
        DB::table('mail_tenants')->where('id', $t->id)->update([
            'status' => $suspend ? 'suspended' : 'active', 'suspended_reason' => $suspend ? Str::limit((string) $reason, 190, '') : null, 'updated_at' => now(),
        ]);
        return $this->tenantPayload(DB::table('mail_tenants')->where('id', $t->id)->first());
    }

    /** كل الـ tenants للأدمن (مع عدد الصناديق والنطاق الأساسي). */
    public function allTenants(?string $q = null, int $page = 1, int $perPage = 25): array
    {
        $qb = DB::table('mail_tenants as t')->join('universities as u', 'u.id', '=', 't.university_id');
        if ($q !== null && trim($q) !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim($q)) . '%';
            $qb->where(fn ($w) => $w->where('u.official_name_en', 'like', $like)->orWhere('u.official_name_ar', 'like', $like));
        }
        $total = (clone $qb)->count();
        $rows = $qb->orderBy('u.official_name_en')->forPage(max(1, $page), $perPage)->get(['t.*', 'u.official_name_ar', 'u.official_name_en']);
        $counts = DB::table('mail_accounts')->whereIn('tenant_id', $rows->pluck('id')->all())->selectRaw('tenant_id, COUNT(*) as c')->groupBy('tenant_id')->pluck('c', 'tenant_id');
        $primary = DB::table('mail_domains')->whereIn('tenant_id', $rows->pluck('id')->all())->where('is_primary', 1)->pluck('domain', 'tenant_id');
        $pending = DB::table('mail_domains')->whereIn('tenant_id', $rows->pluck('id')->all())->where('status', 'pending')->selectRaw('tenant_id, COUNT(*) as c')->groupBy('tenant_id')->pluck('c', 'tenant_id');
        return ['total' => $total, 'page' => max(1, $page), 'per_page' => $perPage, 'items' => $rows->map(fn ($r) => $this->tenantPayload($r) + [
            'name_ar' => $r->official_name_ar, 'name_en' => $r->official_name_en, 'accounts' => (int) ($counts[$r->id] ?? 0),
            'primary_domain' => $primary[$r->id] ?? null, 'pending_domains' => (int) ($pending[$r->id] ?? 0),
        ])->values()->all()];
    }

    /* ------------------------------------------------------------------ domains */

    private function cleanDomain(string $d): string
    {
        return ltrim(strtolower(trim($d)), '@');
    }

    private function validDomain(string $d): bool
    {
        return (bool) preg_match('/^(?=.{3,120}$)([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $d);
    }

    public function domains(object $t): array
    {
        return DB::table('mail_domains')->where('tenant_id', $t->id)->orderByDesc('is_primary')->orderBy('id')->get()->map(fn ($d) => [
            'id' => (int) $d->id, 'domain' => $d->domain, 'is_primary' => (bool) $d->is_primary, 'status' => $d->status, 'activated_at' => self::iso($d->activated_at),
            'accounts' => (int) DB::table('mail_accounts')->where('domain_id', $d->id)->count(),
        ])->all();
    }

    /** النطاق الجديد بيبدأ pending لحد ما أدمن المنصة يفعّله (التحقق من الـ DNS بيتركّب مع Stalwart لاحقًا). */
    public function addDomain(int $universityId, string $domain): array
    {
        $t = $this->tenantByUniversity($universityId);
        $d = $this->cleanDomain($domain);
        if (!$this->validDomain($d)) {
            $this->fail('Invalid domain (e.g. uni.edu.eg).');
        }
        if ($d === strtolower((string) config('mailbox.domain', 'uipe.local'))) {
            $this->fail('This domain is reserved by the platform.');
        }
        if (DB::table('mail_domains')->where('domain', $d)->exists()) {
            $this->fail('This domain is already registered.', 409);
        }
        if (DB::table('mail_domains')->where('tenant_id', $t->id)->count() >= self::MAX_DOMAINS) {
            $this->fail('Too many domains.');
        }
        $now = now();
        $id = DB::table('mail_domains')->insertGetId(['tenant_id' => $t->id, 'domain' => $d, 'is_primary' => 0, 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now]);
        return collect($this->domains($t))->firstWhere('id', (int) $id);
    }

    /** تفعيل/تعطيل نطاق (أدمن المنصة). تعطيل الـ primary مرفوض. */
    public function setDomainStatus(int $domainId, string $status): array
    {
        if (!in_array($status, ['active', 'disabled'], true)) {
            $this->fail('Invalid status.');
        }
        $d = DB::table('mail_domains')->where('id', $domainId)->first();
        if (!$d) {
            $this->fail('Domain not found.', 404);
        }
        if ($status === 'disabled' && $d->is_primary) {
            $this->fail('Choose another primary domain first.', 409);
        }
        DB::table('mail_domains')->where('id', $domainId)->update(['status' => $status, 'activated_at' => $status === 'active' ? ($d->activated_at ?: now()) : $d->activated_at, 'updated_at' => now()]);
        $t = DB::table('mail_tenants')->where('id', $d->tenant_id)->first();
        return collect($this->domains($t))->firstWhere('id', $domainId);
    }

    /** جعل نطاق (مفعّل) هو الـ primary، وتزامن universities.email_domain عشان الإيميل الجامعي القديم يفضل شغال. */
    public function setPrimary(int $universityId, int $domainId): array
    {
        $t = $this->tenantByUniversity($universityId);
        $d = DB::table('mail_domains')->where('id', $domainId)->where('tenant_id', $t->id)->first();
        if (!$d) {
            $this->fail('Domain not found.', 404);
        }
        if ($d->status !== 'active') {
            $this->fail('The domain must be activated first.', 409);
        }
        $oldDomain = DB::table('universities')->where('id', $t->university_id)->value('email_domain');
        DB::transaction(function () use ($t, $d, $oldDomain) {
            DB::table('mail_domains')->where('tenant_id', $t->id)->update(['is_primary' => 0]);
            DB::table('mail_domains')->where('id', $d->id)->update(['is_primary' => 1, 'updated_at' => now()]);
            DB::table('universities')->where('id', $t->university_id)->update(['email_domain' => $d->domain]);
            // عناوين التحويل القديمة للطلاب (university_email) تتبع النطاق الأساسي الجديد.
            if ($oldDomain && $oldDomain !== $d->domain) {
                app(\App\Services\UniversityEmailService::class)->repoint((int) $t->university_id, $d->domain);
            }
        });
        return $this->domains($t);
    }

    public function removeDomain(int $universityId, int $domainId): void
    {
        $t = $this->tenantByUniversity($universityId);
        $d = DB::table('mail_domains')->where('id', $domainId)->where('tenant_id', $t->id)->first();
        if (!$d) {
            $this->fail('Domain not found.', 404);
        }
        if ($d->is_primary) {
            $this->fail('You cannot remove the primary domain.', 409);
        }
        if (DB::table('mail_accounts')->where('domain_id', $d->id)->exists()) {
            $this->fail('This domain still has mailboxes.', 409);
        }
        DB::table('mail_domains')->where('id', $domainId)->delete();
    }

    /* ------------------------------------------------------------------ quotas & policies at runtime */

    public function statusOf(?int $tenantId): ?string
    {
        if (!$tenantId) {
            return null;
        }
        return (string) DB::table('mail_tenants')->where('id', $tenantId)->value('status') ?: null;
    }

    /** IDs الـ tenants الموقوفة (لاستبعاد صناديقها من التسليم). */
    public function suspendedTenantIds(): array
    {
        return DB::table('mail_tenants')->where('status', 'suspended')->pluck('id')->map(fn ($x) => (int) $x)->all();
    }

    public function policyFor(MailAccount $a): ?object
    {
        return $a->tenant_id ? DB::table('mail_tenants')->where('id', $a->tenant_id)->first() : null;
    }

    /** سبب منع الكتابة على الصندوق (موقوف/الجامعة موقوفة) أو null. */
    public function blockReason(MailAccount $a): ?string
    {
        if ($this->statusOf($a->tenant_id ? (int) $a->tenant_id : null) === 'suspended') {
            return 'Your university mail service is suspended.';
        }
        if ($a->status !== 'active') {
            return 'Your mailbox is suspended.';
        }
        return null;
    }

    public function quotaMb(MailAccount $a, ?object $t = null): ?int
    {
        if ($a->quota_mb) {
            return (int) $a->quota_mb;
        }
        $t ??= $this->policyFor($a);
        if (!$t) {
            return null;
        }
        return (int) match ($a->member_type) {
            'student' => $t->quota_student_mb,
            'staff' => $t->quota_staff_mb,
            default => $t->quota_admin_mb,
        };
    }

    /** @return array<int,int> account_id → bytes (نص الرسائل + مرفقاتها + المرفقات المرفوعة ولسه ما اتبعتتش). */
    public function usageForAccounts(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $out = array_fill_keys($ids, 0);
        $bodies = DB::table('mail_items as i')->join('mail_messages as m', 'm.id', '=', 'i.message_id')->whereIn('i.account_id', $ids)
            ->selectRaw('i.account_id as acc, SUM(COALESCE(LENGTH(m.body_html), 0) + COALESCE(LENGTH(m.body_text), 0)) as bytes')->groupBy('i.account_id')->pluck('bytes', 'acc');
        $linked = DB::table('mail_items as i')->join('mail_attachments as at', 'at.message_id', '=', 'i.message_id')->whereIn('i.account_id', $ids)
            ->selectRaw('i.account_id as acc, SUM(at.size) as bytes')->groupBy('i.account_id')->pluck('bytes', 'acc');
        $loose = DB::table('mail_attachments')->whereIn('account_id', $ids)->whereNull('message_id')->selectRaw('account_id as acc, SUM(size) as bytes')->groupBy('account_id')->pluck('bytes', 'acc');
        foreach ($ids as $id) {
            $out[$id] = (int) ($bodies[$id] ?? 0) + (int) ($linked[$id] ?? 0) + (int) ($loose[$id] ?? 0);
        }
        return $out;
    }

    /** للصندوق المؤسسي بس؛ الشخصي مفيهوش حصة (null). */
    public function quotaPayload(MailAccount $a): ?array
    {
        $mb = $this->quotaMb($a);
        if ($mb === null) {
            return null;
        }
        $used = $this->usageForAccounts([(int) $a->id])[(int) $a->id] ?? 0;
        $limit = $mb * 1048576;
        return ['limit_mb' => $mb, 'used_bytes' => $used, 'percent' => $limit > 0 ? min(100, (int) round($used * 100 / $limit)) : 0, 'full' => $used >= $limit];
    }

    public function isFull(MailAccount $a): bool
    {
        $q = $this->quotaPayload($a);
        return $q !== null && $q['full'];
    }

    /** حد المرفق الفعلي للصندوق (سياسة الجامعة أو الإعداد العام). */
    public function maxAttachmentMb(MailAccount $a): int
    {
        $t = $this->policyFor($a);
        return $t ? (int) $t->max_attachment_mb : (int) config('mailbox.max_attachment_mb', 20);
    }

    public function maxRecipients(MailAccount $a): int
    {
        $t = $this->policyFor($a);
        return $t ? (int) $t->max_recipients : (int) config('mailbox.max_recipients', 50);
    }

    /**
     * سياسات المرسل على المستلمين: الطالب لجامعته بس / منع الخارجي. $accountsByAddress: address → MailAccount.
     * بيرمي 403 مع رسالة واضحة.
     */
    public function assertMayEmail(MailAccount $sender, array $addresses, \Illuminate\Support\Collection $accountsByAddress): void
    {
        $t = $this->policyFor($sender);
        if (!$t) {
            return;
        }
        foreach ($addresses as $addr) {
            $acc = $accountsByAddress->get($addr);
            if (!$acc && !$t->allow_external) {
                $this->fail("Your university allows sending inside UIPE only ({$addr}).", 403);
            }
            if ($sender->member_type === 'student' && $t->student_send_scope === 'university' && (!$acc || (int) $acc->tenant_id !== (int) $sender->tenant_id)) {
                $this->fail("Students can only email addresses inside their university ({$addr}).", 403);
            }
        }
    }

    /* ------------------------------------------------------------------ members & accounts (admin) */

    /** @return array{total:int,page:int,per_page:int,items:array} */
    public function members(int $universityId, array $f, ?int $facultyId = null): array
    {
        $t = $this->tenantByUniversity($universityId);
        $q = DB::table('mail_accounts as a')->join('users as u', 'u.id', '=', 'a.user_id')->where('a.tenant_id', $t->id);
        if (!empty($f['type']) && in_array($f['type'], self::MEMBER_TYPES, true)) {
            $q->where('a.member_type', $f['type']);
        }
        if (!empty($f['status']) && in_array($f['status'], ['active', 'suspended'], true)) {
            $q->where('a.status', $f['status']);
        }
        if ($facultyId) { // أدمن الكلية: صناديق طلاب وموظفي كليته بس
            $q->where(function ($w) use ($facultyId) {
                $w->where(fn ($x) => $x->where('a.member_type', 'student')->whereIn('a.member_id', DB::table('students')->where('faculty_id', $facultyId)->select('id')))
                    ->orWhere(fn ($x) => $x->where('a.member_type', 'staff')->whereIn('a.member_id', DB::table('academic_staff')->where('faculty_id', $facultyId)->select('id')))
                    ->orWhere(fn ($x) => $x->where('a.member_type', 'faculty')->where('a.member_id', $facultyId));
            });
        }
        if (!empty($f['q'])) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $f['q'])) . '%';
            $q->where(fn ($w) => $w->where('a.address', 'like', $like)->orWhere('a.display_name', 'like', $like)->orWhere('u.full_name', 'like', $like));
        }
        $per = max(1, min(100, (int) ($f['per_page'] ?? 25)));
        $page = max(1, (int) ($f['page'] ?? 1));
        $total = (clone $q)->count();
        $rows = $q->orderBy('a.member_type')->orderBy('a.address')->forPage($page, $per)->get(['a.*', 'u.full_name', 'u.email as personal_email']);
        $usage = $this->usageForAccounts($rows->pluck('id')->all());
        $items = $rows->map(function ($r) use ($usage, $t) {
            $acc = (new MailAccount())->forceFill((array) $r);
            $mb = $this->quotaMb($acc, $t);
            return ['id' => (int) $r->id, 'address' => $r->address, 'display_name' => $r->display_name, 'full_name' => $r->full_name, 'personal_email' => $r->personal_email,
                'member_type' => $r->member_type, 'member_id' => $r->member_id ? (int) $r->member_id : null, 'status' => $r->status, 'suspended_reason' => $r->suspended_reason, 'suspended_by' => $r->suspended_by ?? null, 'membership_expires_at' => !empty($r->membership_expires_at) ? \Illuminate\Support\Carbon::parse($r->membership_expires_at, 'UTC')->toIso8601String() : null,
                'quota_mb' => $r->quota_mb ? (int) $r->quota_mb : null, 'effective_quota_mb' => $mb, 'used_bytes' => (int) ($usage[(int) $r->id] ?? 0)];
        })->values()->all();
        return ['total' => $total, 'page' => $page, 'per_page' => $per, 'items' => $items];
    }

    /** تعديل صندوق: حصة خاصة، اسم العرض، إيقاف/تشغيل، تغيير الجزء المحلي من العنوان. */
    public function updateAccount(int $universityId, int $accountId, array $in): array
    {
        $t = $this->tenantByUniversity($universityId);
        $a = MailAccount::where('id', $accountId)->where('tenant_id', $t->id)->first();
        if (!$a) {
            $this->fail('Mailbox not found.', 404);
        }
        $upd = [];
        if (array_key_exists('status', $in)) {
            if (!in_array($in['status'], ['active', 'suspended'], true)) {
                $this->fail('Invalid status.');
            }
            $upd['status'] = $in['status'];
            $upd['suspended_reason'] = $in['status'] === 'suspended' ? Str::limit((string) ($in['suspended_reason'] ?? ''), 190, '') ?: null : null;
            if (\Illuminate\Support\Facades\Schema::hasColumn('mail_accounts', 'suspended_by')) {
                $upd['suspended_by'] = null;   // قرار الأدمن اليدوي: المزامنة التلقائية متلمسوش
            }
        }
        if (array_key_exists('quota_mb', $in)) {
            if ($in['quota_mb'] === null || $in['quota_mb'] === '') {
                $upd['quota_mb'] = null;
            } else {
                $v = (int) $in['quota_mb'];
                if ($v < 1 || $v > 102400) {
                    $this->fail('quota_mb must be between 1 and 102400.');
                }
                $upd['quota_mb'] = $v;
            }
        }
        if (array_key_exists('display_name', $in)) {
            $upd['display_name'] = Str::limit(trim((string) $in['display_name']), 150, '') ?: null;
        }
        if (!empty($in['local'])) {
            $local = strtolower(trim((string) $in['local']));
            if (!preg_match('/^[a-z0-9]([a-z0-9._-]{0,62}[a-z0-9])?$/', $local)) {
                $this->fail('Invalid name (letters, digits, dot, dash only).');
            }
            if (in_array($local, self::RESERVED, true)) {
                $this->fail('This name is reserved.', 409);
            }
            $domain = $a->domain_id ? (string) DB::table('mail_domains')->where('id', $a->domain_id)->value('domain') : '';
            if ($domain === '') {
                $this->fail('This mailbox has no domain.', 409);
            }
            $addr = "{$local}@{$domain}";
            if (MailAccount::where('address', $addr)->where('id', '!=', $a->id)->exists()) {
                $this->fail('That address is already taken.', 409);
            }
            $upd['address'] = $addr;
        }
        if ($upd) {
            DB::transaction(function () use ($a, $upd) {
                $a->forceFill($upd)->save();
                if (isset($upd['address']) && $a->member_type === 'student') {
                    DB::table('students')->where('id', $a->member_id)->update(['university_email' => $upd['address']]);
                }
            });
        }
        return $this->accountRow($a->fresh(), $t);
    }

    private function accountRow(MailAccount $a, object $t): array
    {
        $usage = $this->usageForAccounts([(int) $a->id]);
        return ['id' => (int) $a->id, 'address' => $a->address, 'display_name' => $a->display_name, 'member_type' => $a->member_type, 'status' => $a->status,
            'suspended_reason' => $a->suspended_reason, 'quota_mb' => $a->quota_mb ? (int) $a->quota_mb : null, 'effective_quota_mb' => $this->quotaMb($a, $t),
            'used_bytes' => (int) ($usage[(int) $a->id] ?? 0)];
    }

    /* ------------------------------------------------------------------ provisioning */

    /** الجزء المحلي (ASCII) من اسم عربي/إنجليزي: first.last */
    private function nameLocal(string $fullName): string
    {
        $parts = Str::of(Str::ascii($fullName))->lower()->replaceMatches('/[^a-z\s]/', '')->trim()->explode(' ')->filter()->values();
        if ($parts->count() >= 2) {
            return $parts->first() . '.' . $parts->last();
        }
        return $parts->count() === 1 ? (string) $parts->first() : '';
    }

    private function cleanLocal(string $s): string
    {
        return strtolower(trim(preg_replace('/[^a-z0-9._-]+/i', '', $s), '._-'));
    }

    private function localFor(string $type, object $m, string $pattern): string
    {
        $base = '';
        if ($type === 'student') {
            $number = $this->cleanLocal((string) ($m->student_number ?? ''));
            $base = $pattern === 'name' ? ($this->nameLocal((string) $m->full_name) ?: $number) : ($number ?: $this->nameLocal((string) $m->full_name));
            $base = $base ?: 'student' . $m->id;
        } elseif ($type === 'staff') {
            $base = $this->nameLocal((string) $m->full_name) ?: $this->cleanLocal((string) ($m->staff_number ?? '')) ?: 'staff' . $m->id;
        } elseif ($type === 'faculty') {
            $base = $this->cleanLocal((string) ($m->slug ?? '')) ?: 'faculty' . $m->id;
        } else {
            $base = 'office';
        }
        $base = Str::limit($this->cleanLocal($base) ?: $type . $m->id, 60, '');
        return in_array($base, self::RESERVED, true) ? $base . '.' . $m->id : $base;
    }

    /** يجيب الأعضاء الصالحين للتوليد حسب النوع (مع $facultyId اختياري لتضييق النطاق). */
    private function memberQuery(string $type, int $universityId, ?int $facultyId)
    {
        switch ($type) {
            case 'student':
                $q = DB::table('students as s')->join('users as u', 'u.id', '=', 's.user_id')->where('s.university_id', $universityId)
                    ->select('s.id', 's.user_id', 's.student_number', 's.university_email', 'u.full_name');
                return $facultyId ? $q->where('s.faculty_id', $facultyId) : $q;
            case 'staff':
                $q = DB::table('academic_staff as a')->join('users as u', 'u.id', '=', 'a.user_id')->where('a.university_id', $universityId)->where('a.status', 'active')
                    ->select('a.id', 'a.user_id', 'a.staff_number', 'u.full_name');
                return $facultyId ? $q->where('a.faculty_id', $facultyId) : $q;
            case 'faculty':
                $q = DB::table('faculties as f')->join('users as u', 'u.id', '=', 'f.user_id')->where('f.university_id', $universityId)->whereNotNull('f.user_id')
                    ->select('f.id', 'f.user_id', 'f.slug', 'f.name_en', 'f.name_ar', 'u.full_name');
                return $facultyId ? $q->where('f.id', $facultyId) : $q;
            default:
                return DB::table('universities as un')->join('users as u', 'u.id', '=', 'un.user_id')->where('un.id', $universityId)
                    ->select('un.id', 'un.user_id', 'un.official_name_en', 'un.official_name_ar', 'u.full_name');
        }
    }

    /**
     * ينشئ صناديق مؤسسية للأعضاء اللي ماعندهمش (idempotent). لازم يكون فيه نطاق primary مفعّل وtenant شغال.
     * @param string[] $types  student|staff|faculty|university
     * @return array{created:int,skipped:int,failed:int,remaining:int,blocked:int,by_type:array}
     */
    public function provisionMembers(int $universityId, array $types, ?int $facultyId = null, ?int $onlyUserId = null): array
    {
        $types = array_values(array_intersect(self::MEMBER_TYPES, $types));
        if (!$types) {
            $this->fail('Choose at least one member type.');
        }
        $t = $this->tenantByUniversity($universityId);
        if ($t->status !== 'active') {
            $this->fail('The university mail service is suspended.', 403);
        }
        $dom = DB::table('mail_domains')->where('tenant_id', $t->id)->where('is_primary', 1)->where('status', 'active')->first();
        if (!$dom) {
            $this->fail('Add a domain and ask the platform admin to activate it first.', 409);
        }
        $pattern = (string) (DB::table('universities')->where('id', $universityId)->value('email_pattern') ?: 'number');
        $taken = array_flip(DB::table('mail_accounts')->where('address', 'like', '%@' . $dom->domain)->pluck('address')->all());
        $res = ['created' => 0, 'skipped' => 0, 'failed' => 0, 'remaining' => 0, 'blocked' => 0, 'by_type' => []];
        $access = app(MailAccessService::class);   // إدارة الوصول: أدوار ممنوع يتعملها صندوق
        $budget = self::MAX_PROVISION;
        $now = now();

        foreach ($types as $type) {
            $res['by_type'][$type] = 0;
            $done = DB::table('mail_accounts')->where('tenant_id', $t->id)->where('member_type', $type)->pluck('member_id')->all();
            $existing = array_flip(array_map('intval', $done));
            $pk = ['student' => 's.id', 'staff' => 'a.id', 'faculty' => 'f.id', 'university' => 'un.id'][$type];
            $mq = $this->memberQuery($type, $universityId, $facultyId);
            if ($onlyUserId) {   // المرحلة 5: إنشاء صندوق عضو واحد (تسجيل/دعوة/SCIM)
                $mq->where(['student' => 's.user_id', 'staff' => 'a.user_id', 'faculty' => 'f.user_id', 'university' => 'un.user_id'][$type], $onlyUserId);
            }
            $rows = $mq->orderBy($pk)->get();
            foreach ($rows as $m) {
                if (isset($existing[(int) $m->id])) {
                    $res['skipped']++;
                    continue;
                }
                if ($access->createBlockReason((int) $m->user_id, $universityId) !== null) {
                    $res['blocked']++;
                    continue;
                }
                if ($budget <= 0) {
                    $res['remaining']++;
                    continue;
                }
                $local = null;
                if ($type === 'student' && !empty($m->university_email) && strcasecmp(substr(strrchr($m->university_email, '@'), 1), $dom->domain) === 0 && !isset($taken[strtolower($m->university_email)])) {
                    $addr = strtolower($m->university_email); // نحترم العنوان اللي الجامعة ولّدته قبل كده
                } else {
                    $base = $this->localFor($type, $m, $pattern);
                    $local = $base;
                    $i = 1;
                    while (isset($taken[$local . '@' . $dom->domain])) {
                        $i++;
                        $local = $base . $i;
                    }
                    $addr = $local . '@' . $dom->domain;
                }
                $name = $type === 'faculty' ? ($m->name_en ?: $m->name_ar ?: $m->full_name) : ($type === 'university' ? ($m->official_name_en ?: $m->full_name) : $m->full_name);
                try {
                    DB::transaction(function () use ($m, $type, $t, $dom, $universityId, $addr, $name, $now) {
                        DB::table('mail_accounts')->insert(['user_id' => $m->user_id, 'address' => $addr, 'kind' => 'institutional', 'university_id' => $universityId,
                            'tenant_id' => $t->id, 'domain_id' => $dom->id, 'member_type' => $type, 'member_id' => $m->id, 'display_name' => Str::limit((string) $name, 150, ''),
                            'is_default' => 0, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
                        if ($type === 'student') {
                            DB::table('students')->where('id', $m->id)->update(['university_email' => $addr]);
                        }
                    });
                    $taken[$addr] = true;
                    $res['created']++;
                    $res['by_type'][$type]++;
                    $budget--;
                } catch (\Throwable $e) {
                    $res['failed']++;
                }
            }
        }
        return $res;
    }
}
