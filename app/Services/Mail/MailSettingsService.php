<?php

namespace App\Services\Mail;

use App\Models\Mail\MailAccount;
use App\Support\MailHtmlSanitizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** UIPE Mail — المرحلة 3: إعدادات الصندوق (Undo Send)، التوقيعات، القوالب، الفلاتر، الرد التلقائي (Vacation). */
class MailSettingsService
{
    public const MAX_SIGNATURES = 20;
    public const MAX_TEMPLATES = 50;
    public const MAX_FILTERS = 50;
    public const SIG_KB = 20;
    public const TPL_KB = 100;

    private function fail(string $msg, int $code = 422): never
    {
        throw new \DomainException($msg, $code);
    }

    private static function iso($v): ?string
    {
        return $v ? Carbon::parse($v, 'UTC')->toIso8601String() : null;
    }

    /* ------------------------------------------------------------------ settings */

    public function settingsRow(MailAccount $a): object
    {
        $r = DB::table('mail_settings')->where('account_id', $a->id)->first();
        return $r ?: (object) ['undo_seconds' => 10, 'sig_new_id' => null, 'sig_reply_id' => null];
    }

    /** بيتبعت جوه GET /account: التوقيع الافتراضي جاهز للكتابة من غير طلب إضافي. */
    public function settingsPayload(MailAccount $a): array
    {
        $s = $this->settingsRow($a);
        $sig = function ($id) use ($a) {
            if (!$id) {
                return null;
            }
            $r = DB::table('mail_signatures')->where('id', $id)->where('account_id', $a->id)->first(['id', 'name', 'body_html']);
            return $r ? ['id' => (int) $r->id, 'name' => $r->name, 'body_html' => (string) $r->body_html] : null;
        };
        $vac = DB::table('mail_vacation')->where('account_id', $a->id)->first();
        return [
            'undo_seconds' => (int) $s->undo_seconds,
            'sig_new_id' => $s->sig_new_id ? (int) $s->sig_new_id : null,
            'sig_reply_id' => $s->sig_reply_id ? (int) $s->sig_reply_id : null,
            'sig_new' => $sig($s->sig_new_id),
            'sig_reply' => $sig($s->sig_reply_id),
            'vacation_on' => $vac ? $this->vacationActive($vac) : false,
        ];
    }

    public function saveSettings(MailAccount $a, array $in): array
    {
        $row = [];
        if (array_key_exists('undo_seconds', $in)) {
            $row['undo_seconds'] = max(0, min(30, (int) $in['undo_seconds']));
        }
        foreach (['sig_new_id', 'sig_reply_id'] as $k) {
            if (array_key_exists($k, $in)) {
                $id = $in[$k] ? (int) $in[$k] : null;
                if ($id && !DB::table('mail_signatures')->where('id', $id)->where('account_id', $a->id)->exists()) {
                    $this->fail('Signature not found.', 404);
                }
                $row[$k] = $id;
            }
        }
        if ($row) {
            $exists = DB::table('mail_settings')->where('account_id', $a->id)->exists();
            if ($exists) {
                DB::table('mail_settings')->where('account_id', $a->id)->update($row + ['updated_at' => now()]);
            } else {
                DB::table('mail_settings')->insert($row + ['account_id' => $a->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        return $this->settingsPayload($a);
    }

    /* ------------------------------------------------------------------ signatures */

    public function signatures(MailAccount $a): array
    {
        return DB::table('mail_signatures')->where('account_id', $a->id)->orderBy('name')->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'body_html' => (string) $r->body_html])->all();
    }

    public function saveSignature(MailAccount $a, array $in, ?int $id = null): array
    {
        $name = Str::limit(trim((string) ($in['name'] ?? '')), 100, '');
        if ($name === '') {
            $this->fail('Give the signature a name.');
        }
        $raw = (string) ($in['body_html'] ?? '');
        if (strlen($raw) > self::SIG_KB * 1024) {
            $this->fail('Signature is too large.');
        }
        $html = MailHtmlSanitizer::clean($raw);
        if ($id) {
            $n = DB::table('mail_signatures')->where('id', $id)->where('account_id', $a->id)->update(['name' => $name, 'body_html' => $html, 'updated_at' => now()]);
            if (!$n && !DB::table('mail_signatures')->where('id', $id)->where('account_id', $a->id)->exists()) {
                $this->fail('Signature not found.', 404);
            }
        } else {
            if (DB::table('mail_signatures')->where('account_id', $a->id)->count() >= self::MAX_SIGNATURES) {
                $this->fail('Too many signatures.');
            }
            $id = DB::table('mail_signatures')->insertGetId(['account_id' => $a->id, 'name' => $name, 'body_html' => $html, 'created_at' => now(), 'updated_at' => now()]);
        }
        return ['id' => (int) $id, 'name' => $name, 'body_html' => $html];
    }

    public function deleteSignature(MailAccount $a, int $id): void
    {
        if (!DB::table('mail_signatures')->where('id', $id)->where('account_id', $a->id)->delete()) {
            $this->fail('Signature not found.', 404);
        }
        DB::table('mail_settings')->where('account_id', $a->id)->where('sig_new_id', $id)->update(['sig_new_id' => null]);
        DB::table('mail_settings')->where('account_id', $a->id)->where('sig_reply_id', $id)->update(['sig_reply_id' => null]);
    }

    /* ------------------------------------------------------------------ templates */

    public function templates(MailAccount $a): array
    {
        return DB::table('mail_templates')->where('account_id', $a->id)->orderBy('name')->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'subject' => (string) $r->subject, 'body_html' => (string) $r->body_html])->all();
    }

    public function saveTemplate(MailAccount $a, array $in, ?int $id = null): array
    {
        $name = Str::limit(trim((string) ($in['name'] ?? '')), 100, '');
        if ($name === '') {
            $this->fail('Give the template a name.');
        }
        $raw = (string) ($in['body_html'] ?? '');
        if (strlen($raw) > self::TPL_KB * 1024) {
            $this->fail('Template is too large.');
        }
        $subject = Str::limit(trim((string) ($in['subject'] ?? '')), 250, '');
        $html = MailHtmlSanitizer::clean($raw);
        $row = ['name' => $name, 'subject' => $subject, 'body_html' => $html, 'updated_at' => now()];
        if ($id) {
            $n = DB::table('mail_templates')->where('id', $id)->where('account_id', $a->id)->update($row);
            if (!$n && !DB::table('mail_templates')->where('id', $id)->where('account_id', $a->id)->exists()) {
                $this->fail('Template not found.', 404);
            }
        } else {
            if (DB::table('mail_templates')->where('account_id', $a->id)->count() >= self::MAX_TEMPLATES) {
                $this->fail('Too many templates.');
            }
            $id = DB::table('mail_templates')->insertGetId($row + ['account_id' => $a->id, 'created_at' => now()]);
        }
        return ['id' => (int) $id, 'name' => $name, 'subject' => $subject, 'body_html' => $html];
    }

    public function deleteTemplate(MailAccount $a, int $id): void
    {
        if (!DB::table('mail_templates')->where('id', $id)->where('account_id', $a->id)->delete()) {
            $this->fail('Template not found.', 404);
        }
    }

    /* ------------------------------------------------------------------ filters */

    private function filterPayload(object $r): array
    {
        return ['id' => (int) $r->id, 'name' => $r->name, 'conditions' => json_decode((string) $r->conditions, true) ?: new \stdClass(),
            'actions' => json_decode((string) $r->actions, true) ?: new \stdClass(), 'is_active' => (bool) $r->is_active, 'position' => (int) $r->position];
    }

    public function filters(MailAccount $a): array
    {
        return DB::table('mail_filters')->where('account_id', $a->id)->orderBy('position')->orderBy('id')->get()->map(fn ($r) => $this->filterPayload($r))->all();
    }

    public function saveFilter(MailAccount $a, array $in, ?int $id = null): array
    {
        $name = Str::limit(trim((string) ($in['name'] ?? '')), 100, '');
        $c = [];
        foreach (['from', 'to', 'subject', 'has_words', 'not_words'] as $k) {
            $v = Str::limit(trim((string) ($in['conditions'][$k] ?? '')), 190, '');
            if ($v !== '') {
                $c[$k] = $v;
            }
        }
        if (!empty($in['conditions']['has_attachment'])) {
            $c['has_attachment'] = true;
        }
        if (!$c) {
            $this->fail('Add at least one condition.');
        }
        $act = [];
        foreach (['archive', 'mark_read', 'star', 'spam', 'trash'] as $k) {
            if (!empty($in['actions'][$k])) {
                $act[$k] = true;
            }
        }
        if (!empty($in['actions']['label_id'])) {
            $lid = (int) $in['actions']['label_id'];
            if (!DB::table('mail_labels')->where('id', $lid)->where('account_id', $a->id)->exists()) {
                $this->fail('Label not found.', 404);
            }
            $act['label_id'] = $lid;
        }
        if (!$act) {
            $this->fail('Choose at least one action.');
        }
        if ($name === '') {
            $name = Str::limit(implode(' · ', array_map(fn ($k, $v) => is_bool($v) ? $k : $v, array_keys($c), $c)), 100, '');
        }
        $row = ['name' => $name, 'conditions' => json_encode($c, JSON_UNESCAPED_UNICODE), 'actions' => json_encode($act),
            'is_active' => array_key_exists('is_active', $in) ? (int) (bool) $in['is_active'] : 1, 'updated_at' => now()];
        if ($id) {
            $n = DB::table('mail_filters')->where('id', $id)->where('account_id', $a->id)->update($row);
            if (!$n && !DB::table('mail_filters')->where('id', $id)->where('account_id', $a->id)->exists()) {
                $this->fail('Filter not found.', 404);
            }
        } else {
            if (DB::table('mail_filters')->where('account_id', $a->id)->count() >= self::MAX_FILTERS) {
                $this->fail('Too many filters.');
            }
            $pos = (int) DB::table('mail_filters')->where('account_id', $a->id)->max('position') + 1;
            $id = DB::table('mail_filters')->insertGetId($row + ['account_id' => $a->id, 'position' => $pos, 'created_at' => now()]);
        }
        return $this->filterPayload(DB::table('mail_filters')->where('id', $id)->first());
    }

    public function deleteFilter(MailAccount $a, int $id): void
    {
        if (!DB::table('mail_filters')->where('id', $id)->where('account_id', $a->id)->delete()) {
            $this->fail('Filter not found.', 404);
        }
    }

    /** يطبّق فلتر على آخر 500 رسالة في الوارد (غير المؤجّلة). يرجّع عدد الرسائل اللي اتغيّرت. */
    public function applyFilterToInbox(MailAccount $a, int $id): int
    {
        $f = DB::table('mail_filters')->where('id', $id)->where('account_id', $a->id)->first();
        if (!$f) {
            $this->fail('Filter not found.', 404);
        }
        $cond = json_decode((string) $f->conditions, true) ?: [];
        $act = json_decode((string) $f->actions, true) ?: [];
        $labelIds = DB::table('mail_labels')->where('account_id', $a->id)->pluck('id')->map(fn ($x) => (int) $x)->all();
        $rows = DB::table('mail_items as i')->join('mail_messages as m', 'm.id', '=', 'i.message_id')->join('mail_accounts as s', 's.id', '=', 'm.sender_account_id')
            ->where('i.account_id', $a->id)->where('i.folder', 'inbox')->whereNull('i.snoozed_until')->orderByDesc('i.sort_at')->limit(500)
            ->get(['i.id', 'i.message_id', 'm.subject', 'm.body_text', 'm.ext_from_address', 'm.ext_from_name', 's.address as from_address', 's.display_name as from_name']);
        if ($rows->isEmpty()) {
            return 0;
        }
        $to = DB::table('mail_recipients')->whereIn('message_id', $rows->pluck('message_id')->all())->where('kind', '!=', 'bcc')->get(['message_id', 'address'])->groupBy('message_id');
        $att = DB::table('mail_attachments')->whereIn('message_id', $rows->pluck('message_id')->all())->distinct()->pluck('message_id')->flip();
        $n = 0;
        foreach ($rows as $r) {
            $ctx = ['from' => (string) ($r->ext_from_address ?: $r->from_address), 'from_name' => (string) ($r->ext_from_name ?: $r->from_name),
                'to' => ($to[$r->message_id] ?? collect())->pluck('address')->all(), 'subject' => (string) $r->subject, 'text' => (string) $r->body_text,
                'has_attachment' => isset($att[$r->message_id])];
            if (!MailFilterEngine::matches($cond, $ctx)) {
                continue;
            }
            $out = ['folder' => 'inbox', 'read' => false, 'star' => false, 'labels' => []];
            MailFilterEngine::fold($out, $act, $labelIds);
            $upd = [];
            if ($out['read']) { $upd['is_read'] = 1; }
            if ($out['star']) { $upd['is_starred'] = 1; }
            if ($out['folder'] !== 'inbox') {
                $upd['folder'] = $out['folder'];
                if ($out['folder'] === 'trash') { $upd['prev_folder'] = 'inbox'; $upd['trashed_at'] = now(); }
            }
            if ($upd) {
                DB::table('mail_items')->where('id', $r->id)->update($upd + ['updated_at' => now()]);
            }
            foreach ($out['labels'] as $lid) {
                DB::table('mail_item_labels')->insertOrIgnore(['item_id' => $r->id, 'label_id' => $lid]);
            }
            $n++;
        }
        return $n;
    }

    /* ------------------------------------------------------------------ vacation */

    public function vacationActive(object $v, ?Carbon $now = null): bool
    {
        $now = $now ?: now();
        if (!$v->enabled) {
            return false;
        }
        if ($v->starts_at && $now->lt(Carbon::parse($v->starts_at, 'UTC'))) {
            return false;
        }
        if ($v->ends_at && $now->gt(Carbon::parse($v->ends_at, 'UTC'))) {
            return false;
        }
        return true;
    }

    public function vacation(MailAccount $a): array
    {
        $v = DB::table('mail_vacation')->where('account_id', $a->id)->first();
        if (!$v) {
            return ['enabled' => false, 'subject' => '', 'body_html' => '', 'starts_at' => null, 'ends_at' => null, 'active' => false];
        }
        return ['enabled' => (bool) $v->enabled, 'subject' => (string) $v->subject, 'body_html' => (string) $v->body_html,
            'starts_at' => self::iso($v->starts_at), 'ends_at' => self::iso($v->ends_at), 'active' => $this->vacationActive($v)];
    }

    public function saveVacation(MailAccount $a, array $in): array
    {
        $subject = Str::limit(trim((string) ($in['subject'] ?? '')), 250, '');
        $raw = (string) ($in['body_html'] ?? '');
        if (strlen($raw) > self::SIG_KB * 1024) {
            $this->fail('Message is too large.');
        }
        $html = MailHtmlSanitizer::clean($raw);
        $enabled = !empty($in['enabled']);
        $starts = !empty($in['starts_at']) ? Carbon::parse($in['starts_at'])->utc() : null;
        $ends = !empty($in['ends_at']) ? Carbon::parse($in['ends_at'])->utc() : null;
        if ($starts && $ends && $ends->lte($starts)) {
            $this->fail('The end date must be after the start date.');
        }
        if ($enabled && $subject === '' && trim(strip_tags($html)) === '') {
            $this->fail('Write the automatic reply first.');
        }
        $row = ['enabled' => (int) $enabled, 'subject' => $subject, 'body_html' => $html, 'starts_at' => $starts, 'ends_at' => $ends, 'updated_at' => now()];
        if (DB::table('mail_vacation')->where('account_id', $a->id)->exists()) {
            DB::table('mail_vacation')->where('account_id', $a->id)->update($row);
        } else {
            DB::table('mail_vacation')->insert($row + ['account_id' => $a->id, 'created_at' => now()]);
        }
        return $this->vacation($a);
    }
}
