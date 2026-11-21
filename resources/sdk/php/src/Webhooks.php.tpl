<?php

declare(strict_types=1);

namespace Uipe;

/**
 * Webhook signature verification with replay protection.
 *
 *   X-UIPE-Signature: t=<unix seconds>,v1=<hex>
 *   v1 = HMAC-SHA256(secret, "<t>.<raw body>")
 *
 * Verify against the RAW request body (file_get_contents('php://input')), reject timestamps more than
 * `$tolerance` seconds away from now (old or future), and ignore an event id you already processed.
 */
final class Webhooks
{
    public const HEADER = 'X-UIPE-Signature';
    public const DEFAULT_TOLERANCE = {{TOLERANCE}};
    private const MAX_HEADER = 1024;
    private const MAX_SIGNATURES = 5;

    public const MESSAGES = [
        'missing_header' => 'The X-UIPE-Signature header is missing or empty.',
        'malformed_header' => 'The signature header is not in the form t=<unix seconds>,v1=<64 hex characters>.',
        'missing_secret' => 'No signing secret was given.',
        'signature_mismatch' => 'The signature does not match. Use the raw request body (byte for byte) and the secret of this webhook.',
        'timestamp_too_old' => 'The signature is valid but older than the allowed window (replay protection).',
        'timestamp_in_future' => 'The signature is valid but its timestamp is too far in the future.',
        'invalid_payload' => 'The body is not valid JSON.',
        'replayed_event' => 'This event id was already processed.',
    ];

    /** Hex HMAC-SHA256 of "<timestamp>.<payload>" (what UIPE puts in v1). */
    public static function sign(string $secret, int $timestamp, string $payload): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    }

    /** A full X-UIPE-Signature value. Useful to test your own receiver. */
    public static function buildHeader(string $secret, string $payload, ?int $timestamp = null): string
    {
        $t = $timestamp ?? time();
        return 't=' . $t . ',v1=' . self::sign($secret, $t, $payload);
    }

    /** @return array{timestamp:int,signatures:string[]}|null null when the header is malformed */
    public static function parseHeader(?string $header): ?array
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
            } elseif ($key === 'v1') {
                if (!preg_match('/^[0-9a-fA-F]{64}$/', $val) || count($sigs) >= self::MAX_SIGNATURES) {
                    return null;
                }
                $sigs[] = strtolower($val);
            } // unknown keys (v0 ...) are ignored so new versions can be added later
        }
        if ($t === null || !$sigs) {
            return null;
        }
        return ['timestamp' => $t, 'signatures' => $sigs];
    }

    /**
     * Full verification with the reason. `$secrets` is one secret or a list (two while you rotate).
     * The signature is checked before the time, so a timestamp_* reason is only given for a genuine signature.
     * @param string|string[] $secrets
     * @return array{valid:bool,reason:?string,timestamp:?int,age_seconds:?int,tolerance_seconds:int}
     */
    public static function inspect(string $payload, ?string $header, $secrets, ?int $tolerance = null, ?int $now = null): array
    {
        $tolerance = $tolerance !== null && $tolerance > 0 ? $tolerance : self::DEFAULT_TOLERANCE;
        $now ??= time();
        $res = ['valid' => false, 'reason' => null, 'timestamp' => null, 'age_seconds' => null, 'tolerance_seconds' => $tolerance];

        if (trim((string) $header) === '') {
            $res['reason'] = 'missing_header';
            return $res;
        }
        $p = self::parseHeader($header);
        if ($p === null) {
            $res['reason'] = 'malformed_header';
            return $res;
        }
        $age = $now - $p['timestamp'];
        $res['timestamp'] = $p['timestamp'];
        $res['age_seconds'] = $age;

        $secrets = array_values(array_filter((array) $secrets, static fn ($s) => is_string($s) && $s !== ''));
        if (!$secrets) {
            $res['reason'] = 'missing_secret';
            return $res;
        }
        $match = false;
        foreach ($secrets as $secret) {
            $expected = self::sign($secret, $p['timestamp'], $payload);
            foreach ($p['signatures'] as $given) {
                if (hash_equals($expected, $given)) {
                    $match = true; // no break: roughly constant time
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
     * True when the signature is genuine and the timestamp is inside the window.
     * @param string|string[] $secrets
     */
    public static function verifySignature(string $payload, ?string $header, $secrets, ?int $tolerance = null, ?int $now = null): bool
    {
        return self::inspect($payload, $header, $secrets, $tolerance, $now)['valid'];
    }

    /**
     * Verify a delivery and return the parsed event (array). Throws SignatureException (see `$reason`) when the
     * signature is bad, the timestamp is outside the window, the body is not JSON, or `$guard` already saw the event id.
     * @param string|string[] $secrets
     * @param object|null $guard anything with firstSeen(string $eventId): bool (see ReplayGuard)
     * @return mixed
     */
    public static function constructEvent(string $payload, ?string $header, $secrets, ?int $tolerance = null, ?object $guard = null, ?int $now = null)
    {
        $res = self::inspect($payload, $header, $secrets, $tolerance, $now);
        if (!$res['valid']) {
            throw new SignatureException((string) $res['reason'], self::MESSAGES[$res['reason']] ?? null);
        }
        $event = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new SignatureException('invalid_payload', self::MESSAGES['invalid_payload']);
        }
        if ($guard !== null && is_array($event) && isset($event['id']) && is_string($event['id']) && $event['id'] !== '' && !$guard->firstSeen($event['id'])) {
            throw new SignatureException('replayed_event', self::MESSAGES['replayed_event']);
        }
        return $event;
    }
}
