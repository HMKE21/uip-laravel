<?php

namespace App\Services\Mail;

use App\Models\Mail\MailAccount;
use App\Models\User;
use App\Services\Developer\DevEventBus;
use App\Support\MailHtmlSanitizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** UIPE Mail — المرحلة 1: حسابات، إرسال داخلي، مسودات، صناديق، تصنيفات، مرفقات. المرحلة 3: Snooze، جدولة، Undo Send، سرّي، فلاتر، رد تلقائي. */
class MailboxService
{
    private const EXT_ERR = 'External delivery failed. Check the Brevo settings.';

    public const FOLDERS = ['inbox', 'sent', 'drafts', 'scheduled', 'archive', 'spam', 'trash'];

    public function __construct(private ExternalMailGateway $external, private MailFilterEngine $filters, private MailTenantService $tenants,
        private MailAuditService $audit, private MailSecurityService $security)
    {
    }

    /** يرمي \DomainException(message, httpCode) لأخطاء الاستخدام. */
    private function fail(string $msg, int $code = 422): never
    {
        throw new \DomainException($msg, $code);
    }

    /* ------------------------------------------------------------------ accounts */

    /** $accountId (اختياري): نختار صندوق معيّن من صناديق نفس المستخدم (شخصي/مؤسسي). لو مش بتاعه → 404. */
    public function accountFor(int $userId, ?int $accountId = null): MailAccount
    {
        if ($accountId) {
            $own = MailAccount::where('user_id', $userId)->where('id', $accountId)->first();
            if (!$own) {
                $this->fail('Mailbox not found.', 404);
            }
            return $own;
        }
        $acc = MailAccount::where('user_id', $userId)->orderByDesc('is_default')->orderBy('id')->first();
        if ($acc) {
            return $acc;
        }
        $user = User::find($userId);
        if (!$user) {
            $this->fail('User not found.', 404);
        }
        $domain = strtolower((string) config('mailbox.domain', 'uipe.local'));
        $local = Str::slug(Str::before((string) $user->email, '@'), '.') ?: 'user';
        $local = Str::limit($local, 40, '');
        $address = "{$local}@{$domain}";
        if (MailAccount::where('address', $address)->exists()) {
            $address = "{$local}.{$userId}@{$domain}";
        }
        try {
            return MailAccount::create([
                'user_id' => $userId, 'address' => $address, 'kind' => 'personal',
                'display_name' => $user->full_name, 'is_default' => true, 'status' => 'active',
            ]);
        } catch (\Throwable $e) { // سباق: طلبين متوازيين أول مرة
            return MailAccount::where('user_id', $userId)->firstOrFail();
        }
    }

    public function accountPayload(MailAccount $a): array
    {
        return ['id' => (int) $a->id, 'address' => $a->address, 'kind' => $a->kind, 'display_name' => $a->display_name, 'status' => $a->status,
            'university_id' => $a->university_id ? (int) $a->university_id : null, 'is_default' => (bool) $a->is_default,
            'tenant_id' => $a->tenant_id ? (int) $a->tenant_id : null, 'member_type' => $a->member_type, 'suspended_reason' => $a->suspended_reason,
            'tenant_status' => $this->tenants->statusOf($a->tenant_id ? (int) $a->tenant_id : null),
            'external_enabled' => $this->external->enabled() && (!$a->tenant_id || (bool) ($this->tenants->policyFor($a)->allow_external ?? true))];
    }

    /** كل صناديق المستخدم (الافتراضي أولًا) + عدد غير المقروء في الوارد لكل صندوق — لمبدّل الحسابات في الواجهة. */
    /** سبب منع الكتابة (صندوق موقوف أو الجامعة موقوفة) أو null. */
    public function blockReason(MailAccount $a): ?string
    {
        return $this->tenants->blockReason($a);
    }

    /** الحصة والمستخدم للصندوق المؤسسي (null للشخصي). */
    public function quotaPayload(MailAccount $a): ?array
    {
        return $this->tenants->quotaPayload($a);
    }

    public function accountsFor(int $userId): array
    {
        $this->accountFor($userId); // يتأكد إن فيه صندوق واحد على الأقل (بيتنشأ أول مرة)
        $accs = MailAccount::where('user_id', $userId)->orderByDesc('is_default')->orderBy('id')->get();
        $unread = DB::table('mail_items')->whereIn('account_id', $accs->pluck('id')->all())->where('folder', 'inbox')->where('is_read', 0)->whereNull('snoozed_until')
            ->groupBy('account_id')->selectRaw('account_id, COUNT(*) as c')->pluck('c', 'account_id');
        return $accs->map(fn ($a) => $this->accountPayload($a) + ['inbox_unread' => (int) ($unread[$a->id] ?? 0)])->values()->all();
    }

    /* ------------------------------------------------------------------ folders & lists */

    public function folderCounts(MailAccount $a): array
    {
        $rows = DB::table('mail_items')->where('account_id', $a->id)->whereNull('snoozed_until')
            ->selectRaw('folder, COUNT(*) as total, SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread')->groupBy('folder')->get()->keyBy('folder');
        $out = [];
        foreach (self::FOLDERS as $f) {
            $r = $rows->get($f);
            $out[$f] = ['total' => (int) ($r->total ?? 0), 'unread' => (int) ($r->unread ?? 0)];
        }
        $out['starred'] = ['total' => (int) DB::table('mail_items')->where('account_id', $a->id)->where('is_starred', 1)->whereNotIn('folder', ['trash', 'spam'])->count(), 'unread' => 0];
        $out['snoozed'] = ['total' => (int) DB::table('mail_items')->where('account_id', $a->id)->where('folder', 'inbox')->whereNotNull('snoozed_until')->count(), 'unread' => 0];
        return $out;
    }

    /**
     * @param array{folder?:string,label_id?:int,q?:string,starred?:bool,unread?:bool,view?:string,page?:int,per_page?:int} $f
     */
    public function list(MailAccount $a, array $f): array
    {
        $per = max(1, min(50, (int) ($f['per_page'] ?? config('mailbox.per_page', 25))));
        $page = max(1, (int) ($f['page'] ?? 1));
        $folder = $f['folder'] ?? 'inbox';
        $starredView = $folder === 'starred' || !empty($f['starred']);
        $snoozedView = !$starredView && $folder === 'snoozed';
        // 'all' = كل البريد ما عدا السلة والمزعج — مسموح بس مع label_id أو بحث (q)، عشان تصنيف/بحث يعدّي على كل الصناديق.
        $allView = !$starredView && $folder === 'all' && (!empty($f['label_id']) || trim((string) ($f['q'] ?? '')) !== '');
        if (!$starredView && !$allView && !$snoozedView && !in_array($folder, self::FOLDERS, true)) {
            $this->fail('Unknown folder.');
        }
        $scope = function ($qq, string $p) use ($starredView, $allView, $snoozedView, $folder) {
            if ($starredView) {
                $qq->where("{$p}.is_starred", 1)->whereNotIn("{$p}.folder", ['trash', 'spam', 'outbox']);
            } elseif ($allView) {
                $qq->whereNotIn("{$p}.folder", ['trash', 'spam', 'outbox']);
            } elseif ($snoozedView) {
                $qq->where("{$p}.folder", 'inbox')->whereNotNull("{$p}.snoozed_until");
            } else {
                $qq->where("{$p}.folder", $folder);
                if ($folder === 'inbox') { // المؤجّل مش بيظهر في الوارد لحد ما يصحى
                    $qq->whereNull("{$p}.snoozed_until");
                }
            }
        };

        $q = DB::table('mail_items as i')->join('mail_messages as m', 'm.id', '=', 'i.message_id')->join('mail_accounts as s', 's.id', '=', 'm.sender_account_id')
            ->where('i.account_id', $a->id);
        $scope($q, 'i');
        if (!empty($f['unread'])) {
            $q->where('i.is_read', 0);
        }
        if (!empty($f['label_id'])) {
            $q->whereExists(fn ($s) => $s->select(DB::raw(1))->from('mail_item_labels as il')->whereColumn('il.item_id', 'i.id')->where('il.label_id', (int) $f['label_id']));
        }
        $term = trim((string) ($f['q'] ?? ''));
        if ($term !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
            $q->where(function ($w) use ($like) {
                // نص الرسائل السرية اللي وصلتك مش بيتبحث فيه
                $w->where('m.subject', 'like', $like)
                    ->orWhere(fn ($x) => $x->where('m.body_text', 'like', $like)->where(fn ($y) => $y->where('m.confidential', 0)->orWhereColumn('m.sender_account_id', 'i.account_id')))
                    ->orWhere('s.address', 'like', $like)->orWhere('s.display_name', 'like', $like)
                    ->orWhere('m.ext_from_address', 'like', $like)->orWhere('m.ext_from_name', 'like', $like)
                    ->orWhereExists(fn ($s) => $s->select(DB::raw(1))->from('mail_recipients as r')->whereColumn('r.message_id', 'm.id')->where('r.kind', '!=', 'bcc')->where(fn ($x) => $x->where('r.address', 'like', $like)->orWhere('r.name', 'like', $like)));
            });
        }
        $threads = ($f['view'] ?? 'messages') === 'threads';
        if ($threads) {
            $latest = DB::table('mail_items as i2')->join('mail_messages as m2', 'm2.id', '=', 'i2.message_id')->where('i2.account_id', $a->id);
            $scope($latest, 'i2');
            $latest->groupBy('m2.thread_id')->selectRaw('MAX(i2.id)');
            $q->whereIn('i.id', $latest);
        }

        $total = (clone $q)->count();
        $q = $snoozedView ? $q->orderBy('i.snoozed_until')->orderBy('i.id') : $q->orderByDesc('i.sort_at')->orderByDesc('i.id');
        $rows = $q->forPage($page, $per)
            ->get(['i.id', 'i.folder', 'i.is_read', 'i.is_starred', 'i.sort_at', 'i.snoozed_until', 'm.id as message_id', 'm.thread_id', 'm.subject', 'm.snippet', 'm.is_draft',
                'm.send_state', 'm.scheduled_at', 'm.schedule_error', 'm.confidential', 'm.sender_account_id', 'm.ext_from_address', 'm.ext_from_name',
                's.address as from_address', 's.display_name as from_name']);

        $ids = $rows->pluck('id')->all();
        $labels = $this->labelsForItems($ids);
        $atts = DB::table('mail_attachments')->whereIn('message_id', $rows->pluck('message_id')->all() ?: [0])->select('message_id', DB::raw('COUNT(*) as c'))->groupBy('message_id')->pluck('c', 'message_id');
        $counts = [];
        if ($threads && $rows->isNotEmpty()) {
            $counts = DB::table('mail_items as i3')->join('mail_messages as m3', 'm3.id', '=', 'i3.message_id')->where('i3.account_id', $a->id)
                ->whereIn('m3.thread_id', $rows->pluck('thread_id')->all())->groupBy('m3.thread_id')->select('m3.thread_id', DB::raw('COUNT(*) as c'))->pluck('c', 'thread_id');
        }
        $recipients = DB::table('mail_recipients')->whereIn('message_id', $rows->pluck('message_id')->all() ?: [0])->where('kind', 'to')->get(['message_id', 'address', 'name'])->groupBy('message_id');

        return [
            'items' => $rows->map(function ($r) use ($a, $recipients, $atts, $threads, $counts, $labels) {
                $hide = $r->confidential && (int) $r->sender_account_id !== (int) $a->id; // السرية: مفيش snippet في القائمة
                $ext = (string) $r->ext_from_address;
                return [
                    'id' => (int) $r->id, 'message_id' => (int) $r->message_id, 'thread_id' => (int) $r->thread_id, 'folder' => $r->folder,
                    'subject' => $r->subject, 'snippet' => $hide ? null : $r->snippet, 'is_read' => (bool) $r->is_read, 'is_starred' => (bool) $r->is_starred, 'is_draft' => (bool) $r->is_draft,
                    'send_state' => $r->send_state, 'scheduled_at' => self::iso($r->scheduled_at), 'schedule_error' => $r->schedule_error,
                    'snoozed_until' => self::iso($r->snoozed_until), 'confidential' => (bool) $r->confidential,
                    'date' => self::iso($r->sort_at), 'from' => ['address' => $ext !== '' ? $ext : $r->from_address, 'name' => $ext !== '' ? $r->ext_from_name : $r->from_name],
                    'to' => ($recipients[$r->message_id] ?? collect())->map(fn ($x) => ['address' => $x->address, 'name' => $x->name])->values(),
                    'has_attachments' => ($atts[$r->message_id] ?? 0) > 0, 'thread_count' => $threads ? (int) ($counts[$r->thread_id] ?? 1) : null,
                    'labels' => $labels[$r->id] ?? [],
                ];
            })->values(),
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $per, 'pages' => (int) ceil($total / $per)],
        ];
    }

    /** تاريخ UTC من الداتابيز → ISO-8601 بالـ offset (الفرونت بيحوّله لتوقيت جهاز المستخدم صح). */
    private static function iso($v): ?string
    {
        return $v ? Carbon::parse($v, 'UTC')->toIso8601String() : null;
    }

    private function labelsForItems(array $itemIds): array
    {
        if (!$itemIds) {
            return [];
        }
        $out = [];
        foreach (DB::table('mail_item_labels as il')->join('mail_labels as l', 'l.id', '=', 'il.label_id')->whereIn('il.item_id', $itemIds)->get(['il.item_id', 'l.id', 'l.name', 'l.color']) as $r) {
            $out[$r->item_id][] = ['id' => (int) $r->id, 'name' => $r->name, 'color' => $r->color];
        }
        return $out;
    }

    /* ------------------------------------------------------------------ reading */

    private function ownItem(MailAccount $a, int $itemId): object
    {
        $it = DB::table('mail_items')->where('id', $itemId)->where('account_id', $a->id)->first();
        if (!$it) {
            $this->fail('Message not found.', 404);
        }
        return $it;
    }

    public function show(MailAccount $a, int $itemId, bool $markRead = true): array
    {
        $it = $this->ownItem($a, $itemId);
        if ($markRead && !$it->is_read && $it->folder !== 'drafts') {
            DB::table('mail_items')->where('id', $it->id)->update(['is_read' => 1]);
            $it->is_read = 1;
        }
        return $this->payload($a, $it);
    }

    public function thread(MailAccount $a, int $threadId): array
    {
        $items = DB::table('mail_items as i')->join('mail_messages as m', 'm.id', '=', 'i.message_id')->where('i.account_id', $a->id)->where('m.thread_id', $threadId)
            ->orderBy('i.sort_at')->orderBy('i.id')->get(['i.*']);
        if ($items->isEmpty()) {
            $this->fail('Conversation not found.', 404);
        }
        DB::table('mail_items')->whereIn('id', $items->where('is_read', 0)->where('folder', '!=', 'drafts')->pluck('id'))->update(['is_read' => 1]);
        return $items->map(fn ($it) => $this->payload($a, $it))->values()->all();
    }

    /** حالة قفل الرسالة السرية عند المستلم: null (مفتوحة) | revoked | expired | passcode. صاحب الرسالة دايمًا مفتوحة. */
    private function confLock(object $m, object $it, MailAccount $a): ?string
    {
        if (!$m->confidential || (int) $m->sender_account_id === (int) $a->id) {
            return null;
        }
        if ($m->conf_revoked_at) {
            return 'revoked';
        }
        if ($m->conf_expires_at && now()->gte(Carbon::parse($m->conf_expires_at, 'UTC'))) {
            return 'expired';
        }
        if ($m->conf_passcode && !Cache::has($this->unlockKey((int) $it->id))) {
            return 'passcode';
        }
        return null;
    }

    private function unlockKey(int $itemId): string
    {
        return 'mailconf:unlock:' . $itemId;
    }

    private function payload(MailAccount $a, object $it): array
    {
        $m = DB::table('mail_messages')->where('id', $it->message_id)->first();
        $sender = DB::table('mail_accounts')->where('id', $m->sender_account_id)->first(['address', 'display_name']);
        $isSender = (int) $m->sender_account_id === (int) $a->id;
        $rc = DB::table('mail_recipients')->where('message_id', $m->id)->orderBy('id')->get()
            ->filter(fn ($r) => $r->kind !== 'bcc' || $isSender || (int) $r->account_id === (int) $a->id); // Bcc بيظهر للمرسل وصاحب الـ Bcc بس
        $labels = $this->labelsForItems([$it->id])[$it->id] ?? [];
        $lock = $this->confLock($m, $it, $a);
        $atts = $lock ? collect() : DB::table('mail_attachments')->where('message_id', $m->id)->get(['id', 'name', 'mime', 'size'])
            ->map(fn ($x) => ['id' => (int) $x->id, 'name' => $x->name, 'mime' => $x->mime, 'size' => (int) $x->size])->values();
        $ext = (string) $m->ext_from_address;
        $conf = null;
        if ($m->confidential) {
            $conf = ['on' => true, 'mine' => $isSender, 'locked' => $lock, 'passcode' => (bool) $m->conf_passcode, 'expires_days' => $m->conf_days ? (int) $m->conf_days : null,
                'expires_at' => self::iso($m->conf_expires_at), 'revoked' => (bool) $m->conf_revoked_at];
        }
        return [
            'id' => (int) $it->id, 'message_id' => (int) $m->id, 'thread_id' => (int) $m->thread_id, 'folder' => $it->folder,
            'subject' => $m->subject, 'body_html' => $lock ? null : $m->body_html, 'body_text' => $lock ? null : $m->body_text, 'is_draft' => (bool) $m->is_draft,
            'send_state' => $m->send_state, 'scheduled_at' => self::iso($m->scheduled_at), 'schedule_error' => $m->schedule_error,
            'snoozed_until' => self::iso($it->snoozed_until ?? null), 'is_auto' => (bool) $m->is_auto, 'is_imported' => (bool) $m->is_imported, 'confidential' => $conf,
            'is_read' => (bool) $it->is_read, 'is_starred' => (bool) $it->is_starred, 'date' => self::iso($it->sort_at),
            'in_reply_to_message_id' => $m->in_reply_to_message_id ? (int) $m->in_reply_to_message_id : null,
            'from' => $ext !== '' ? ['address' => $ext, 'name' => $m->ext_from_name] : ['address' => $sender->address ?? '', 'name' => $sender->display_name ?? null],
            'to' => $rc->where('kind', 'to')->map(fn ($r) => ['address' => $r->address, 'name' => $r->name])->values(),
            'cc' => $rc->where('kind', 'cc')->map(fn ($r) => ['address' => $r->address, 'name' => $r->name])->values(),
            'bcc' => $rc->where('kind', 'bcc')->map(fn ($r) => ['address' => $r->address, 'name' => $r->name])->values(),
            'labels' => $labels, 'attachments' => $atts,
        ];
    }

    /* ------------------------------------------------------------------ compose: drafts & send */

    /** يحوّل مدخلات المستلمين لقائمة موحّدة [{kind,address,name,account_id}] بعد التحقق. */
    private function resolveRecipients(array $input, bool $forSend, ?MailAccount $sender = null): array
    {
        $out = []; $seen = [];
        foreach (['to', 'cc', 'bcc'] as $kind) {
            foreach ((array) ($input[$kind] ?? []) as $r) {
                $addr = strtolower(trim(is_array($r) ? (string) ($r['address'] ?? '') : (string) $r));
                if ($addr === '') {
                    continue;
                }
                if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    $this->fail("Invalid address: {$addr}");
                }
                if (isset($seen[$addr])) {
                    continue;
                }
                $seen[$addr] = true;
                $out[] = ['kind' => $kind, 'address' => $addr, 'name' => is_array($r) ? Str::limit((string) ($r['name'] ?? ''), 150, '') : null];
            }
        }
        if (count($out) > ($sender ? $this->tenants->maxRecipients($sender) : (int) config('mailbox.max_recipients', 50))) {
            $this->fail('Too many recipients.');
        }
        $accounts = $out ? MailAccount::whereIn('address', array_column($out, 'address'))->get()->keyBy('address') : collect();
        if ($forSend && $out) {
            // العنوان مش صندوق موجود: ممكن يكون إيميل تسجيل دخول مستخدم على المنصة (مثلاً admin@uip.demo) أو مستخدم لسه ما فتحش البريد.
            // نربطه بصندوقه (ونعمله لو لسه مش موجود) بدل ما يتعامل كإيميل خارجي ويضيع.
            $missing = array_values(array_diff(array_column($out, 'address'), $accounts->keys()->all()));
            if ($missing) {
                foreach (DB::table('users')->whereIn(DB::raw('LOWER(email)'), $missing)->get(['id', 'email']) as $u) {
                    try {
                        $accounts->put(strtolower((string) $u->email), $this->accountFor((int) $u->id));
                    } catch (\Throwable $e) {
                        Log::warning('UIPE Mail: could not provision mailbox for recipient', ['user_id' => $u->id, 'error' => $e->getMessage()]);
                    }
                }
            }
        }
        foreach ($out as &$r) {
            $acc = $accounts->get($r['address']);
            $r['account_id'] = $acc?->id;
            if ($forSend && !$acc && $this->isPlatformDomain($r['address'])) {
                $this->fail("لا يوجد صندوق بريد بالعنوان {$r['address']}. / No UIPE mailbox exists for {$r['address']}.", 422);
            }
            if ($forSend && !$acc && !$this->external->enabled()) {
                $this->fail("لا يمكن الإرسال إلى {$r['address']}: البريد الخارجي غير مفعّل بعد، والإرسال متاح داخل المنصة فقط. / External email is not enabled yet; you can only send to UIPE addresses.", 422);
            }
            if ($forSend && $acc && $acc->status !== 'active') {
                $this->fail("{$r['address']} is suspended.", 422);
            }
        }
        unset($r);
        if ($forSend && $out) {
            $suspendedTenants = $this->tenants->suspendedTenantIds();
            foreach ($out as $r) {
                $acc = $accounts->get($r['address']);
                if ($acc && $acc->tenant_id && in_array((int) $acc->tenant_id, $suspendedTenants, true)) {
                    $this->fail("{$r['address']} is suspended.", 422);
                }
                if ($acc && $acc->tenant_id && (int) $acc->id !== (int) ($sender?->id) && $this->tenants->isFull($acc)) {
                    $this->fail("{$r['address']} mailbox is full.", 422);
                }
            }
            if ($sender) {
                $this->tenants->assertMayEmail($sender, array_column($out, 'address'), $accounts);
            }
        }
        if ($forSend && !array_filter($out, fn ($r) => $r['kind'] === 'to')) {
            $this->fail('Add at least one recipient.');
        }
        return $out;
    }

    /** العنوان على نطاق تابع للمنصة (الافتراضي أو نطاق جامعة مفعّل)؟ لو أيوه ومفيش صندوق بيه → مينفعش يتبعت كإيميل خارجي. */
    private function isPlatformDomain(string $address): bool
    {
        $d = strtolower((string) Str::after($address, '@'));
        if ($d === strtolower((string) config('mailbox.domain', 'uipe.local'))) {
            return true;
        }
        try {
            return DB::table('mail_domains')->where('domain', $d)->where('status', 'active')->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function normSubject(string $s): string
    {
        $s = trim($s);
        while (preg_match('/^(re|fw|fwd|رد|إعادة توجيه|اعادة توجيه)\s*:\s*/iu', $s)) {
            $s = preg_replace('/^(re|fw|fwd|رد|إعادة توجيه|اعادة توجيه)\s*:\s*/iu', '', $s, 1);
        }
        return Str::limit(mb_strtolower($s), 240, '');
    }

    /** حقول الرسالة السرية للتخزين. [] = المفتاح مش مبعوت (سيب القديم زي ما هو). */
    private function confidentialFields(array $in, bool $send, array $recipients): array
    {
        if (!array_key_exists('confidential', $in)) {
            return [];
        }
        $c = $in['confidential'];
        if (!is_array($c)) {
            return ['confidential' => 0, 'conf_days' => null, 'conf_passcode' => null, 'conf_expires_at' => null, 'conf_revoked_at' => null];
        }
        if ($send && array_filter($recipients, fn ($r) => empty($r['account_id']))) {
            $this->fail('Confidential mode works only for UIPE addresses.');
        }
        $days = (int) ($c['expires_days'] ?? 0);
        $out = ['confidential' => 1, 'conf_days' => $days > 0 ? min(365, $days) : null, 'conf_expires_at' => null, 'conf_revoked_at' => null];
        $pass = trim((string) ($c['passcode'] ?? ''));
        if ($pass !== '') {
            if (mb_strlen($pass) < 4) {
                $this->fail('The passcode must be at least 4 characters.');
            }
            $out['conf_passcode'] = Hash::make($pass);
        } elseif (empty($c['keep_passcode'])) {
            $out['conf_passcode'] = null;
        }
        return $out;
    }

    /**
     * @param array $in subject, body, to/cc/bcc, attachment_ids, in_reply_to_item_id, forward_of_item_id, confidential
     * @param string $mode draft | send | scheduled | undo   ($holdAt = وقت التسليم لـ scheduled / undo — المسودة بتفضل مسودة لحد وقتها)
     */
    private function writeMessage(MailAccount $a, array $in, string $mode, ?int $draftMessageId, ?Carbon $holdAt = null): int
    {
        $send = $mode !== 'draft';
        $subject = Str::limit(trim((string) ($in['subject'] ?? '')), (int) config('mailbox.max_subject', 250), '');
        $rawBody = (string) ($in['body'] ?? '');
        if (strlen($rawBody) > (int) config('mailbox.max_body_kb', 512) * 1024) {
            $this->fail('Message is too large.');
        }
        $html = MailHtmlSanitizer::clean($rawBody);
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '</p>', '</div>', '</li>'], ' ', $html)), ENT_QUOTES, 'UTF-8')));
        if ($send && $subject === '' && $text === '') {
            $this->fail('Write a subject or a message first.');
        }
        $recipients = $this->resolveRecipients($in, $send, $a);
        if ($send) { // المرحلة 7: حدود الإرسال الساعية + الكشف المشبوه
            $this->security->assertMaySend($a, count($recipients), count(array_filter($recipients, fn ($r) => empty($r['account_id']))));
        }
        $conf = $this->confidentialFields($in, $send, $recipients);

        $replyTo = null; $threadId = null;
        if (!empty($in['in_reply_to_item_id'])) {
            $orig = $this->ownItem($a, (int) $in['in_reply_to_item_id']);
            $replyTo = (int) $orig->message_id;
            $threadId = (int) DB::table('mail_messages')->where('id', $replyTo)->value('thread_id');
        }
        if (!empty($in['forward_of_item_id'])) { // الرسالة السرية اللي وصلتك مينفعش تتحوّل
            $fw = $this->ownItem($a, (int) $in['forward_of_item_id']);
            $fm = DB::table('mail_messages')->where('id', $fw->message_id)->first(['confidential', 'sender_account_id']);
            if ($fm && $fm->confidential && (int) $fm->sender_account_id !== (int) $a->id) {
                $this->fail('Confidential messages cannot be forwarded.', 403);
            }
        }

        return DB::transaction(function () use ($a, $in, $mode, $holdAt, $draftMessageId, $subject, $html, $text, $recipients, $replyTo, $threadId, $conf) {
            $now = now();
            $fields = ['subject' => $subject, 'body_html' => $html, 'body_text' => $text, 'snippet' => Str::limit($text, 180, ''), 'updated_at' => $now];
            if ($draftMessageId) {
                $msg = DB::table('mail_messages')->where('id', $draftMessageId)->where('sender_account_id', $a->id)->where('is_draft', 1)->lockForUpdate()->first();
                if (!$msg) {
                    $this->fail('Draft not found.', 404);
                }
                $mid = (int) $msg->id;
                DB::table('mail_messages')->where('id', $mid)->update($fields + $conf);
                DB::table('mail_recipients')->where('message_id', $mid)->delete();
                $threadId = (int) $msg->thread_id;
            } else {
                if (!$threadId) {
                    $threadId = DB::table('mail_threads')->insertGetId(['subject_norm' => $this->normSubject($subject), 'created_at' => $now, 'updated_at' => $now]);
                }
                $mid = DB::table('mail_messages')->insertGetId($fields + $conf + ['thread_id' => $threadId, 'sender_account_id' => $a->id, 'in_reply_to_message_id' => $replyTo, 'is_draft' => 1, 'created_at' => $now]);
            }
            if ($recipients) {
                DB::table('mail_recipients')->insert(array_map(fn ($r) => $r + ['message_id' => $mid], $recipients));
            }

            // المرفقات: لازم تكون مرفوعة من نفس الحساب ومش مربوطة برسالة تانية.
            $attIds = array_values(array_unique(array_map('intval', (array) ($in['attachment_ids'] ?? []))));
            if (count($attIds) > (int) config('mailbox.max_attachments', 10)) {
                $this->fail('Too many attachments.');
            }
            DB::table('mail_attachments')->where('message_id', $mid)->whereNotIn('id', $attIds ?: [0])->update(['message_id' => null]);
            if ($attIds) {
                $ok = DB::table('mail_attachments')->where('account_id', $a->id)->whereIn('id', $attIds)->where(fn ($q) => $q->whereNull('message_id')->orWhere('message_id', $mid))->pluck('id')->all();
                if (count($ok) !== count($attIds)) {
                    $this->fail('Attachment not found.', 404);
                }
                DB::table('mail_attachments')->whereIn('id', $ok)->update(['message_id' => $mid]);
            }

            if ($mode === 'draft') {
                $exists = DB::table('mail_items')->where('account_id', $a->id)->where('message_id', $mid)->exists();
                if (!$exists) {
                    DB::table('mail_items')->insert(['account_id' => $a->id, 'message_id' => $mid, 'folder' => 'drafts', 'is_read' => 1, 'sort_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
                } else {
                    DB::table('mail_items')->where('account_id', $a->id)->where('message_id', $mid)->update(['sort_at' => $now, 'updated_at' => $now]);
                }
                return $mid;
            }

            if ($mode === 'send') {
                $this->deliver($mid);
                return $mid;
            }

            // scheduled | undo: بنسيبها مسودة بحالة انتظار، والتسليم بيحصل لما الوقت يجي (release / schedule:run).
            DB::table('mail_messages')->where('id', $mid)->update(['send_state' => $mode, 'scheduled_at' => $holdAt, 'schedule_error' => null, 'updated_at' => $now]);
            DB::table('mail_items')->updateOrInsert(['account_id' => $a->id, 'message_id' => $mid],
                ['folder' => $mode === 'scheduled' ? 'scheduled' : 'outbox', 'prev_folder' => null, 'is_read' => 1, 'sort_at' => $holdAt, 'updated_at' => $now, 'created_at' => $now]);
            return $mid;
        });
    }

    /**
     * التسليم الفعلي: المسودة تتحوّل لرسالة مرسلة + نسخة لكل مستلم داخلي (بعد تطبيق فلاتره) + الرد التلقائي لو مفعّل.
     * لازم يتنادى جوه transaction. آمن لو اتنادى مرتين (التاني بيتجاهل).
     */
    private function deliver(int $mid): void
    {
        $msg = DB::table('mail_messages')->where('id', $mid)->lockForUpdate()->first();
        if (!$msg || !$msg->is_draft) {
            return;
        }
        $now = now();
        $upd = ['is_draft' => 0, 'sent_at' => $now, 'send_state' => null, 'scheduled_at' => null, 'schedule_error' => null, 'updated_at' => $now];
        if ($msg->confidential && $msg->conf_days) {
            $upd['conf_expires_at'] = $now->copy()->addDays((int) $msg->conf_days);
        }
        DB::table('mail_messages')->where('id', $mid)->update($upd);
        DB::table('mail_threads')->where('id', $msg->thread_id)->update(['updated_at' => $now]);
        $senderId = (int) $msg->sender_account_id;
        if (!$msg->is_auto) {
            DB::table('mail_items')->updateOrInsert(['account_id' => $senderId, 'message_id' => $mid],
                ['folder' => 'sent', 'prev_folder' => null, 'is_read' => 1, 'sort_at' => $now, 'snoozed_until' => null, 'updated_at' => $now, 'created_at' => $now]);
        }

        $recips = DB::table('mail_recipients')->where('message_id', $mid)->get();
        if (!$msg->is_auto && !$msg->confidential) { // Webhook: mail.message.sent (المرحلة 6) — من غير أجسام رسائل، ومن غير الـ Bcc
            $this->emitDev('mail.message.sent', $senderId, ['message_id' => $mid, 'thread_id' => (int) $msg->thread_id, 'subject' => (string) $msg->subject,
                'to' => $recips->where('kind', '!=', 'bcc')->pluck('address')->values()->all(), 'sent_at' => $now->toIso8601String()]);
        }
        // مستلمين خارجيين (مفيش حساب UIPE ليهم): الإرسال الفعلي بعد ما الـ transaction يتأكد.
        if (!$msg->is_auto && $this->external->enabled() && $recips->whereNull('account_id')->isNotEmpty()) {
            DB::afterCommit(fn () => $this->sendExternal($mid));
        }
        $accIds = $recips->whereNotNull('account_id')->pluck('account_id')->map(fn ($x) => (int) $x)->unique()->values();
        if ($accIds->isEmpty()) {
            return;
        }
        $suspendedTenants = $this->tenants->suspendedTenantIds();
        $active = MailAccount::whereIn('id', $accIds->all())->where('status', 'active')
            ->when($suspendedTenants, fn ($q) => $q->where(fn ($w) => $w->whereNull('tenant_id')->orWhereNotIn('tenant_id', $suspendedTenants)))
            ->pluck('id')->map(fn ($x) => (int) $x)->all();
        $sender = DB::table('mail_accounts')->where('id', $senderId)->first(['address', 'display_name']);
        $hasAtt = DB::table('mail_attachments')->where('message_id', $mid)->exists();
        $visibleTo = $recips->where('kind', '!=', 'bcc')->pluck('address')->all();

        $userOf = MailAccount::whereIn('id', $accIds->all())->pluck('user_id', 'id');
        foreach ($accIds as $accId) {
            if (!in_array($accId, $active, true)) {
                continue;
            }
            $fx = $this->filters->evaluate($accId, ['from' => (string) ($sender->address ?? ''), 'from_name' => (string) ($sender->display_name ?? ''), 'to' => $visibleTo,
                'subject' => (string) $msg->subject, 'text' => (string) $msg->body_text, 'has_attachment' => $hasAtt]);
            $trash = $fx['folder'] === 'trash';
            $itemId = DB::table('mail_items')->insertGetId(['account_id' => $accId, 'message_id' => $mid, 'folder' => $fx['folder'], 'prev_folder' => $trash ? 'inbox' : null,
                'is_read' => $fx['read'] ? 1 : 0, 'is_starred' => $fx['star'] ? 1 : 0, 'sort_at' => $now, 'trashed_at' => $trash ? $now : null, 'created_at' => $now, 'updated_at' => $now]);
            foreach ($fx['labels'] as $lid) {
                DB::table('mail_item_labels')->insertOrIgnore(['item_id' => $itemId, 'label_id' => $lid]);
            }
            if ($fx['folder'] === 'inbox' && $accId !== $senderId && !$msg->is_auto && !$msg->confidential) {
                $this->notifyNewMail((int) ($userOf[$accId] ?? 0), $itemId, (string) ($sender?->display_name ?: $sender?->address ?: ''), (string) $msg->subject, (string) $msg->snippet,
                    $accId, (string) ($sender?->address ?? ''));
            }
            if (!$trash && $accId !== $senderId && !$msg->is_auto && !$msg->confidential) { // Webhook: mail.message.received (المرحلة 6)
                $this->emitDev('mail.message.received', $accId, ['item_id' => $itemId, 'message_id' => $mid, 'thread_id' => (int) $msg->thread_id, 'folder' => $fx['folder'],
                    'from' => ['address' => (string) ($sender->address ?? ''), 'name' => $sender->display_name ?? null], 'subject' => (string) $msg->subject,
                    'snippet' => (string) $msg->snippet, 'has_attachments' => $hasAtt, 'received_at' => $now->toIso8601String()]);
            }
        }

        if (!$msg->is_auto && $sender) {
            foreach ($recips->whereIn('kind', ['to', 'cc'])->whereNotNull('account_id')->pluck('account_id')->map(fn ($x) => (int) $x)->unique() as $accId) {
                if ($accId !== $senderId && in_array($accId, $active, true)) {
                    $this->vacationReply($accId, $msg, (string) $sender->address);
                }
            }
        }
    }

    /** يطلق حدث Webhook للمستخدم صاحب الصندوق بعد ما الـ transaction يتأكد. أي فشل مبيكسرش التسليم. */
    private function emitDev(string $event, int $accountId, array $data): void
    {
        DB::afterCommit(function () use ($event, $accountId, $data) {
            try {
                $uid = (int) DB::table('mail_accounts')->where('id', $accountId)->value('user_id');
                if ($uid > 0) {
                    app(DevEventBus::class)->emit($event, $uid, ['account_id' => $accountId] + $data, $accountId, 'live');
                }
            } catch (\Throwable $e) {
                Log::warning('UIPE Mail: dev event failed', ['event' => $event, 'error' => $e->getMessage()]);
            }
        });
    }

    /** إشعار داخل المنصة (جرس + لحظي) لما تجي رسالة جديدة في الوارد. أي فشل مبيكسرش التسليم. */
    private function notifyNewMail(int $userId, int $itemId, string $from, string $subject, string $snippet, ?int $accountId = null, string $fromAddress = ''): void
    {
        if ($userId <= 0) {
            return;
        }
        if ($accountId && !MailNotificationPrefs::allows($accountId, $fromAddress)) { // إعدادات إشعارات البريد (المرحلة 6): كتم / VIP فقط
            return;
        }
        DB::afterCommit(function () use ($userId, $itemId, $from, $subject, $snippet) {
            try {
                $role = app(\App\Services\RoleService::class)->primaryRoleFor($userId);
                $prefix = ['data_analyst' => 'data-analysis', 'security_admin' => 'security', 'security_officer' => 'security', 'academic_staff' => 'academic-staff'][$role] ?? $role;
                $link = $prefix ? "/{$prefix}/mail/inbox/{$itemId}" : '/mail';
                app(\App\Services\NotificationService::class)->notify(
                    $userId, 'mail_message_received', 'رسالة بريد جديدة من ' . ($from ?: 'UIPE Mail'),
                    $subject !== '' ? $subject : Str::limit($snippet, 100, ''), $link
                );
            } catch (\Throwable $e) {
                Log::warning('UIPE Mail: new-mail notification failed', ['error' => $e->getMessage()]);
            }
        });
    }

    /** سجل حالة الإرسال الخارجي (طابور + إعادة محاولة). */
    private function trackDelivery(int $mid, object $msg, array $set): void
    {
        $now = now();
        DB::table('mail_external_deliveries')->insertOrIgnore(['message_id' => $mid, 'sender_account_id' => (int) $msg->sender_account_id,
            'university_id' => DB::table('mail_accounts')->where('id', $msg->sender_account_id)->value('university_id'), 'status' => 'queued', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('mail_external_deliveries')->where('message_id', $mid)->update($set + ['updated_at' => $now]);
    }

    /** يبعت نسخة الرسالة للمستلمين الخارجيين عبر البوابة. الفشل مبيلغيش الإرسال الداخلي: بيتسجل ويتعاد تلقائيًا (retryExternal). */
    public function sendExternal(int $mid): void
    {
        $msg = null;
        try {
            $msg = DB::table('mail_messages')->where('id', $mid)->first();
            if (!$msg) {
                return;
            }
            $senderAcc = MailAccount::find($msg->sender_account_id);
            $sender = DB::table('mail_accounts')->where('id', $msg->sender_account_id)->first(['address', 'display_name']);
            $rc = DB::table('mail_recipients')->where('message_id', $mid)->whereNull('account_id')->get();
            $skip = $senderAcc ? $this->security->suppressedAmong($senderAcc, $rc->pluck('address')->all()) : [];
            if ($skip) { // عناوين فيها hard bounce/شكوى: منبعتش ليها
                $rc = $rc->reject(fn ($r) => in_array(strtolower((string) $r->address), $skip, true))->values();
                $this->security->event('suppressed_send', 'low', ['university_id' => $senderAcc?->university_id, 'tenant_id' => $senderAcc?->tenant_id, 'account_id' => $senderAcc?->id,
                    'details' => ['skipped' => count($skip), 'message_id' => $mid]], false);
            }
            $pick = fn (string $k) => $rc->where('kind', $k)->map(fn ($r) => ['address' => $r->address, 'name' => $r->name])->values()->all();
            $to = $pick('to'); $cc = $pick('cc'); $bcc = $pick('bcc');
            if (!$to) { // Brevo محتاج To: لو الخارجيين cc/bcc بس نرقّي أول مجموعة
                if ($cc) { $to = $cc; $cc = []; } elseif ($bcc) { $to = $bcc; $bcc = []; }
            }
            if (!$to) {
                $this->trackDelivery($mid, $msg, ['status' => $skip ? 'suppressed' : 'failed', 'recipients' => 0, 'next_retry_at' => null]);
                return;
            }
            $atts = DB::table('mail_attachments')->where('message_id', $mid)->get(['name', 'mime', 'disk', 'path'])->map(fn ($a) => (array) $a)->all();
            $this->external->send([
                'from' => (string) ($sender->address ?? ''), 'from_name' => $sender->display_name ?? null,
                'to' => $to, 'cc' => $cc, 'bcc' => $bcc, 'subject' => (string) $msg->subject,
                'html' => (string) $msg->body_html, 'text' => (string) $msg->body_text, 'attachments' => $atts, 'message_id' => $mid,
            ]);
            $n = $rc->count();
            $prev = (int) DB::table('mail_external_deliveries')->where('message_id', $mid)->value('attempts');
            $this->trackDelivery($mid, $msg, ['status' => 'sent', 'recipients' => $n, 'attempts' => $prev + 1, 'last_error' => null, 'last_attempt_at' => now(), 'next_retry_at' => null]);
            DB::table('mail_messages')->where('id', $mid)->where('schedule_error', self::EXT_ERR)->update(['schedule_error' => null]);
            $this->audit->record('mail.send_external', "External send: {$n} recipient(s)", ['university_id' => $senderAcc?->university_id, 'tenant_id' => $senderAcc?->tenant_id,
                'account_id' => $senderAcc?->id, 'target_type' => 'message', 'target_id' => $mid, 'meta' => ['recipients' => $n]]);
        } catch (\Throwable $e) {
            Log::warning('UIPE Mail: external delivery failed', ['message_id' => $mid, 'error' => $e->getMessage()]);
            DB::table('mail_messages')->where('id', $mid)->update(['schedule_error' => self::EXT_ERR]);
            if ($msg) {
                try {
                    $attempts = (int) DB::table('mail_external_deliveries')->where('message_id', $mid)->value('attempts') + 1;
                    $max = (int) config('mailbox.external_max_attempts', 5);
                    $retry = $attempts < $max ? now()->addMinutes([5, 15, 60, 240][min($attempts - 1, 3)]) : null;
                    $this->trackDelivery($mid, $msg, ['status' => 'failed', 'attempts' => $attempts, 'last_error' => Str::limit($e->getMessage(), 240, ''), 'last_attempt_at' => now(), 'next_retry_at' => $retry]);
                } catch (\Throwable $e2) { /* ignore */ }
            }
        }
    }

    /** يعيد محاولة الإرسال الخارجي الفاشل (backoff 5د/15د/1س/4س، حد أقصى external_max_attempts). */
    public function retryExternal(int $limit = 50): int
    {
        $ids = DB::table('mail_external_deliveries')->where('status', 'failed')->whereNotNull('next_retry_at')->where('next_retry_at', '<=', now())
            ->where('attempts', '<', (int) config('mailbox.external_max_attempts', 5))->orderBy('next_retry_at')->limit($limit)->pluck('message_id');
        foreach ($ids as $mid) {
            DB::table('mail_external_deliveries')->where('message_id', $mid)->update(['next_retry_at' => null]); // يمنع تكرار التنفيذ لو الأمر اشتغل مرتين
            $this->sendExternal((int) $mid);
        }
        return $ids->count();
    }

    /** رد تلقائي واحد لكل مرسل كل 4 أيام (وما بيردش على رسائل تلقائية أو no-reply). */
    private function vacationReply(int $accId, object $msg, string $senderAddress): void
    {
        $v = DB::table('mail_vacation')->where('account_id', $accId)->first();
        if (!$v || !$v->enabled) {
            return;
        }
        $now = now();
        if (($v->starts_at && $now->lt(Carbon::parse($v->starts_at, 'UTC'))) || ($v->ends_at && $now->gt(Carbon::parse($v->ends_at, 'UTC')))) {
            return;
        }
        if (preg_match('/^(no-?reply|do-?not-?reply|mailer-daemon|postmaster)@/i', $senderAddress)) {
            return;
        }
        $addr = strtolower($senderAddress);
        if (DB::table('mail_vacation_log')->where('account_id', $accId)->where('sender_address', $addr)->where('replied_at', '>', $now->copy()->subDays(4))->exists()) {
            return;
        }
        $toAcc = MailAccount::find($msg->sender_account_id);
        if (!$toAcc || $toAcc->status !== 'active') {
            return;
        }
        $subject = trim((string) $v->subject) !== '' ? (string) $v->subject : 'Re: ' . $msg->subject;
        $html = (string) $v->body_html;
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['<br>', '</p>', '</div>'], ' ', $html)), ENT_QUOTES, 'UTF-8')));
        $mid = DB::table('mail_messages')->insertGetId(['thread_id' => $msg->thread_id, 'sender_account_id' => $accId, 'subject' => Str::limit($subject, 250, ''),
            'body_html' => $html, 'body_text' => $text, 'snippet' => Str::limit($text, 180, ''), 'in_reply_to_message_id' => $msg->id, 'is_draft' => 1, 'is_auto' => 1,
            'created_at' => $now, 'updated_at' => $now]);
        DB::table('mail_recipients')->insert(['message_id' => $mid, 'kind' => 'to', 'address' => $toAcc->address, 'name' => $toAcc->display_name, 'account_id' => $toAcc->id]);
        DB::table('mail_vacation_log')->insert(['account_id' => $accId, 'sender_address' => $addr, 'replied_at' => $now]);
        $this->deliver($mid);
    }

    public function saveDraft(MailAccount $a, array $in, ?int $itemId = null): array
    {
        $mid = null;
        if ($itemId) {
            $it = $this->ownItem($a, $itemId);
            if ($it->folder !== 'drafts') {
                $this->fail('This message is not a draft.');
            }
            $mid = (int) $it->message_id;
        }
        $mid = $this->writeMessage($a, $in, 'draft', $mid);
        $item = DB::table('mail_items')->where('account_id', $a->id)->where('message_id', $mid)->first();
        return $this->payload($a, $item);
    }

    private function undoSeconds(MailAccount $a): int
    {
        $v = DB::table('mail_settings')->where('account_id', $a->id)->value('undo_seconds');
        return $v === null ? 10 : max(0, min(30, (int) $v));
    }

    private function parseFuture(?string $v): Carbon
    {
        try {
            $d = Carbon::parse((string) $v)->setTimezone('UTC');
        } catch (\Throwable $e) {
            $this->fail('Pick a valid time.');
        }
        if ($d->lt(now()->addSeconds(30))) {
            $this->fail('Pick a time in the future.');
        }
        if ($d->gt(now()->addYear())) {
            $this->fail('You can pick a date up to one year ahead.');
        }
        return $d;
    }

    /**
     * إرسال. من غير schedule_at: لو إعداد Undo Send > 0 الرسالة بتتحجز (send_state=undo) لحد ما الفرونت يعمل release أو الوقت يعدّي،
     * وفي الفترة دي تقدر تتراجع (cancelSend). مع schedule_at: بتتجدول (send_state=scheduled) وبتتسلّم من schedule:run.
     */
    public function send(MailAccount $a, array $in, ?int $draftItemId = null): array
    {
        if ($reason = $this->tenants->blockReason($a)) {
            $this->fail($reason, 403);
        }
        $mid = null;
        if ($draftItemId) {
            $it = $this->ownItem($a, $draftItemId);
            if ($it->folder !== 'drafts') {
                $this->fail('This message is not a draft.');
            }
            $mid = (int) $it->message_id;
        }
        $mode = 'send'; $holdAt = null;
        if (!empty($in['schedule_at'])) {
            $holdAt = $this->parseFuture((string) $in['schedule_at']);
            $mode = 'scheduled';
        } elseif (($undo = $this->undoSeconds($a)) > 0) {
            $mode = 'undo';
            $holdAt = now()->addSeconds($undo);
        }
        $mid = $this->writeMessage($a, $in, $mode, $mid, $holdAt);
        $q = DB::table('mail_items')->where('account_id', $a->id)->where('message_id', $mid);
        $item = $mode === 'send' ? $q->where('folder', 'sent')->first() : $q->whereIn('folder', ['scheduled', 'outbox'])->first();
        return $this->payload($a, $item);
    }

    /** إلغاء إرسال مجدول أو التراجع عن إرسال لسه محجوز → بترجع مسودة. 409 لو الرسالة اتسلّمت خلاص. */
    public function cancelSend(MailAccount $a, int $itemId): array
    {
        $it = $this->ownItem($a, $itemId);
        DB::transaction(function () use ($a, $it) {
            $m = DB::table('mail_messages')->where('id', $it->message_id)->where('sender_account_id', $a->id)->lockForUpdate()->first();
            if (!$m) {
                $this->fail('Message not found.', 404);
            }
            if (!$m->is_draft || !$m->send_state) {
                $this->fail('Too late — the message was already sent.', 409);
            }
            $now = now();
            DB::table('mail_messages')->where('id', $m->id)->update(['send_state' => null, 'scheduled_at' => null, 'schedule_error' => null, 'updated_at' => $now]);
            DB::table('mail_items')->where('account_id', $a->id)->where('message_id', $m->id)->whereIn('folder', ['scheduled', 'outbox'])
                ->update(['folder' => 'drafts', 'sort_at' => $now, 'updated_at' => $now]);
        });
        $item = DB::table('mail_items')->where('account_id', $a->id)->where('message_id', $it->message_id)->where('folder', 'drafts')->first();
        return $this->payload($a, $item);
    }

    /** الفرونت بينادي عليها لما فترة Undo تخلص (idempotent: لو اتسلّمت أو اتلغت مفيش حاجة بتحصل). */
    public function release(MailAccount $a, int $itemId): array
    {
        $it = $this->ownItem($a, $itemId);
        DB::transaction(function () use ($a, $it) {
            $m = DB::table('mail_messages')->where('id', $it->message_id)->where('sender_account_id', $a->id)->lockForUpdate()->first();
            if (!$m || !$m->is_draft || $m->send_state !== 'undo') {
                return;
            }
            if ($m->scheduled_at && Carbon::parse($m->scheduled_at, 'UTC')->gt(now()->addSeconds(3))) {
                $this->fail('Not yet.', 409);
            }
            $this->recheck($m);
            $this->deliver((int) $m->id);
        });
        $item = DB::table('mail_items')->where('account_id', $a->id)->where('message_id', $it->message_id)->orderBy('id')->first();
        return $this->payload($a, $item);
    }

    private function recheck(object $m): void
    {
        $sender = MailAccount::find($m->sender_account_id);
        if (!$sender || $this->tenants->blockReason($sender)) {
            $this->fail($sender ? (string) $this->tenants->blockReason($sender) : 'Your mailbox is suspended.', 403);
        }
        $bad = DB::table('mail_recipients as r')->join('mail_accounts as x', 'x.id', '=', 'r.account_id')->where('r.message_id', $m->id)->where('x.status', '!=', 'active')->value('r.address');
        if ($bad) {
            $this->fail("{$bad} is suspended.");
        }
        $rc = DB::table('mail_recipients')->where('message_id', $m->id)->selectRaw('COUNT(*) total, SUM(CASE WHEN account_id IS NULL THEN 1 ELSE 0 END) ext')->first();
        $this->security->assertMaySend($sender, (int) ($rc->total ?? 0), (int) ($rc->ext ?? 0));
    }

    /** بيسلّم كل اللي وقته جه (مجدول أو Undo خلصت مدته). $accountId = تسليم رسائل صندوق واحد بس (استدعاء كسول من الـ API). */
    public function processDue(?int $accountId = null, int $limit = 200): array
    {
        $q = DB::table('mail_messages')->where('is_draft', 1)->whereIn('send_state', ['scheduled', 'undo'])->where('scheduled_at', '<=', now());
        if ($accountId) {
            $q->where('sender_account_id', $accountId);
        }
        $sent = 0; $failed = 0;
        foreach ($q->orderBy('scheduled_at')->limit($limit)->pluck('id') as $id) {
            try {
                DB::transaction(function () use ($id) {
                    $m = DB::table('mail_messages')->where('id', $id)->lockForUpdate()->first();
                    if (!$m || !$m->is_draft || !$m->send_state) {
                        return;
                    }
                    $this->recheck($m);
                    $this->deliver((int) $id);
                });
                $sent++;
            } catch (\Throwable $e) { // فشل التسليم → ترجع مسودة ومعاها سبب الفشل
                $failed++;
                $now = now();
                $err = $e instanceof \DomainException ? $e->getMessage() : 'Delivery failed.';
                DB::table('mail_messages')->where('id', $id)->where('is_draft', 1)->update(['send_state' => null, 'scheduled_at' => null, 'schedule_error' => Str::limit($err, 190, ''), 'updated_at' => $now]);
                DB::table('mail_items')->where('message_id', $id)->whereIn('folder', ['scheduled', 'outbox'])->update(['folder' => 'drafts', 'sort_at' => $now, 'updated_at' => $now]);
                if (!$e instanceof \DomainException) {
                    report($e);
                }
            }
        }
        return ['sent' => $sent, 'failed' => $failed];
    }

    /** المؤجّلات اللي وقتها جه ترجع للوارد كغير مقروءة. */
    public function wakeSnoozed(?int $accountId = null): int
    {
        $q = DB::table('mail_items')->whereNotNull('snoozed_until')->where('snoozed_until', '<=', now());
        if ($accountId) {
            $q->where('account_id', $accountId);
        }
        return $q->update(['snoozed_until' => null, 'is_read' => 0, 'sort_at' => now(), 'updated_at' => now()]);
    }

    /* ------------------------------------------------------------------ confidential mode */

    public function unlock(MailAccount $a, int $itemId, string $pass): array
    {
        $it = $this->ownItem($a, $itemId);
        $m = DB::table('mail_messages')->where('id', $it->message_id)->first();
        if (!$m || !$m->confidential || !$m->conf_passcode) {
            return $this->payload($a, $it);
        }
        $key = "mailconf:{$a->id}:{$itemId}";
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->fail('Too many attempts. Try again in a few minutes.', 429);
        }
        if (!Hash::check($pass, (string) $m->conf_passcode)) {
            RateLimiter::hit($key, 300);
            $this->fail('Wrong passcode.');
        }
        RateLimiter::clear($key);
        Cache::put($this->unlockKey($itemId), 1, now()->addMinutes(30));
        return $this->payload($a, $it);
    }

    /** صاحب الرسالة السرية يسحب صلاحية القراءة (أو يرجّعها). */
    public function revoke(MailAccount $a, int $itemId, bool $revoke = true): array
    {
        $it = $this->ownItem($a, $itemId);
        $mine = DB::table('mail_messages')->where('id', $it->message_id)->where('sender_account_id', $a->id)->where('confidential', 1)->exists();
        if (!$mine) {
            $this->fail('This is not a confidential message you sent.', 404);
        }
        DB::table('mail_messages')->where('id', $it->message_id)->update(['conf_revoked_at' => $revoke ? now() : null, 'updated_at' => now()]);
        return $this->payload($a, $it);
    }

    /* ------------------------------------------------------------------ actions */

    /** كل نسخ صندوقك في المحادثات دي (للإجراءات على المحادثة كاملة: أرشفة/حذف/تصنيف…). */
    public function itemIdsForThreads(MailAccount $a, array $threadIds): array
    {
        $threadIds = array_values(array_unique(array_filter(array_map('intval', $threadIds))));
        if (!$threadIds) {
            return [];
        }
        return DB::table('mail_items as i')->join('mail_messages as m', 'm.id', '=', 'i.message_id')->where('i.account_id', $a->id)
            ->whereIn('m.thread_id', $threadIds)->pluck('i.id')->map(fn ($x) => (int) $x)->all();
    }

    /**
     * @param int[] $itemIds
     * @param string $action read|unread|star|unstar|archive|inbox|spam|not_spam|trash|restore|delete_forever|label_add|label_remove|snooze|unsnooze|cancel_send
     */
    public function bulk(MailAccount $a, array $itemIds, string $action, ?int $labelId = null, ?string $until = null): int
    {
        $ids = DB::table('mail_items')->where('account_id', $a->id)->whereIn('id', array_map('intval', $itemIds) ?: [0])->pluck('id')->all();
        if (!$ids) {
            return 0;
        }
        $now = now();
        $q = fn () => DB::table('mail_items')->whereIn('id', $ids);
        switch ($action) {
            case 'read': $q()->where('folder', '!=', 'drafts')->update(['is_read' => 1]); break;
            case 'unread': $q()->where('folder', '!=', 'drafts')->update(['is_read' => 0]); break;
            case 'star': $q()->update(['is_starred' => 1]); break;
            case 'unstar': $q()->update(['is_starred' => 0]); break;
            case 'archive': $q()->whereIn('folder', ['inbox', 'spam'])->update(['folder' => 'archive', 'updated_at' => $now]); break;
            case 'inbox': case 'not_spam':
                $q()->whereIn('folder', ['archive', 'spam'])->update(['folder' => 'inbox', 'updated_at' => $now]); break;
            case 'spam': $q()->where('folder', 'inbox')->update(['folder' => 'spam', 'updated_at' => $now]); break;
            case 'trash': // المجدولة/المحجوزة لازم تتلغي الأول (cancel_send) عشان ما تتسلّمش وهي في السلة
                $q()->whereNotIn('folder', ['trash', 'scheduled', 'outbox'])->update(['prev_folder' => DB::raw('folder'), 'folder' => 'trash', 'trashed_at' => $now, 'updated_at' => $now]); break;
            case 'restore':
                $q()->where('folder', 'trash')->update(['folder' => DB::raw("COALESCE(prev_folder, 'inbox')"), 'prev_folder' => null, 'trashed_at' => null, 'updated_at' => $now]); break;
            case 'delete_forever':
                $this->assertNoLegalHold($a);
                $n = $q()->where('folder', 'trash')->delete();
                $this->purgeOrphans();
                if ($n) {
                    $this->audit->record('mail.delete_forever', "Permanently deleted {$n} message(s)", ['university_id' => $a->university_id, 'tenant_id' => $a->tenant_id, 'actor_user_id' => $a->user_id, 'account_id' => $a->id, 'meta' => ['count' => $n]]);
                }
                break;
            case 'snooze':
                $at = $this->parseFuture($until);
                $q()->where('folder', 'inbox')->update(['snoozed_until' => $at, 'updated_at' => $now]);
                break;
            case 'unsnooze':
                $q()->whereNotNull('snoozed_until')->update(['snoozed_until' => null, 'updated_at' => $now]);
                break;
            case 'cancel_send':
                foreach (DB::table('mail_items')->whereIn('id', $ids)->whereIn('folder', ['scheduled', 'outbox'])->pluck('id') as $iid) {
                    try {
                        $this->cancelSend($a, (int) $iid);
                    } catch (\DomainException $e) { /* اتسلّمت خلاص */ }
                }
                break;
            case 'label_add': case 'label_remove':
                $label = $labelId ? DB::table('mail_labels')->where('id', $labelId)->where('account_id', $a->id)->first() : null;
                if (!$label) {
                    $this->fail('Label not found.', 404);
                }
                if ($action === 'label_add') {
                    foreach ($ids as $id) {
                        DB::table('mail_item_labels')->insertOrIgnore(['item_id' => $id, 'label_id' => $label->id]);
                    }
                } else {
                    DB::table('mail_item_labels')->whereIn('item_id', $ids)->where('label_id', $label->id)->delete();
                }
                break;
            default: $this->fail('Unknown action.');
        }
        if (in_array($action, ['archive', 'spam', 'trash', 'inbox', 'not_spam'], true)) { // أي نقل بيلغي التأجيل
            DB::table('mail_items')->whereIn('id', $ids)->whereNotNull('snoozed_until')->update(['snoozed_until' => null]);
        }
        return count($ids);
    }

    public function emptyTrash(MailAccount $a): int
    {
        $this->assertNoLegalHold($a);
        $n = DB::table('mail_items')->where('account_id', $a->id)->where('folder', 'trash')->delete();
        $this->purgeOrphans();
        if ($n) {
            $this->audit->record('mail.empty_trash', "Emptied trash ({$n})", ['university_id' => $a->university_id, 'tenant_id' => $a->tenant_id, 'actor_user_id' => $a->user_id, 'account_id' => $a->id, 'meta' => ['count' => $n]]);
        }
        return $n;
    }

    /** Legal hold: ممنوع الحذف النهائي لحد ما الأدمن يرفعه (النقل للسلة مسموح). */
    private function assertNoLegalHold(MailAccount $a): void
    {
        if (!empty($a->legal_hold)) {
            $this->fail('This mailbox is under legal hold: permanent deletion is disabled. / الصندوق تحت التجميد القانوني: الحذف النهائي غير متاح.', 423);
        }
    }

    /** رسائل ملهاش أي نسخة صندوق (اتمسحت من كل الأطراف) تتمسح مع مرفقاتها من التخزين. */
    public function purgeOrphans(): void
    {
        $orphans = DB::table('mail_messages as m')->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('mail_items as i')->whereColumn('i.message_id', 'm.id'))->limit(200)->pluck('m.id')->all();
        if (!$orphans) {
            return;
        }
        foreach (DB::table('mail_attachments')->whereIn('message_id', $orphans)->get(['disk', 'path']) as $f) {
            try { Storage::disk($f->disk)->delete($f->path); } catch (\Throwable $e) { /* ignore */ }
        }
        DB::table('mail_messages')->whereIn('id', $orphans)->delete(); // المرفقات والمستلمين cascade
    }

    /** مسح تلقائي للسلة بعد trash_days (أو retention_trash_days للجامعة). الصناديق تحت Legal hold بتتخطّى. */
    public function purgeOldTrash(): int
    {
        $def = (int) config('mailbox.trash_days', 30);
        $custom = DB::table('mail_tenants')->whereNotNull('retention_trash_days')->pluck('retention_trash_days', 'id');
        $n = 0;
        $run = function (?int $tenantId, int $days) use (&$n, $custom) {
            do {
                $q = DB::table('mail_items as i')->join('mail_accounts as a', 'a.id', '=', 'i.account_id')->where('i.folder', 'trash')->where('a.legal_hold', 0)->where('i.trashed_at', '<', now()->subDays(max(1, $days)));
                $tenantId === null
                    ? $q->where(fn ($w) => $w->whereNull('a.tenant_id')->orWhereNotIn('a.tenant_id', $custom->keys()->all() ?: [0]))
                    : $q->where('a.tenant_id', $tenantId);
                $ids = $q->limit(2000)->pluck('i.id')->all();
                if ($ids) {
                    $n += DB::table('mail_items')->whereIn('id', $ids)->delete();
                }
            } while (count($ids) === 2000);
        };
        $run(null, $def);
        foreach ($custom as $tid => $days) {
            $run((int) $tid, (int) $days);
        }
        $this->purgeOrphans();
        return $n;
    }

    /* ------------------------------------------------------------------ labels */

    public function labels(MailAccount $a): array
    {
        return DB::table('mail_labels')->where('account_id', $a->id)->orderBy('name')->get(['id', 'name', 'color'])->map(fn ($l) => ['id' => (int) $l->id, 'name' => $l->name, 'color' => $l->color])->all();
    }

    public function saveLabel(MailAccount $a, array $in, ?int $id = null): array
    {
        $name = Str::limit(trim((string) ($in['name'] ?? '')), 60, '');
        $color = (string) ($in['color'] ?? '#3b82f6');
        if ($name === '') {
            $this->fail('Label name is required.');
        }
        if (!preg_match('/^#[0-9a-f]{6}$/i', $color)) {
            $color = '#3b82f6';
        }
        $dup = DB::table('mail_labels')->where('account_id', $a->id)->where('name', $name)->when($id, fn ($q) => $q->where('id', '!=', $id))->exists();
        if ($dup) {
            $this->fail('A label with this name already exists.');
        }
        if ($id) {
            if (!DB::table('mail_labels')->where('id', $id)->where('account_id', $a->id)->update(['name' => $name, 'color' => $color, 'updated_at' => now()])
                && !DB::table('mail_labels')->where('id', $id)->where('account_id', $a->id)->exists()) {
                $this->fail('Label not found.', 404);
            }
        } else {
            if (DB::table('mail_labels')->where('account_id', $a->id)->count() >= 100) {
                $this->fail('Too many labels.');
            }
            $id = DB::table('mail_labels')->insertGetId(['account_id' => $a->id, 'name' => $name, 'color' => $color, 'created_at' => now(), 'updated_at' => now()]);
        }
        return ['id' => (int) $id, 'name' => $name, 'color' => $color];
    }

    public function deleteLabel(MailAccount $a, int $id): void
    {
        if (!DB::table('mail_labels')->where('id', $id)->where('account_id', $a->id)->delete()) {
            $this->fail('Label not found.', 404);
        }
    }

    /* ------------------------------------------------------------------ attachments */

    private function disk(): string
    {
        return (string) (config('mailbox.attachments_disk') ?: config('filesystems.default', 'local'));
    }

    public function uploadAttachment(MailAccount $a, UploadedFile $file): array
    {
        $max = $this->tenants->maxAttachmentMb($a) * 1048576;
        if (!$file->isValid() || $file->getSize() > $max) {
            $this->fail('File is too large or invalid.', 413);
        }
        $quota = $this->tenants->quotaPayload($a);
        if ($quota && ($quota['used_bytes'] + (int) $file->getSize()) > $quota['limit_mb'] * 1048576) {
            $this->fail('Your mailbox is full.', 413);
        }
        if (DB::table('mail_attachments')->where('account_id', $a->id)->whereNull('message_id')->count() >= 30) {
            $this->fail('Too many unsent attachments.');
        }
        $name = Str::limit(preg_replace('/[\x00-\x1f\/\\\\]/u', '_', $file->getClientOriginalName()) ?: 'file', 190, '');
        $path = $file->storeAs('mail/' . $a->id . '/' . now()->format('Ym'), Str::uuid()->toString(), $this->disk());
        $id = DB::table('mail_attachments')->insertGetId(['account_id' => $a->id, 'disk' => $this->disk(), 'path' => $path, 'name' => $name,
            'mime' => Str::limit((string) $file->getClientMimeType(), 110, ''), 'size' => (int) $file->getSize(), 'created_at' => now()]);
        return ['id' => (int) $id, 'name' => $name, 'size' => (int) $file->getSize(), 'mime' => $file->getClientMimeType()];
    }

    /** مسموح لو المرفق مرفوع منك، أو مربوط برسالة عندك لها نسخة صندوق. */
    public function attachmentForDownload(MailAccount $a, int $id): object
    {
        $att = DB::table('mail_attachments')->where('id', $id)->first();
        $ok = $att && ((int) $att->account_id === (int) $a->id
            || ($att->message_id && DB::table('mail_items')->where('account_id', $a->id)->where('message_id', $att->message_id)->exists()));
        if (!$ok) {
            $this->fail('Attachment not found.', 404);
        }
        if ($att->message_id && (int) $att->account_id !== (int) $a->id) { // مرفقات رسالة سرية مقفولة (باسورد / انتهت / اتسحبت)
            $m = DB::table('mail_messages')->where('id', $att->message_id)->first();
            $it = DB::table('mail_items')->where('account_id', $a->id)->where('message_id', $att->message_id)->first();
            if ($m && $it && $this->confLock($m, $it, $a)) {
                $this->fail('This message is locked.', 403);
            }
        }
        return $att;
    }

    public function deleteAttachment(MailAccount $a, int $id): void
    {
        $att = DB::table('mail_attachments')->where('id', $id)->where('account_id', $a->id)->whereNull('message_id')->first();
        if (!$att) {
            $this->fail('Attachment not found.', 404);
        }
        try { Storage::disk($att->disk)->delete($att->path); } catch (\Throwable $e) { /* ignore */ }
        DB::table('mail_attachments')->where('id', $id)->delete();
    }
}
