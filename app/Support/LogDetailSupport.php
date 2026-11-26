<?php

namespace App\Support;

use App\Models\BlockedIp;
use Illuminate\Support\Facades\DB;

/**
 * مساعد صفحة "نشاط النظام" (Security events + Admin actions): بيجهّز تفاصيل الصف عشان لوحة التفاصيل في الأدمن.
 *  - redact(): بيخفي أي قيمة حساسة (باسورد/توكن/سر/كوكي…) قبل ما تتبعت للمتصفح، ويقصّ النصوص الطويلة.
 *  - decode(): old_values/new_values بتتخزّن JSON string في الجدول، هنا بترجع array مخفي الحساس.
 *  - usersFor(): استعلام واحد لكل الصفحة (مش استعلام لكل صف) يرجّع uuid/الاسم/الحالة للمستخدمين اللي في الصفحة.
 *  - blockedSet(): أي IP من اللي في الصفحة محظور فعلًا.
 */
class LogDetailSupport
{
    private const SENSITIVE = '/(pass(word)?|pwd|token|secret|authorization|cookie|otp|api[_-]?key|private|signature|credential|recovery_code|backup_code|session_id)/i';

    public static function redact(mixed $value, int $depth = 0, string $key = ''): mixed
    {
        if ($key !== '' && preg_match(self::SENSITIVE, $key)) {
            return '[hidden]';
        }
        if (is_array($value)) {
            if ($depth >= 4) {
                return '[…]';
            }
            $out = [];
            $n = 0;
            foreach ($value as $k => $v) {
                if (++$n > 40) {
                    $out['…'] = '+' . (count($value) - 40) . ' more';
                    break;
                }
                $out[$k] = self::redact($v, $depth + 1, is_string($k) ? $k : '');
            }
            return $out;
        }
        if (is_string($value) && mb_strlen($value) > 500) {
            return mb_substr($value, 0, 500) . '…';
        }
        return $value;
    }

    public static function decode(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $arr = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (!is_array($arr)) {
            return ['value' => self::redact((string) $raw)];
        }
        return self::redact($arr);
    }

    /**
     * @param array<int,mixed> $ids    أرقام users.id
     * @param array<int,string> $emails
     * @return array{byId:array<int,array>,byEmail:array<string,array>}
     */
    public static function usersFor(array $ids, array $emails): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $emails = array_values(array_unique(array_filter(array_map('strval', $emails))));
        $byId = [];
        $byEmail = [];
        if (!$ids && !$emails) {
            return ['byId' => $byId, 'byEmail' => $byEmail];
        }
        try {
            $rows = DB::table('users')
                ->where(function ($w) use ($ids, $emails) {
                    if ($ids) {
                        $w->whereIn('id', $ids);
                    }
                    if ($emails) {
                        $w->orWhereIn('email', $emails);
                    }
                })
                ->select('id', 'uuid', 'full_name', 'email', 'status')
                ->get();
            foreach ($rows as $r) {
                $row = ['id' => (int) $r->id, 'uuid' => $r->uuid, 'name' => $r->full_name, 'email' => $r->email, 'status' => $r->status];
                $byId[(int) $r->id] = $row;
                $byEmail[strtolower((string) $r->email)] = $row;
            }
        } catch (\Throwable $e) {
            // لو الجدول/العمود مش موجود، التفاصيل تتعرض من غير ربط بالمستخدم بدل ما الصفحة كلها تقع.
        }
        return ['byId' => $byId, 'byEmail' => $byEmail];
    }

    /** @param array<int,string> $ips @return array<string,bool> */
    public static function blockedSet(array $ips): array
    {
        $ips = array_values(array_unique(array_filter($ips, fn ($ip) => $ip && $ip !== '—')));
        if (!$ips) {
            return [];
        }
        try {
            return array_fill_keys(BlockedIp::whereIn('ip_address', $ips)->pluck('ip_address')->all(), true);
        } catch (\Throwable $e) {
            return [];
        }
    }
}
