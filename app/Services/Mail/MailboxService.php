<?php

namespace App\Services\Mail;

use App\Models\Mail\MailAccount;
use App\Models\User;
use App\Support\MailHtmlSanitizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** UIPE Mail — المرحلة 1: حسابات، إرسال داخلي، مسودات، صناديق، تصنيفات، مرفقات. */
class MailboxService
{
    public const FOLDERS = ['inbox', 'sent', 'drafts', 'archive', 'spam', 'trash'];

    public function __construct(private ExternalMailGateway $external)
    {
    }

    /** يرمي \DomainException(message, httpCode) لأخطاء الاستخدام. */
    private function fail(string $msg, int $code = 422): never
    {
        throw new \DomainException($msg, $code);
    }

    /* ------------------------------------------------------------------ accounts */

    public function accountFor(int $userId): MailAccount
    {
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
            'external_enabled' => $this->external->enabled()];
    }

    /* ------------------------------------------------------------------ folders & lists */

    public function folderCounts(MailAccount $a): array
    {
        $rows = DB::table('mail_items')->where('account_id', $a->id)
            ->selectRaw('folder, COUNT(*) as total, SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread')->groupBy('folder')->get()->keyBy('folder');
        $out = [];
        foreach (self::FOLDERS as $f) {
            $r = $rows->get($f);
            $out[$f] = ['total' => (int) ($r->total ?? 0), 'unread' => (int) ($r->unread ?? 0)];
        }
        $out['starred'] = ['total' => (int) DB::table('mail_items')->where('account_id', $a->id)->where('is_starred', 1)->whereNotIn('folder', ['trash', 'spam'])->count(), 'unread' => 0];
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
        if (!$starredView && !in_array($folder, self::FOLDERS, true)) {
            $this->fail('Unknown folder.');
        }

        $q = DB::table('mail_items as i')->join('mail_messages as m', 'm.id', '=', 'i.message_id')->join('mail_accounts as s', 's.id', '=', 'm.sender_account_id')
            ->where('i.account_id', $a->id);
        if ($starredView) {
            $q->where('i.is_starred', 1)->whereNotIn('i.folder', ['trash', 'spam']);
        } else {
            $q->where('i.folder', $folder);
        }
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
                $w->where('m.subject', 'like', $like)->orWhere('m.body_text', 'like', $like)->orWhere('s.address', 'like', $like)->orWhere('s.display_name', 'like', $like)
                    ->orWhereExists(fn ($s) => $s->select(DB::raw(1))->from('mail_recipients as r')->whereColumn('r.message_id', 'm.id')->where('r.kind', '!=', 'bcc')->where(fn ($x) => $x->where('r.address', 'like', $like)->orWhere('r.name', 'like', $like)));
            });
        }
        $threads = ($f['view'] ?? 'messages') === 'threads';
        if ($threads) {
            $latest = DB::table('mail_items as i2')->join('mail_messages as m2', 'm2.id', '=', 'i2.message_id')->where('i2.account_id', $a->id)
                ->when($starredView, fn ($x) => $x->where('i2.is_starred', 1)->whereNotIn('i2.folder', ['trash', 'spam']), fn ($x) => $x->where('i2.folder', $folder))
                ->groupBy('m2.thread_id')->selectRaw('MAX(i2.id)');
            $q->whereIn('i.id', $latest);
        }

        $total = (clone $q)->count();
        $rows = $q->orderByDesc('i.sort_at')->orderByDesc('i.id')->forPage($page, $per)
            ->get(['i.id', 'i.folder', 'i.is_read', 'i.is_starred', 'i.sort_at', 'm.id as message_id', 'm.thread_id', 'm.subject', 'm.snippet', 'm.is_draft',
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
            'items' => $rows->map(fn ($r) => [
                'id' => (int) $r->id, 'message_id' => (int) $r->message_id, 'thread_id' => (int) $r->thread_id, 'folder' => $r->folder,
                'subject' => $r->subject, 'snippet' => $r->snippet, 'is_read' => (bool) $r->is_read, 'is_starred' => (bool) $r->is_starred, 'is_draft' => (bool) $r->is_draft,
                'date' => $r->sort_at, 'from' => ['address' => $r->from_address, 'name' => $r->from_name],
                'to' => ($recipients[$r->message_id] ?? collect())->map(fn ($x) => ['address' => $x->address, 'name' => $x->name])->values(),
                'has_attachments' => ($atts[$r->message_id] ?? 0) > 0, 'thread_count' => $threads ? (int) ($counts[$r->thread_id] ?? 1) : null,
                'labels' => $labels[$r->id] ?? [],
            ])->values(),
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $per, 'pages' => (int) ceil($total / $per)],
        ];
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

    private function payload(MailAccount $a, object $it): array
    {
        $m = DB::table('mail_messages')->where('id', $it->message_id)->first();
        $sender = DB::table('mail_accounts')->where('id', $m->sender_account_id)->first(['address', 'display_name']);
        $isSender = (int) $m->sender_account_id === (int) $a->id;
        $rc = DB::table('mail_recipients')->where('message_id', $m->id)->orderBy('id')->get()
            ->filter(fn ($r) => $r->kind !== 'bcc' || $isSender || (int) $r->account_id === (int) $a->id); // Bcc بيظهر للمرسل وصاحب الـ Bcc بس
        $labels = $this->labelsForItems([$it->id])[$it->id] ?? [];
        $atts = DB::table('mail_attachments')->where('message_id', $m->id)->get(['id', 'name', 'mime', 'size'])
            ->map(fn ($x) => ['id' => (int) $x->id, 'name' => $x->name, 'mime' => $x->mime, 'size' => (int) $x->size])->values();
        return [
            'id' => (int) $it->id, 'message_id' => (int) $m->id, 'thread_id' => (int) $m->thread_id, 'folder' => $it->folder,
            'subject' => $m->subject, 'body_html' => $m->body_html, 'body_text' => $m->body_text, 'is_draft' => (bool) $m->is_draft,
            'is_read' => (bool) $it->is_read, 'is_starred' => (bool) $it->is_starred, 'date' => $it->sort_at,
            'in_reply_to_message_id' => $m->in_reply_to_message_id ? (int) $m->in_reply_to_message_id : null,
            'from' => ['address' => $sender->address ?? '', 'name' => $sender->display_name ?? null],
            'to' => $rc->where('kind', 'to')->map(fn ($r) => ['address' => $r->address, 'name' => $r->name])->values(),
            'cc' => $rc->where('kind', 'cc')->map(fn ($r) => ['address' => $r->address, 'name' => $r->name])->values(),
            'bcc' => $rc->where('kind', 'bcc')->map(fn ($r) => ['address' => $r->address, 'name' => $r->name])->values(),
            'labels' => $labels, 'attachments' => $atts,
        ];
    }

    /* ------------------------------------------------------------------ compose: drafts & send */

    /** يحوّل مدخلات المستلمين لقائمة موحّدة [{kind,address,name,account_id}] بعد التحقق. */
    private function resolveRecipients(array $input, bool $forSend): array
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
        if (count($out) > (int) config('mailbox.max_recipients', 50)) {
            $this->fail('Too many recipients.');
        }
        $accounts = $out ? MailAccount::whereIn('address', array_column($out, 'address'))->get()->keyBy('address') : collect();
        foreach ($out as &$r) {
            $acc = $accounts->get($r['address']);
            $r['account_id'] = $acc?->id;
            if ($forSend && !$acc && !$this->external->enabled()) {
                $this->fail("لا يمكن الإرسال إلى {$r['address']}: البريد الخارجي غير مفعّل بعد، والإرسال متاح داخل المنصة فقط. / External email is not enabled yet; you can only send to UIPE addresses.", 422);
            }
            if ($forSend && $acc && $acc->status !== 'active') {
                $this->fail("{$r['address']} is suspended.", 422);
            }
        }
        unset($r);
        if ($forSend && !array_filter($out, fn ($r) => $r['kind'] === 'to')) {
            $this->fail('Add at least one recipient.');
        }
        return $out;
    }

    private function normSubject(string $s): string
    {
        $s = trim($s);
        while (preg_match('/^(re|fw|fwd|رد|إعادة توجيه|اعادة توجيه)\s*:\s*/iu', $s)) {
            $s = preg_replace('/^(re|fw|fwd|رد|إعادة توجيه|اعادة توجيه)\s*:\s*/iu', '', $s, 1);
        }
        return Str::limit(mb_strtolower($s), 240, '');
    }

    /** @param array $in subject, body, to/cc/bcc, attachment_ids, in_reply_to_item_id */
    private function writeMessage(MailAccount $a, array $in, bool $send, ?int $draftMessageId): int
    {
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
        $recipients = $this->resolveRecipients($in, $send);

        $replyTo = null; $threadId = null;
        if (!empty($in['in_reply_to_item_id'])) {
            $orig = $this->ownItem($a, (int) $in['in_reply_to_item_id']);
            $replyTo = (int) $orig->message_id;
            $threadId = (int) DB::table('mail_messages')->where('id', $replyTo)->value('thread_id');
        }

        return DB::transaction(function () use ($a, $in, $send, $draftMessageId, $subject, $html, $text, $recipients, $replyTo, $threadId) {
            $now = now();
            $fields = ['subject' => $subject, 'body_html' => $html, 'body_text' => $text, 'snippet' => Str::limit($text, 180, ''), 'updated_at' => $now];
            if ($draftMessageId) {
                $msg = DB::table('mail_messages')->where('id', $draftMessageId)->where('sender_account_id', $a->id)->where('is_draft', 1)->lockForUpdate()->first();
                if (!$msg) {
                    $this->fail('Draft not found.', 404);
                }
                $mid = (int) $msg->id;
                DB::table('mail_messages')->where('id', $mid)->update($fields);
                DB::table('mail_recipients')->where('message_id', $mid)->delete();
                $threadId = (int) $msg->thread_id;
            } else {
                if (!$threadId) {
                    $threadId = DB::table('mail_threads')->insertGetId(['subject_norm' => $this->normSubject($subject), 'created_at' => $now, 'updated_at' => $now]);
                }
                $mid = DB::table('mail_messages')->insertGetId($fields + ['thread_id' => $threadId, 'sender_account_id' => $a->id, 'in_reply_to_message_id' => $replyTo, 'is_draft' => 1, 'created_at' => $now]);
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

            if (!$send) {
                $exists = DB::table('mail_items')->where('account_id', $a->id)->where('message_id', $mid)->exists();
                if (!$exists) {
                    DB::table('mail_items')->insert(['account_id' => $a->id, 'message_id' => $mid, 'folder' => 'drafts', 'is_read' => 1, 'sort_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
                } else {
                    DB::table('mail_items')->where('account_id', $a->id)->where('message_id', $mid)->update(['sort_at' => $now, 'updated_at' => $now]);
                }
                return $mid;
            }

            // إرسال: المسودة تتحوّل لرسالة مرسلة + نسخة لكل مستلم داخلي.
            DB::table('mail_messages')->where('id', $mid)->update(['is_draft' => 0, 'sent_at' => $now]);
            DB::table('mail_threads')->where('id', $threadId)->update(['updated_at' => $now]);
            DB::table('mail_items')->updateOrInsert(['account_id' => $a->id, 'message_id' => $mid],
                ['folder' => 'sent', 'prev_folder' => null, 'is_read' => 1, 'sort_at' => $now, 'updated_at' => $now, 'created_at' => $now]);
            foreach (collect($recipients)->whereNotNull('account_id')->pluck('account_id')->unique() as $accId) {
                if ((int) $accId === (int) $a->id) { // بعت لنفسه: نسخة وارد كمان
                    DB::table('mail_items')->insert(['account_id' => $accId, 'message_id' => $mid, 'folder' => 'inbox', 'is_read' => 0, 'sort_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
                    continue;
                }
                DB::table('mail_items')->insert(['account_id' => $accId, 'message_id' => $mid, 'folder' => 'inbox', 'is_read' => 0, 'sort_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            }
            return $mid;
        });
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
        $mid = $this->writeMessage($a, $in, false, $mid);
        $item = DB::table('mail_items')->where('account_id', $a->id)->where('message_id', $mid)->first();
        return $this->payload($a, $item);
    }

    public function send(MailAccount $a, array $in, ?int $draftItemId = null): array
    {
        if ($a->status !== 'active') {
            $this->fail('Your mailbox is suspended.', 403);
        }
        $mid = null;
        if ($draftItemId) {
            $it = $this->ownItem($a, $draftItemId);
            if ($it->folder !== 'drafts') {
                $this->fail('This message is not a draft.');
            }
            $mid = (int) $it->message_id;
        }
        $mid = $this->writeMessage($a, $in, true, $mid);
        $item = DB::table('mail_items')->where('account_id', $a->id)->where('message_id', $mid)->where('folder', 'sent')->first();
        return $this->payload($a, $item);
    }

    /* ------------------------------------------------------------------ actions */

    /**
     * @param int[] $itemIds
     * @param string $action read|unread|star|unstar|archive|inbox|spam|not_spam|trash|restore|delete_forever|label_add|label_remove
     */
    public function bulk(MailAccount $a, array $itemIds, string $action, ?int $labelId = null): int
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
            case 'trash':
                $q()->where('folder', '!=', 'trash')->update(['prev_folder' => DB::raw('folder'), 'folder' => 'trash', 'trashed_at' => $now, 'updated_at' => $now]); break;
            case 'restore':
                $q()->where('folder', 'trash')->update(['folder' => DB::raw("COALESCE(prev_folder, 'inbox')"), 'prev_folder' => null, 'trashed_at' => null, 'updated_at' => $now]); break;
            case 'delete_forever':
                $q()->where('folder', 'trash')->delete();
                $this->purgeOrphans();
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
        return count($ids);
    }

    public function emptyTrash(MailAccount $a): int
    {
        $n = DB::table('mail_items')->where('account_id', $a->id)->where('folder', 'trash')->delete();
        $this->purgeOrphans();
        return $n;
    }

    /** رسائل ملهاش أي نسخة صندوق (اتمسحت من كل الأطراف) تتمسح مع مرفقاتها من التخزين. */
    private function purgeOrphans(): void
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

    /** مسح تلقائي للسلة بعد trash_days — بيتنادى من schedule لاحقًا أو يدويًا. */
    public function purgeOldTrash(): int
    {
        $n = DB::table('mail_items')->where('folder', 'trash')->where('trashed_at', '<', now()->subDays((int) config('mailbox.trash_days', 30)))->delete();
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
        $max = (int) config('mailbox.max_attachment_mb', 20) * 1048576;
        if (!$file->isValid() || $file->getSize() > $max) {
            $this->fail('File is too large or invalid.', 413);
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
