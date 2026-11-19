<?php

namespace App\Services\Mail;

use App\Models\Mail\MailAccount;
use App\Support\MailHtmlSanitizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * UIPE Mail — المرحلة 3: Import / Export.
 *  - تصدير: mbox (RFC 4155 + MIME، بالمرفقات) أو JSON (من غير مرفقات). الرسائل السرية اللي وصلتك مش بتتصدّر.
 *  - استيراد: ملف .mbox أو .eml (من Gmail Takeout / Outlook / Thunderbird). المرسل الخارجي بيتخزّن في ext_from_*.
 */
class MailPortabilityService
{
    public const MAX_EXPORT = 5000;
    public const MAX_IMPORT_MESSAGES = 5000;
    public const MAX_RAW_MESSAGE = 30 * 1048576;

    public function __construct(private MailboxService $mail)
    {
    }

    private function fail(string $msg, int $code = 422): never
    {
        throw new \DomainException($msg, $code);
    }

    /* ------------------------------------------------------------------ export */

    /** رسائل التصدير (نسخ الصندوق) مع كل ما نحتاجه. folder: اسم مجلد أو all (كل شيء ما عدا السلة/المزعج). */
    private function exportRows(MailAccount $a, string $folder)
    {
        $q = DB::table('mail_items as i')->join('mail_messages as m', 'm.id', '=', 'i.message_id')->join('mail_accounts as s', 's.id', '=', 'm.sender_account_id')
            ->where('i.account_id', $a->id)->where('m.is_draft', 0)
            ->where(fn ($x) => $x->where('m.confidential', 0)->orWhereColumn('m.sender_account_id', 'i.account_id'));
        if ($folder === 'all') {
            $q->whereNotIn('i.folder', ['trash', 'spam', 'outbox', 'scheduled', 'drafts']);
        } else {
            if (!in_array($folder, ['inbox', 'sent', 'archive', 'spam', 'trash'], true)) {
                $this->fail('Unknown folder.');
            }
            $q->where('i.folder', $folder);
        }
        return $q->orderBy('i.sort_at')->limit(self::MAX_EXPORT)
            ->get(['i.id as item_id', 'i.folder', 'i.is_read', 'i.is_starred', 'i.sort_at', 'm.*', 's.address as s_address', 's.display_name as s_name']);
    }

    private function header(string $v): string
    {
        $v = trim(preg_replace('/[\r\n]+/', ' ', $v));
        return preg_match('/[^\x20-\x7e]/', $v) ? '=?UTF-8?B?' . base64_encode($v) . '?=' : $v;
    }

    private function addr(?string $address, ?string $name): string
    {
        $address = trim((string) $address);
        $name = trim((string) $name);
        return $name !== '' ? $this->header($name) . ' <' . $address . '>' : $address;
    }

    private function body64(string $s): string
    {
        return chunk_split(base64_encode($s), 76, "\r\n");
    }

    /** يكتب الـ mbox على الـ output (للاستخدام جوه streamDownload). */
    public function streamMbox(MailAccount $a, string $folder): void
    {
        $rows = $this->exportRows($a, $folder);
        $domain = (string) config('mailbox.domain', 'uipe.local');
        $labels = [];
        foreach (DB::table('mail_item_labels as il')->join('mail_labels as l', 'l.id', '=', 'il.label_id')->whereIn('il.item_id', $rows->pluck('item_id')->all() ?: [0])->get(['il.item_id', 'l.name']) as $r) {
            $labels[$r->item_id][] = $r->name;
        }
        foreach ($rows as $r) {
            $isSender = (int) $r->sender_account_id === (int) $a->id;
            $from = $r->ext_from_address ?: $r->s_address;
            $fromName = $r->ext_from_address ? $r->ext_from_name : $r->s_name;
            $when = Carbon::parse($r->sent_at ?: $r->sort_at, 'UTC');
            $rc = DB::table('mail_recipients')->where('message_id', $r->id)->orderBy('id')->get();
            $join = fn ($kind) => $rc->where('kind', $kind)->map(fn ($x) => $this->addr($x->address, $x->name))->implode(', ');
            $b1 = 'uipe_' . Str::random(16);
            $b2 = 'uipe_alt_' . Str::random(16);
            $h = "From {$from} " . $when->format('D M j H:i:s Y') . "\r\n";
            $h .= 'From: ' . $this->addr($from, $fromName) . "\r\n";
            if ($rc->where('kind', 'to')->isNotEmpty()) { $h .= 'To: ' . $join('to') . "\r\n"; }
            if ($rc->where('kind', 'cc')->isNotEmpty()) { $h .= 'Cc: ' . $join('cc') . "\r\n"; }
            if ($isSender && $rc->where('kind', 'bcc')->isNotEmpty()) { $h .= 'Bcc: ' . $join('bcc') . "\r\n"; }
            $h .= 'Subject: ' . $this->header((string) $r->subject) . "\r\n";
            $h .= 'Date: ' . $when->format('D, d M Y H:i:s') . " +0000\r\n";
            $h .= 'Message-ID: <' . ($r->ext_message_id ?: "uipe-{$r->id}@{$domain}") . ">\r\n";
            $h .= 'X-UIPE-Folder: ' . $r->folder . "\r\n";
            $h .= 'X-UIPE-Read: ' . (int) $r->is_read . "\r\n" . 'X-UIPE-Starred: ' . (int) $r->is_starred . "\r\n";
            if (!empty($labels[$r->item_id])) { $h .= 'X-UIPE-Labels: ' . $this->header(implode(',', str_replace(',', ' ', $labels[$r->item_id]))) . "\r\n"; }
            $h .= "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"{$b1}\"\r\n\r\n";
            $h .= "--{$b1}\r\nContent-Type: multipart/alternative; boundary=\"{$b2}\"\r\n\r\n";
            $h .= "--{$b2}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $this->body64((string) $r->body_text);
            $h .= "--{$b2}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $this->body64((string) $r->body_html);
            $h .= "--{$b2}--\r\n";
            echo $h;
            foreach (DB::table('mail_attachments')->where('message_id', $r->id)->get() as $att) {
                $name = $this->header((string) $att->name);
                echo "--{$b1}\r\nContent-Type: " . ($att->mime ?: 'application/octet-stream') . "; name=\"{$name}\"\r\nContent-Disposition: attachment; filename=\"{$name}\"\r\nContent-Transfer-Encoding: base64\r\n\r\n";
                try {
                    $fp = Storage::disk($att->disk)->readStream($att->path);
                    if ($fp) {
                        stream_filter_append($fp, 'convert.base64-encode', STREAM_FILTER_READ, ['line-length' => 76, 'line-break-chars' => "\r\n"]);
                        while (!feof($fp)) {
                            echo fread($fp, 8192);
                        }
                        fclose($fp);
                    }
                } catch (\Throwable $e) { /* مرفق ناقص — نكمل الباقي */ }
                echo "\r\n";
            }
            echo "--{$b1}--\r\n\r\n";
            if (function_exists('flush')) { @flush(); }
        }
    }

    public function exportJson(MailAccount $a, string $folder): array
    {
        $rows = $this->exportRows($a, $folder);
        $labels = [];
        foreach (DB::table('mail_item_labels as il')->join('mail_labels as l', 'l.id', '=', 'il.label_id')->whereIn('il.item_id', $rows->pluck('item_id')->all() ?: [0])->get(['il.item_id', 'l.name']) as $r) {
            $labels[$r->item_id][] = $r->name;
        }
        $out = [];
        foreach ($rows as $r) {
            $rc = DB::table('mail_recipients')->where('message_id', $r->id)->orderBy('id')->get();
            $isSender = (int) $r->sender_account_id === (int) $a->id;
            $att = DB::table('mail_attachments')->where('message_id', $r->id)->get(['name', 'mime', 'size']);
            $out[] = ['folder' => $r->folder, 'date' => Carbon::parse($r->sent_at ?: $r->sort_at, 'UTC')->toIso8601String(), 'subject' => $r->subject,
                'from' => ['address' => $r->ext_from_address ?: $r->s_address, 'name' => $r->ext_from_address ? $r->ext_from_name : $r->s_name],
                'to' => $rc->where('kind', 'to')->map(fn ($x) => ['address' => $x->address, 'name' => $x->name])->values(),
                'cc' => $rc->where('kind', 'cc')->map(fn ($x) => ['address' => $x->address, 'name' => $x->name])->values(),
                'bcc' => $isSender ? $rc->where('kind', 'bcc')->map(fn ($x) => ['address' => $x->address, 'name' => $x->name])->values() : [],
                'is_read' => (bool) $r->is_read, 'is_starred' => (bool) $r->is_starred, 'labels' => $labels[$r->item_id] ?? [],
                'body_text' => $r->body_text, 'body_html' => $r->body_html, 'attachments' => $att];
        }
        return ['exported_at' => now()->toIso8601String(), 'account' => $a->address, 'count' => count($out), 'messages' => $out];
    }

    /* ------------------------------------------------------------------ import */

    /** @return array{imported:int,skipped:int,failed:int} */
    public function import(MailAccount $a, UploadedFile $file, string $folder = 'inbox'): array
    {
        if (!in_array($folder, ['inbox', 'archive'], true)) {
            $this->fail('Choose Inbox or Archive.');
        }
        $path = $file->getRealPath();
        $fp = $path ? fopen($path, 'rb') : false;
        if (!$fp) {
            $this->fail('Could not read the file.');
        }
        $stats = ['imported' => 0, 'skipped' => 0, 'failed' => 0];
        $threads = [];
        $labelCache = DB::table('mail_labels')->where('account_id', $a->id)->pluck('id', 'name')->all();
        $first = fgets($fp);
        $isMbox = is_string($first) && str_starts_with($first, 'From ');
        rewind($fp);
        $handle = function (string $raw) use ($a, $folder, &$stats, &$threads, &$labelCache) {
            if ($stats['imported'] + $stats['skipped'] + $stats['failed'] >= self::MAX_IMPORT_MESSAGES) {
                return;
            }
            try {
                $this->importOne($a, $raw, $folder, $stats, $threads, $labelCache);
            } catch (\Throwable $e) {
                $stats['failed']++;
            }
        };
        if (!$isMbox) {
            $raw = stream_get_contents($fp, self::MAX_RAW_MESSAGE);
            fclose($fp);
            if ($raw === false || trim($raw) === '') {
                $this->fail('The file is empty.');
            }
            $handle($raw);
            return $stats;
        }
        $buf = ''; $prevBlank = true; $skip = false;
        while (($line = fgets($fp)) !== false) {
            if ($prevBlank && str_starts_with($line, 'From ')) { // سطر From_ = بداية رسالة جديدة
                if ($skip) {
                    $stats['failed']++;
                } elseif (trim($buf) !== '') {
                    $handle($buf);
                }
                $buf = ''; $skip = false; $prevBlank = false;
                continue;
            }
            $prevBlank = trim($line) === '';
            if ($skip) {
                continue;
            }
            if (strlen($buf) + strlen($line) > self::MAX_RAW_MESSAGE) { // رسالة أكبر من الحد → نتخطاها
                $skip = true; $buf = '';
                continue;
            }
            $buf .= preg_match('/^>+From /', $line) ? substr($line, 1) : $line; // mboxrd unescape
        }
        if ($skip) {
            $stats['failed']++;
        } elseif (trim($buf) !== '') {
            $handle($buf);
        }
        fclose($fp);
        return $stats;
    }

    private function importOne(MailAccount $a, string $raw, string $folder, array &$stats, array &$threads, array &$labelCache): void
    {
        $p = $this->parse(str_replace("\r\n", "\n", $raw));
        $h = $p['headers'];
        $msgId = isset($h['message-id']) ? Str::limit(trim($this->decodeHeader($h['message-id']), " <>\t"), 240, '') : '';
        if ($msgId !== '' && DB::table('mail_messages')->where('sender_account_id', $a->id)->where('is_imported', 1)->where('ext_message_id', $msgId)->exists()) {
            $stats['skipped']++;
            return;
        }
        [$fromAddr, $fromName] = $this->firstAddress($this->decodeHeader($h['from'] ?? ''));
        if ($fromAddr === '') {
            $fromAddr = 'unknown@invalid.local';
        }
        $subject = Str::limit(trim($this->decodeHeader($h['subject'] ?? '')), 250, '');
        try {
            $when = isset($h['date']) ? Carbon::parse($h['date'])->setTimezone('UTC') : now();
        } catch (\Throwable $e) {
            $when = now();
        }
        if ($when->gt(now())) {
            $when = now();
        }

        $html = $p['html'] !== '' ? MailHtmlSanitizer::clean($p['html']) : '<div dir="auto">' . nl2br(e($p['text'])) . '</div>';
        $text = $p['text'] !== '' ? $p['text'] : trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['<br>', '</p>', '</div>'], ' ', $html)), ENT_QUOTES, 'UTF-8')));
        $text = Str::limit(trim(preg_replace('/\s+/u', ' ', $text)), 60000, '');
        if (strlen($html) > (int) config('mailbox.max_body_kb', 512) * 1024) {
            $html = Str::limit(strip_tags($html), 100000, '');
        }
        $norm = $this->mail->normSubject($subject);
        $now = now();

        DB::transaction(function () use ($a, $h, $p, $msgId, $fromAddr, $fromName, $subject, $when, $html, $text, $norm, $folder, $now, &$threads, &$labelCache) {
            if ($norm !== '' && isset($threads[$norm])) {
                $tid = $threads[$norm];
            } else {
                $tid = DB::table('mail_threads')->insertGetId(['subject_norm' => $norm, 'created_at' => $now, 'updated_at' => $now]);
                if ($norm !== '') { $threads[$norm] = $tid; }
            }
            $mid = DB::table('mail_messages')->insertGetId(['thread_id' => $tid, 'sender_account_id' => $a->id, 'subject' => $subject, 'body_html' => $html, 'body_text' => $text,
                'snippet' => Str::limit($text, 180, ''), 'is_draft' => 0, 'sent_at' => $when, 'is_imported' => 1, 'ext_from_address' => Str::limit($fromAddr, 190, ''),
                'ext_from_name' => $fromName !== '' ? Str::limit($fromName, 150, '') : null, 'ext_message_id' => $msgId !== '' ? $msgId : null, 'created_at' => $now, 'updated_at' => $now]);
            $rcpts = [];
            foreach (['to', 'cc'] as $kind) {
                foreach ($this->addressList($this->decodeHeader($h[$kind] ?? '')) as [$ad, $nm]) {
                    $rcpts[$kind . $ad] = ['message_id' => $mid, 'kind' => $kind, 'address' => Str::limit($ad, 190, ''), 'name' => $nm !== '' ? Str::limit($nm, 150, '') : null, 'account_id' => null];
                }
            }
            if ($rcpts) {
                DB::table('mail_recipients')->insert(array_slice(array_values($rcpts), 0, 100));
            }
            $read = isset($h['x-uipe-read']) ? trim($h['x-uipe-read']) === '1' : (isset($h['status']) ? str_contains($h['status'], 'R') : false);
            $itemId = DB::table('mail_items')->insertGetId(['account_id' => $a->id, 'message_id' => $mid, 'folder' => $folder, 'is_read' => $read ? 1 : 0,
                'is_starred' => isset($h['x-uipe-starred']) && trim($h['x-uipe-starred']) === '1' ? 1 : 0, 'sort_at' => $when, 'created_at' => $now, 'updated_at' => $now]);
            if (!empty($h['x-uipe-labels'])) {
                foreach (array_slice(array_filter(array_map('trim', explode(',', $this->decodeHeader($h['x-uipe-labels'])))), 0, 10) as $ln) {
                    $ln = Str::limit($ln, 60, '');
                    if (!isset($labelCache[$ln])) {
                        if (count($labelCache) >= 100) { continue; }
                        $labelCache[$ln] = DB::table('mail_labels')->insertGetId(['account_id' => $a->id, 'name' => $ln, 'color' => '#3b82f6', 'created_at' => $now, 'updated_at' => $now]);
                    }
                    DB::table('mail_item_labels')->insertOrIgnore(['item_id' => $itemId, 'label_id' => $labelCache[$ln]]);
                }
            }
            $disk = (string) (config('mailbox.attachments_disk') ?: config('filesystems.default', 'local'));
            foreach (array_slice($p['attachments'], 0, 20) as $att) {
                if (strlen($att['data']) > (int) config('mailbox.max_attachment_mb', 20) * 1048576 || $att['data'] === '') { continue; }
                $path = 'mail/' . $a->id . '/' . now()->format('Ym') . '/' . Str::uuid()->toString();
                Storage::disk($disk)->put($path, $att['data']);
                DB::table('mail_attachments')->insert(['account_id' => $a->id, 'message_id' => $mid, 'disk' => $disk, 'path' => $path,
                    'name' => Str::limit(preg_replace('/[\x00-\x1f\/\\\\]/u', '_', $att['name']) ?: 'file', 190, ''), 'mime' => Str::limit($att['mime'], 110, ''), 'size' => strlen($att['data']), 'created_at' => $now]);
            }
        });
        $stats['imported']++;
    }

    /* ------------------------------------------------------------------ RFC 822 / MIME parsing */

    private function decodeHeader(string $v): string
    {
        $v = trim($v);
        if ($v === '' || !str_contains($v, '=?')) {
            return $v;
        }
        $d = @mb_decode_mimeheader($v);
        return is_string($d) && $d !== '' ? $d : $v;
    }

    /** @return array{0:string,1:string} [address, name] */
    private function firstAddress(string $s): array
    {
        $l = $this->addressList($s);
        return $l[0] ?? ['', ''];
    }

    /** "Ali <a@x.com>, b@y.com" → [[address, name], …] */
    private function addressList(string $s): array
    {
        $out = [];
        if (!preg_match_all('/(?:"?([^"<,;]*?)"?\s*)?<\s*([^<>\s,;]+@[^<>\s,;]+)\s*>|([^\s<>,;"]+@[^\s<>,;"]+)/u', $s, $m, PREG_SET_ORDER)) {
            return $out;
        }
        foreach ($m as $x) {
            $ad = strtolower(trim($x[2] !== '' ? $x[2] : ($x[3] ?? '')));
            if ($ad === '' || !filter_var($ad, FILTER_VALIDATE_EMAIL)) { continue; }
            $out[] = [$ad, trim((string) ($x[1] ?? ''), " \t\"'")];
        }
        return $out;
    }

    /** @return array{0:array,1:string} [headers (lowercase keys), body] — EOL لازم يكون \n */
    private function splitMessage(string $raw): array
    {
        $raw = ltrim($raw, "\n");
        $pos = strpos($raw, "\n\n");
        $head = $pos === false ? $raw : substr($raw, 0, $pos);
        $body = $pos === false ? '' : substr($raw, $pos + 2);
        $head = preg_replace("/\n[ \t]+/", ' ', $head);
        $h = [];
        foreach (explode("\n", $head) as $line) {
            if (preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', $line, $m)) {
                $k = strtolower($m[1]);
                if (!isset($h[$k])) { $h[$k] = $m[2]; }
            }
        }
        return [$h, $body];
    }

    private function params(string $v): array
    {
        $out = ['_' => strtolower(trim(explode(';', $v, 2)[0]))];
        if (preg_match_all('/;\s*([A-Za-z0-9*-]+)\s*=\s*(?:"((?:[^"\\\\]|\\\\.)*)"|([^;\s]*))/', $v, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $k = strtolower($x[1]);
                $val = $x[2] !== '' ? stripslashes($x[2]) : ($x[3] ?? '');
                if (str_ends_with($k, '*') && preg_match("/^([^']*)'[^']*'(.*)$/", $val, $q)) { // RFC 2231: filename*=UTF-8''%D8%B9
                    $k = rtrim($k, '*');
                    $val = rawurldecode($q[2]);
                }
                $out[$k] = $val;
            }
        }
        return $out;
    }

    private function decodeBody(string $body, string $enc, string $charset): string
    {
        $enc = strtolower(trim($enc));
        if ($enc === 'base64') {
            $body = (string) base64_decode(preg_replace('/\s+/', '', $body), false);
        } elseif ($enc === 'quoted-printable') {
            $body = quoted_printable_decode($body);
        }
        $cs = strtolower(trim($charset ?: 'utf-8'));
        if ($cs !== 'utf-8' && $cs !== 'utf8' && $cs !== 'us-ascii') {
            $conv = @mb_convert_encoding($body, 'UTF-8', $cs);
            if (is_string($conv)) { $body = $conv; }
        }
        return $body;
    }

    /** @return array{headers:array,text:string,html:string,attachments:array} */
    private function parse(string $raw): array
    {
        [$h, $body] = $this->splitMessage($raw);
        $out = ['headers' => $h, 'text' => '', 'html' => '', 'attachments' => []];
        $this->walk($h, $body, $out, 0);
        $out['text'] = trim($out['text']);
        $out['html'] = trim($out['html']);
        return $out;
    }

    private function walk(array $h, string $body, array &$out, int $depth): void
    {
        if ($depth > 8) {
            return;
        }
        $ct = $this->params($h['content-type'] ?? 'text/plain');
        $type = $ct['_'] ?: 'text/plain';
        if (str_starts_with($type, 'multipart/') && !empty($ct['boundary'])) {
            $parts = explode("\n--" . $ct['boundary'], "\n" . $body);
            array_shift($parts);
            foreach ($parts as $part) {
                if (str_starts_with($part, '--')) { break; }
                $part = preg_replace('/^[ \t]*\n/', '', $part, 1);
                [$ph, $pb] = $this->splitMessage($part);
                $this->walk($ph, $pb, $out, $depth + 1);
            }
            return;
        }
        $disp = $this->params($h['content-disposition'] ?? '');
        $name = $disp['filename'] ?? ($ct['name'] ?? '');
        $isAtt = ($disp['_'] ?? '') === 'attachment' || $name !== '' || ($type !== 'text/plain' && $type !== 'text/html' && !str_starts_with($type, 'multipart/'));
        $data = $this->decodeBody($body, $h['content-transfer-encoding'] ?? '', $ct['charset'] ?? 'utf-8');
        if ($isAtt) {
            if ($type === 'message/rfc822' && $name === '') { $name = 'message.eml'; }
            $out['attachments'][] = ['name' => $this->decodeHeader($name) ?: 'file', 'mime' => $type, 'data' => ($type === 'message/rfc822') ? $body : $this->decodeBinary($body, $h['content-transfer-encoding'] ?? '')];
            return;
        }
        if ($type === 'text/html') {
            $out['html'] .= $data;
        } else {
            $out['text'] .= ($out['text'] !== '' ? "\n" : '') . $data;
        }
    }

    private function decodeBinary(string $body, string $enc): string
    {
        $enc = strtolower(trim($enc));
        if ($enc === 'base64') {
            return (string) base64_decode(preg_replace('/\s+/', '', $body), false);
        }
        if ($enc === 'quoted-printable') {
            return quoted_printable_decode($body);
        }
        return $body;
    }
}
