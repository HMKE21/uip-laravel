<?php

namespace App\Support;

/**
 * قواعد حساب سلسلة الهاش لـ audit_logs (بند 6).
 *
 * entry_hash = sha256( prev_hash | canonical_json(payload) )
 * الـ payload بيشمل: الرقم التسلسلي، الفاعل (actor_ref)، الإجراء، الموضوع، القيم القديمة/الجديدة، الـ IP، وقت الكتابة.
 * الـ JSON بيتطبّع (ترتيب المفاتيح) لأن MySQL بيعيد ترتيب مفاتيح عمود JSON، فلازم الهاش ميعتمدش على الترتيب.
 *
 * التوقيع (HMAC) بمفتاح مشتق من APP_KEY: اللي يعدّل الداتابيز بس (من غير APP_KEY) يقدر يعيد حساب السلسلة كلها
 * لكن مش يقدر يزوّر نقاط التحقق (checkpoints) ولا المراسي (anchors).
 */
final class AuditChain
{
    public const VERSION = 1;

    public static function genesis(): string
    {
        return str_repeat('0', 64);
    }

    /** @param mixed $value */
    public static function canonical($value): string
    {
        return json_encode(self::normalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: 'null';
    }

    /** @param mixed $v @return mixed */
    private static function normalize($v)
    {
        if (!is_array($v)) {
            return $v;
        }
        if (array_is_list($v)) {
            return array_map([self::class, 'normalize'], $v);
        }
        ksort($v, SORT_STRING);
        foreach ($v as $k => $item) {
            $v[$k] = self::normalize($item);
        }

        return (object) $v;
    }

    /**
     * @param array{chain_seq:int,actor_ref:?int,action:string,subject_type:?string,subject_id:?int,old_values:?array,new_values:?array,ip_address:?string,created_at:string} $r
     */
    public static function payload(array $r): string
    {
        return self::canonical([
            'v'     => self::VERSION,
            'seq'   => (int) $r['chain_seq'],
            'actor' => $r['actor_ref'] === null ? null : (int) $r['actor_ref'],
            'act'   => (string) $r['action'],
            'st'    => $r['subject_type'] === null ? null : (string) $r['subject_type'],
            'sid'   => $r['subject_id'] === null ? null : (int) $r['subject_id'],
            'old'   => $r['old_values'],
            'new'   => $r['new_values'],
            'ip'    => $r['ip_address'] === null ? null : (string) $r['ip_address'],
            'at'    => (string) $r['created_at'],
        ]);
    }

    public static function hash(string $prevHash, array $row): string
    {
        return hash('sha256', $prevHash . '|' . self::payload($row));
    }

    /** يحوّل صف خام من DB::table('audit_logs') لشكل الـ payload. @return array<string,mixed> */
    public static function fromRaw(object $row): array
    {
        $dec = static function ($v) {
            if ($v === null || $v === '') {
                return null;
            }
            $d = json_decode((string) $v, true);

            return is_array($d) ? $d : null;
        };

        return [
            'chain_seq'    => (int) $row->chain_seq,
            'actor_ref'    => $row->actor_ref === null ? null : (int) $row->actor_ref,
            'action'       => (string) $row->action,
            'subject_type' => $row->subject_type,
            'subject_id'   => $row->subject_id === null ? null : (int) $row->subject_id,
            'old_values'   => $dec($row->old_values),
            'new_values'   => $dec($row->new_values),
            'ip_address'   => $row->ip_address,
            'created_at'   => (string) $row->created_at,
        ];
    }

    public static function sign(int $seq, string $hash, int $count): string
    {
        $key = hash('sha256', 'uip-audit-chain|' . (string) config('app.key'));

        return hash_hmac('sha256', "audit-chain|{$seq}|{$hash}|{$count}", $key);
    }

    public static function signatureValid(int $seq, string $hash, int $count, string $signature): bool
    {
        return hash_equals(self::sign($seq, $hash, $count), $signature);
    }
}
