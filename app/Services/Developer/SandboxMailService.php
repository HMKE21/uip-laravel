<?php

namespace App\Services\Developer;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * صندوق Sandbox: مفيش بريد حقيقي بيتلمس ولا بيتبعت. بيتستخدم بمفاتيح mode=sandbox (uipk_test_…).
 * نفس شكل ردود الـ API الحقيقي (قوائم + رسالة) عشان المطوّر يبني عليه ويبدّل للـ live بتغيير المفتاح بس.
 */
class SandboxMailService
{
    public const FOLDERS = ['inbox', 'sent', 'drafts', 'trash'];

    public function __construct(private DevEventBus $events)
    {
    }

    private function fail(string $msg, int $code = 422): never
    {
        throw new \DomainException($msg, $code);
    }

    public function address(int $userId): string
    {
        return 'sandbox+' . $userId . '@' . config('mailbox.domain', 'uipe.local');
    }

    private function seed(int $userId): void
    {
        if (DB::table('dev_sandbox_messages')->where('user_id', $userId)->exists()) {
            return;
        }
        DB::table('dev_sandbox_messages')->insert(['user_id' => $userId, 'folder' => 'inbox', 'from_address' => 'welcome@sandbox.uipe.local', 'from_name' => 'UIPE Sandbox',
            'to' => json_encode([$this->address($userId)]), 'subject' => 'Welcome to the UIPE sandbox', 'body_text' => 'Nothing here is real. Send mail, read it, and simulate incoming messages safely.', 'created_at' => now()]);
    }

    public function me(int $userId): array
    {
        $this->seed($userId);
        $name = DB::table('users')->where('id', $userId)->value('full_name');
        return ['id' => 0, 'address' => $this->address($userId), 'kind' => 'sandbox', 'display_name' => $name, 'status' => 'active', 'sandbox' => true, 'folders' => $this->folderCounts($userId)];
    }

    public function folderCounts(int $userId): array
    {
        $rows = DB::table('dev_sandbox_messages')->where('user_id', $userId)->selectRaw('folder, COUNT(*) as total, SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread')->groupBy('folder')->get()->keyBy('folder');
        $out = [];
        foreach (self::FOLDERS as $f) {
            $r = $rows->get($f);
            $out[$f] = ['total' => (int) ($r->total ?? 0), 'unread' => (int) ($r->unread ?? 0)];
        }
        $out['starred'] = ['total' => (int) DB::table('dev_sandbox_messages')->where('user_id', $userId)->where('is_starred', 1)->where('folder', '!=', 'trash')->count(), 'unread' => 0];
        return $out;
    }

    private function recipients($json): array
    {
        return array_values(array_map(fn ($a) => ['address' => (string) $a, 'name' => null], (array) json_decode((string) $json, true)));
    }

    private function item(object $m, bool $full): array
    {
        $out = [
            'id' => (int) $m->id, 'message_id' => (int) $m->id, 'thread_id' => (int) $m->id, 'folder' => $m->folder, 'subject' => $m->subject,
            'snippet' => Str::limit((string) $m->body_text, 180, ''), 'is_read' => (bool) $m->is_read, 'is_starred' => (bool) $m->is_starred, 'is_draft' => $m->folder === 'drafts',
            'date' => DevTokens::iso($m->created_at), 'from' => ['address' => $m->from_address, 'name' => $m->from_name], 'to' => $this->recipients($m->to),
            'has_attachments' => false, 'labels' => [], 'sandbox' => true,
        ];
        if ($full) {
            $out += ['body_text' => $m->body_text, 'body_html' => null, 'cc' => [], 'bcc' => [], 'attachments' => [], 'confidential' => null];
        }
        return $out;
    }

    public function list(int $userId, array $f): array
    {
        $this->seed($userId);
        $per = max(1, min(50, (int) ($f['per_page'] ?? 25)));
        $page = max(1, (int) ($f['page'] ?? 1));
        $folder = (string) ($f['folder'] ?? 'inbox');
        $q = DB::table('dev_sandbox_messages')->where('user_id', $userId);
        if ($folder === 'starred' || !empty($f['starred'])) {
            $q->where('is_starred', 1)->where('folder', '!=', 'trash');
        } elseif (in_array($folder, self::FOLDERS, true)) {
            $q->where('folder', $folder);
        } else {
            $this->fail('Unknown folder.');
        }
        if (!empty($f['unread'])) {
            $q->where('is_read', 0);
        }
        $term = trim((string) ($f['q'] ?? ''));
        if ($term !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $term) . '%';
            $q->where(fn ($w) => $w->where('subject', 'like', $like)->orWhere('body_text', 'like', $like)->orWhere('from_address', 'like', $like));
        }
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('id')->forPage($page, $per)->get();
        return ['items' => $rows->map(fn ($m) => $this->item($m, false))->values()->all(), 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $per, 'pages' => (int) ceil($total / $per)]];
    }

    private function own(int $userId, int $id): object
    {
        $m = DB::table('dev_sandbox_messages')->where('id', $id)->where('user_id', $userId)->first();
        if (!$m) {
            $this->fail('Message not found.', 404);
        }
        return $m;
    }

    public function show(int $userId, int $id, bool $markRead = true): array
    {
        $m = $this->own($userId, $id);
        if ($markRead && !$m->is_read && $m->folder !== 'drafts') {
            DB::table('dev_sandbox_messages')->where('id', $m->id)->update(['is_read' => 1]);
            $m->is_read = 1;
        }
        return $this->item($m, true);
    }

    private function addrs($in): array
    {
        $out = [];
        foreach ((array) $in as $a) {
            $addr = strtolower(trim(is_array($a) ? (string) ($a['address'] ?? '') : (string) $a));
            if ($addr === '') {
                continue;
            }
            if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                $this->fail('Invalid email address: ' . Str::limit($addr, 60, ''));
            }
            $out[] = $addr;
        }
        return array_values(array_unique($out));
    }

    private function trim(int $userId): void
    {
        $cap = (int) config('developer.limits.sandbox_messages', 500);
        $n = DB::table('dev_sandbox_messages')->where('user_id', $userId)->count();
        if ($n > $cap) {
            $old = DB::table('dev_sandbox_messages')->where('user_id', $userId)->orderBy('id')->limit($n - $cap)->pluck('id')->all();
            DB::table('dev_sandbox_messages')->whereIn('id', $old)->delete();
        }
    }

    /** "بيبعت" لكن الرسالة بتتحفظ في sent بس. $draft = مسودة. */
    public function send(int $userId, array $in, bool $draft = false): array
    {
        $this->seed($userId);
        $to = $this->addrs(array_merge((array) ($in['to'] ?? []), (array) ($in['cc'] ?? []), (array) ($in['bcc'] ?? [])));
        if (!$draft && !$to) {
            $this->fail('Add at least one recipient.');
        }
        if (count($to) > (int) config('mailbox.max_recipients', 50)) {
            $this->fail('Too many recipients.');
        }
        $subject = Str::limit(trim((string) ($in['subject'] ?? '')), 250, '');
        $body = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($in['body'] ?? $in['body_text'] ?? ''))));
        if (!$draft && $subject === '' && $body === '') {
            $this->fail('Write a subject or a message first.');
        }
        $name = DB::table('users')->where('id', $userId)->value('full_name');
        $id = (int) DB::table('dev_sandbox_messages')->insertGetId(['user_id' => $userId, 'folder' => $draft ? 'drafts' : 'sent', 'from_address' => $this->address($userId), 'from_name' => $name,
            'to' => json_encode($to), 'subject' => $subject, 'body_text' => Str::limit($body, 20000, ''), 'is_read' => 1, 'created_at' => now()]);
        $this->trim($userId);
        $m = DB::table('dev_sandbox_messages')->find($id);
        if (!$draft) {
            $this->events->emit('mail.message.sent', $userId, ['item_id' => $id, 'message_id' => $id, 'thread_id' => $id, 'subject' => $m->subject, 'to' => $to, 'sandbox' => true], null, 'sandbox');
        }
        return $this->item($m, true);
    }

    /** يحاكي وصول رسالة جديدة (وبيطلق webhook mail.message.received في وضع sandbox). */
    public function simulateIncoming(int $userId, array $in): array
    {
        $this->seed($userId);
        $from = strtolower(trim((string) ($in['from'] ?? 'friend@sandbox.uipe.local')));
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $this->fail('Invalid sender address.');
        }
        $subject = Str::limit(trim((string) ($in['subject'] ?? 'Hello from the sandbox')), 250, '');
        $body = Str::limit(trim((string) ($in['body'] ?? 'This is a simulated incoming message.')), 20000, '');
        $id = (int) DB::table('dev_sandbox_messages')->insertGetId(['user_id' => $userId, 'folder' => 'inbox', 'from_address' => $from, 'from_name' => isset($in['from_name']) ? Str::limit(trim((string) $in['from_name']), 120, '') : null,
            'to' => json_encode([$this->address($userId)]), 'subject' => $subject, 'body_text' => $body, 'is_read' => 0, 'created_at' => now()]);
        $this->trim($userId);
        $m = DB::table('dev_sandbox_messages')->find($id);
        $this->events->emit('mail.message.received', $userId, ['item_id' => $id, 'message_id' => $id, 'thread_id' => $id, 'folder' => 'inbox', 'from' => ['address' => $from, 'name' => $m->from_name],
            'subject' => $subject, 'snippet' => Str::limit($body, 180, ''), 'received_at' => DevTokens::iso($m->created_at), 'sandbox' => true], null, 'sandbox');
        return $this->item($m, true);
    }

    /** { is_read?, is_starred?, folder? } */
    public function update(int $userId, int $id, array $in): array
    {
        $m = $this->own($userId, $id);
        $upd = [];
        if (array_key_exists('is_read', $in)) {
            $upd['is_read'] = (int) (bool) $in['is_read'];
        }
        if (array_key_exists('is_starred', $in)) {
            $upd['is_starred'] = (int) (bool) $in['is_starred'];
        }
        if (isset($in['folder'])) {
            if (!in_array($in['folder'], ['inbox', 'trash'], true)) {
                $this->fail('In the sandbox you can move messages to inbox or trash only.');
            }
            $upd['folder'] = $in['folder'];
        }
        if ($upd) {
            DB::table('dev_sandbox_messages')->where('id', $m->id)->update($upd);
        }
        return $this->show($userId, $id, false);
    }

    public function delete(int $userId, int $id, bool $permanent = false): int
    {
        $m = $this->own($userId, $id);
        if ($permanent || $m->folder === 'trash') {
            return DB::table('dev_sandbox_messages')->where('id', $m->id)->delete();
        }
        return DB::table('dev_sandbox_messages')->where('id', $m->id)->update(['folder' => 'trash']);
    }

    public function reset(int $userId): void
    {
        DB::table('dev_sandbox_messages')->where('user_id', $userId)->delete();
        $this->seed($userId);
    }
}
