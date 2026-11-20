<?php

namespace App\Services\Developer;

/**
 * توقيع الـ Webhooks والتحقق منه (Replay Protection) — المصدر الوحيد للخوارزمية.
 *
 *   X-UIPE-Signature: t=<unix seconds>,v1=<hex>
 *   v1 = HMAC-SHA256(secret, t . "." . rawBody)
 *
 * التحقق (verifySignature):
 *  1) الهيدر سليم (t أرقام بس، v1 = 64 hex).
 *  2) التوقيع يطابق (مقارنة ثابتة الزمن hash_equals) — مع أكتر من secret وأكتر من v1 (للـ rotation).
 *  3) |الوقت الحالي − t| ≤ النافذة (افتراضي 300 ثانية = 5 دقايق) في الاتجاهين، فالرسالة القديمة أو المؤرّخة بالمستقبل مرفوضة.
 * التوقيع بيتفحص قبل الوقت، فالسبب timestamp_* مبيظهرش إلا لتوقيع صحيح.
 */
final class WebhookSignature
{
    public const HEADER = 'X-UIPE-Signature';
    public const SCHEME = 'v1';
    public const DEFAULT_TOLERANCE = 300;
    private const MAX_HEADER = 1024;
    private const MAX_SIGNATURES = 5;

    /** نافذة القبول بالثواني (config developer.webhooks.tolerance). */
    public static function tolerance(): int
    {
        $t = (int) config('developer.webhooks.tolerance', self::DEFAULT_TOLERANCE);
        return $t > 0 ? $t : self::DEFAULT_TOLERANCE;
    }

    public static function sign(string $secret, int $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /** قيمة الهيدر الكاملة: t=...,v1=... */
    public static function header(string $secret, string $body, ?int $timestamp = null): string
    {
        $t = $timestamp ?? time();
        return 't=' . $t . ',' . self::SCHEME . '=' . self::sign($secret, $t, $body);
    }

    /**
     * @return array{t:int,v1:string[]}|null  null لو الهيدر بايظ
     */
    public static function parse(?string $header): ?array
    {
        $header = trim((string) $header);
        if ($header === '' || strlen($header) > self::MAX_HEADER) {
            return null;
        }
        $t = null;
        $sigs = [];
        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $eq = strpos($part, '=');
            if ($eq === false || $eq === 0) {
                return null;
            }
            $key = substr($part, 0, $eq);
            $val = substr($part, $eq + 1);
            if ($key === 't') {
                if ($t !== null || !preg_match('/^[0-9]{1,12}$/', $val)) {
                    return null;
                }
                $t = (int) $val;
            } elseif ($key === self::SCHEME) {
                if (!preg_match('/^[0-9a-fA-F]{64}$/', $val) || count($sigs) >= self::MAX_SIGNATURES) {
                    return null;
                }
                $sigs[] = strtolower($val);
            } // مفاتيح غير معروفة (v0 مثلاً) بتتجاهل عشان نقدر نضيف إصدارات بعدين
        }
        if ($t === null || !$sigs) {
            return null;
        }
        return ['t' => $t, 'v1' => $sigs];
    }

    /**
     * تحقق كامل مع السبب.
     * @param string|string[] $secrets secret واحد أو أكتر (أثناء الـ rotation)
     * @return array{valid:bool,reason:?string,timestamp:?int,age_seconds:?int,tolerance_seconds:int}
     */
    public static function inspect(string $payload, ?string $header, string|array $secrets, ?int $tolerance = null, ?int $now = null): array
    {
        $tolerance = $tolerance !== null && $tolerance > 0 ? $tolerance : self::tolerance();
        $now = $now ?? time();
        $res = ['valid' => false, 'reason' => null, 'timestamp' => null, 'age_seconds' => null, 'tolerance_seconds' => $tolerance];

        if (trim((string) $header) === '') {
            $res['reason'] = 'missing_header';
            return $res;
        }
        $p = self::parse($header);
        if ($p === null) {
            $res['reason'] = 'malformed_header';
            return $res;
        }
        $age = $now - $p['t'];
        $res['timestamp'] = $p['t'];
        $res['age_seconds'] = $age;

        $secrets = array_values(array_filter((array) $secrets, fn ($s) => is_string($s) && $s !== ''));
        if (!$secrets) {
            $res['reason'] = 'missing_secret';
            return $res;
        }
        $match = false;
        foreach ($secrets as $secret) {
            $expected = self::sign($secret, $p['t'], $payload);
            foreach ($p['v1'] as $given) {
                if (hash_equals($expected, $given)) {
                    $match = true; // مفيش break: الوقت ثابت تقريبًا مهما كان الترتيب
                }
            }
        }
        if (!$match) {
            $res['reason'] = 'signature_mismatch';
            return $res;
        }
        if ($age > $tolerance) {
            $res['reason'] = 'timestamp_too_old';
            return $res;
        }
        if (-$age > $tolerance) {
            $res['reason'] = 'timestamp_in_future';
            return $res;
        }
        $res['valid'] = true;
        return $res;
    }

    /**
     * الدالة اللي بيستعملها المستقبِل: true لو التوقيع سليم وجوه النافذة.
     * @param string|string[] $secrets
     */
    public static function verifySignature(string $payload, ?string $header, string|array $secrets, ?int $tolerance = null, ?int $now = null): bool
    {
        return self::inspect($payload, $header, $secrets, $tolerance, $now)['valid'];
    }

    public static function explain(?string $reason): string
    {
        return match ($reason) {
            null => 'The signature is valid and the timestamp is inside the allowed window.',
            'missing_header' => 'The ' . self::HEADER . ' header is missing or empty.',
            'malformed_header' => 'The signature header is not in the form t=<unix seconds>,v1=<64 hex characters>.',
            'missing_secret' => 'The webhook secret is unreadable. Rotate the secret and try again.',
            'signature_mismatch' => 'The signature does not match. Use the raw request body (byte for byte) and the webhook secret of this webhook.',
            'timestamp_too_old' => 'The signature is valid but older than the allowed window: reject it (replay protection).',
            'timestamp_in_future' => 'The signature is valid but its timestamp is too far in the future: check your clock, or reject it.',
            default => 'Unknown result.',
        };
    }

    /** وصف الخوارزمية + test vector ثابت (يتحط في الـ docs والـ SDKs tests). */
    public static function describe(): array
    {
        $secret = 'whsec_test_secret';
        $t = 1700000000;
        $body = '{"id":"evt_test","type":"webhook.test"}';
        $tol = self::tolerance();
        return [
            'header' => self::HEADER,
            'format' => 't=<unix seconds>,v1=<hex>',
            'algorithm' => 'HMAC-SHA256',
            'signed_payload' => '{t}.{raw_body}',
            'tolerance_seconds' => $tol,
            'dedupe_ttl_seconds' => $tol * 2,
            'headers' => [
                'X-UIPE-Signature' => 'Timestamp and signature. Fresh on every attempt (retries are re-signed).',
                'X-UIPE-Event' => 'Event type, e.g. mail.message.received.',
                'X-UIPE-Event-Id' => 'Stable across retries and redeliveries. Use it to de-duplicate.',
                'X-UIPE-Delivery' => 'Delivery attempt record id.',
            ],
            'steps' => [
                'Read the raw request body before any JSON parsing.',
                'Parse X-UIPE-Signature into t and one or more v1 values.',
                'Compute HMAC-SHA256(secret, t + "." + raw_body) as lowercase hex.',
                'Compare with every v1 value in constant time. No match: reject.',
                'Reject when |now - t| is more than ' . $tol . ' seconds (too old or too far in the future).',
                'Store X-UIPE-Event-Id for at least ' . ($tol * 2) . ' seconds and ignore an id you already processed.',
                'Answer any 2xx within 8 seconds. Do the heavy work after answering.',
            ],
            'test_vector' => [
                'secret' => $secret,
                'timestamp' => $t,
                'body' => $body,
                'signature' => self::sign($secret, $t, $body),
                'header' => self::header($secret, $body, $t),
            ],
        ];
    }
}
