<?php

namespace App\Services\Mail;

use Illuminate\Support\Facades\DB;

/**
 * UIPE Mail — فلاتر الوارد. بتتطبّق وقت التسليم على نسخة كل مستلم.
 * conditions: { from?, to?, subject?, has_words?, not_words?, has_attachment? }  (كلهم AND)
 * actions:    { label_id?, archive?, mark_read?, star?, spam?, trash? }
 */
class MailFilterEngine
{
    public const COND_KEYS = ['from', 'to', 'subject', 'has_words', 'not_words', 'has_attachment'];
    public const ACTION_KEYS = ['label_id', 'archive', 'mark_read', 'star', 'spam', 'trash'];

    private static function has(string $hay, string $needle): bool
    {
        return $needle !== '' && mb_stripos($hay, $needle) !== false;
    }

    /** @param array $c شروط الفلتر
     *  @param array{from:string,from_name?:string,to:array,subject:string,text:string,has_attachment:bool} $ctx */
    public static function matches(array $c, array $ctx): bool
    {
        $any = false;
        foreach (['from', 'to', 'subject', 'has_words', 'not_words'] as $k) {
            $v = trim((string) ($c[$k] ?? ''));
            if ($v === '') {
                continue;
            }
            $any = true;
            $ok = match ($k) {
                'from' => self::has($ctx['from'] . ' ' . ($ctx['from_name'] ?? ''), $v),
                'to' => self::has(implode(' ', $ctx['to']), $v),
                'subject' => self::has($ctx['subject'], $v),
                'has_words' => self::has($ctx['subject'] . ' ' . $ctx['text'], $v),
                'not_words' => !self::has($ctx['subject'] . ' ' . $ctx['text'], $v),
            };
            if (!$ok) {
                return false;
            }
        }
        if (!empty($c['has_attachment'])) {
            $any = true;
            if (!$ctx['has_attachment']) {
                return false;
            }
        }
        return $any; // فلتر من غير شروط ما بيطابق أي حاجة
    }

    /** يرجّع ما يجب تطبيقه على نسخة الوارد: ['folder','read','star','labels'=>int[]] */
    public function evaluate(int $accountId, array $ctx): array
    {
        $out = ['folder' => 'inbox', 'read' => false, 'star' => false, 'labels' => []];
        $filters = DB::table('mail_filters')->where('account_id', $accountId)->where('is_active', 1)->orderBy('position')->orderBy('id')->get();
        if ($filters->isEmpty()) {
            return $out;
        }
        $labelIds = DB::table('mail_labels')->where('account_id', $accountId)->pluck('id')->map(fn ($x) => (int) $x)->all();
        foreach ($filters as $f) {
            $cond = json_decode((string) $f->conditions, true) ?: [];
            if (!self::matches($cond, $ctx)) {
                continue;
            }
            $act = json_decode((string) $f->actions, true) ?: [];
            self::fold($out, $act, $labelIds);
        }
        return $out;
    }

    /** يدمج إجراءات فلتر في النتيجة (الأولوية: حذف > مزعج > أرشفة). */
    public static function fold(array &$out, array $act, array $validLabelIds): void
    {
        if (!empty($act['mark_read'])) {
            $out['read'] = true;
        }
        if (!empty($act['star'])) {
            $out['star'] = true;
        }
        if (!empty($act['label_id']) && in_array((int) $act['label_id'], $validLabelIds, true)) {
            $out['labels'][] = (int) $act['label_id'];
            $out['labels'] = array_values(array_unique($out['labels']));
        }
        if (!empty($act['trash'])) {
            $out['folder'] = 'trash';
        } elseif (!empty($act['spam']) && $out['folder'] !== 'trash') {
            $out['folder'] = 'spam';
        } elseif (!empty($act['archive']) && $out['folder'] === 'inbox') {
            $out['folder'] = 'archive';
        }
    }
}
