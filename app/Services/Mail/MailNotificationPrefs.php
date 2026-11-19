<?php

namespace App\Services\Mail;

use App\Models\Mail\MailAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * إعدادات إشعارات البريد لكل صندوق (المرحلة 6): all | vip (مرسلين محددين بس) | off + كتم لحد وقت معيّن.
 * منفصلة عن MailIntegrationService عشان MailboxService يقدر يسألها وقت التسليم من غير تبعية دائرية.
 */
class MailNotificationPrefs
{
    public const MODES = ['all', 'vip', 'off'];

    /** هل نبعت إشعار جرس لرسالة جاية من $senderAddress على الصندوق ده؟ أي خطأ = نبعت (الأسلم). */
    public static function allows(int $accountId, string $senderAddress): bool
    {
        try {
            $p = DB::table('mail_notification_prefs')->where('account_id', $accountId)->first();
            if (!$p) {
                return true;
            }
            if ($p->mute_until && now()->lt(Carbon::parse($p->mute_until, 'UTC'))) {
                return false;
            }
            if ($p->mode === 'off') {
                return false;
            }
            if ($p->mode === 'vip') {
                return in_array(strtolower(trim($senderAddress)), array_map('strtolower', (array) json_decode((string) $p->vip_senders, true)), true);
            }
            return true;
        } catch (\Throwable $e) {
            return true;
        }
    }

    public static function get(MailAccount $a): array
    {
        $p = DB::table('mail_notification_prefs')->where('account_id', $a->id)->first();
        $mute = $p && $p->mute_until ? Carbon::parse($p->mute_until, 'UTC') : null;
        return [
            'mode' => $p->mode ?? 'all', 'vip_senders' => $p ? array_values((array) json_decode((string) $p->vip_senders, true)) : [],
            'mute_until' => $mute && $mute->isFuture() ? $mute->toIso8601String() : null,
        ];
    }

    /** @param array{mode?:string,vip_senders?:array,mute_until?:?string,mute_minutes?:?int} $in */
    public static function save(MailAccount $a, array $in): array
    {
        $cur = DB::table('mail_notification_prefs')->where('account_id', $a->id)->first();
        $row = ['mode' => $cur->mode ?? 'all', 'vip_senders' => $cur->vip_senders ?? json_encode([]), 'mute_until' => $cur->mute_until ?? null];
        if (array_key_exists('mode', $in)) {
            if (!in_array($in['mode'], self::MODES, true)) {
                throw new \DomainException('Invalid notification mode.', 422);
            }
            $row['mode'] = $in['mode'];
        }
        if (array_key_exists('vip_senders', $in)) {
            $list = [];
            foreach ((array) $in['vip_senders'] as $s) {
                $s = strtolower(trim((string) $s));
                if ($s === '') {
                    continue;
                }
                if (!filter_var($s, FILTER_VALIDATE_EMAIL)) {
                    throw new \DomainException('Invalid email address: ' . mb_substr($s, 0, 60), 422);
                }
                $list[] = $s;
            }
            if (count($list) > 50) {
                throw new \DomainException('Too many VIP senders (max 50).', 422);
            }
            $row['vip_senders'] = json_encode(array_values(array_unique($list)));
        }
        if (array_key_exists('mute_minutes', $in) && $in['mute_minutes'] !== null && $in['mute_minutes'] !== '') {
            $min = (int) $in['mute_minutes'];
            if ($min < 0 || $min > 60 * 24 * 30) {
                throw new \DomainException('Mute duration must be up to 30 days.', 422);
            }
            $row['mute_until'] = $min > 0 ? now()->addMinutes($min) : null;
        } elseif (array_key_exists('mute_until', $in)) {
            try {
                $row['mute_until'] = $in['mute_until'] ? Carbon::parse((string) $in['mute_until'])->utc() : null;
            } catch (\Throwable $e) {
                throw new \DomainException('Invalid mute date.', 422);
            }
        }
        if ($cur) {
            DB::table('mail_notification_prefs')->where('account_id', $a->id)->update($row + ['updated_at' => now()]);
        } else {
            DB::table('mail_notification_prefs')->insert($row + ['account_id' => $a->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        return self::get($a);
    }
}
