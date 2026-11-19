<?php

namespace Tests\Unit;

use App\Support\WebAuthn\Cbor;
use App\Support\WebAuthn\WebAuthn;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Passkeys: مصادق برمجي بيعمل تسجيل ودخول حقيقيين على ES256 و RS256 ويجرّب التلاعب. */
class WebAuthnTest extends TestCase
{
    private const RP = 'uipe.test';
    private const ORIGIN = 'https://uipe.test';

    private function cHead(int $major, int $n): string
    {
        if ($n < 24) {
            return chr(($major << 5) | $n);
        }
        if ($n < 256) {
            return chr(($major << 5) | 24) . chr($n);
        }
        return chr(($major << 5) | 25) . pack('n', $n);
    }

    private function cEnc($v): string
    {
        if (is_int($v)) {
            return $v >= 0 ? $this->cHead(0, $v) : $this->cHead(1, -1 - $v);
        }
        if (is_string($v)) {
            return $this->cHead(2, strlen($v)) . $v;
        }
        if (is_array($v) && array_is_list($v)) {
            return $this->cHead(4, count($v)) . implode('', array_map(fn ($x) => $this->cEnc($x), $v));
        }
        $o = $this->cHead(5, count($v));
        foreach ($v as $k => $x) {
            $o .= (is_string($k) ? $this->cHead(3, strlen($k)) . $k : $this->cEnc($k)) . $this->cEnc($x);
        }
        return $o;
    }

    /** @return array{0:\OpenSSLAsymmetricKey,1:array} */
    private function newKey(string $alg): array
    {
        if ($alg === 'ES256') {
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
            $d = openssl_pkey_get_details($key)['ec'];
            return [$key, [1 => 2, 3 => -7, -1 => 1, -2 => str_pad($d['x'], 32, "\0", STR_PAD_LEFT), -3 => str_pad($d['y'], 32, "\0", STR_PAD_LEFT)]];
        }
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $d = openssl_pkey_get_details($key)['rsa'];
        return [$key, [1 => 3, 3 => -257, -1 => $d['n'], -2 => $d['e']]];
    }

    private function clientData(string $type, string $challenge, string $origin = self::ORIGIN): string
    {
        return json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => false]);
    }

    private function authData(int $flags, int $counter, ?array $cose = null, string $credId = ''): string
    {
        $ad = hash('sha256', self::RP, true) . chr($flags) . pack('N', $counter);
        if ($cose !== null) {
            $ad .= str_repeat("\x01", 16) . pack('n', strlen($credId)) . $credId . $this->cEnc($cose);
        }
        return $ad;
    }

    private function register(string $alg, ?array &$out = null): array
    {
        [$priv, $cose] = $this->newKey($alg);
        $credId = random_bytes(32);
        $challenge = WebAuthn::b64uEncode(random_bytes(32));
        $att = $this->cEnc(['fmt' => 'none', 'attStmt' => [], 'authData' => $this->authData(0x45 | 0x08 | 0x10, 0, $cose, $credId)]);
        $res = WebAuthn::verifyRegistration($this->clientData('webauthn.create', $challenge), $att, $challenge, [self::ORIGIN], self::RP);
        $out = ['priv' => $priv, 'credId' => $credId];
        return $res;
    }

    private function sign($priv, string $ad, string $cd): string
    {
        openssl_sign($ad . hash('sha256', $cd, true), $sig, $priv, OPENSSL_ALGO_SHA256);
        return $sig;
    }

    #[Test]
    public function cbor_decodes_scalars_maps_and_negative_keys(): void
    {
        $data = $this->cEnc(['a' => 1, 'b' => [1, -7, 'x'], 3 => -7, -2 => 'yy']);
        $off = 0;
        $this->assertSame(['a' => 1, 'b' => [1, -7, 'x'], 3 => -7, -2 => 'yy'], Cbor::decode($data, $off));
        $this->assertSame(strlen($data), $off);
    }

    #[Test]
    public function cbor_rejects_truncated_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $off = 0;
        Cbor::decode("\x58\x10abc", $off);
    }

    #[Test]
    public function es256_register_then_login_succeeds_and_counter_must_increase(): void
    {
        $reg = $this->register('ES256', $ctx);
        $this->assertSame(WebAuthn::b64uEncode($ctx['credId']), $reg['credential_id']);
        $this->assertSame(-7, $reg['alg']);
        $this->assertTrue($reg['backed_up']);

        $ch = WebAuthn::b64uEncode(random_bytes(32));
        $cd = $this->clientData('webauthn.get', $ch);
        $ad = $this->authData(0x05, 5);
        $r = WebAuthn::verifyAssertion($cd, $ad, $this->sign($ctx['priv'], $ad, $cd), $ch, [self::ORIGIN], self::RP, $reg['public_key_pem'], 0);
        $this->assertSame(5, $r['sign_count']);

        $this->expectException(\InvalidArgumentException::class);   // نفس العدّاد تاني = جهاز منسوخ
        WebAuthn::verifyAssertion($cd, $ad, $this->sign($ctx['priv'], $ad, $cd), $ch, [self::ORIGIN], self::RP, $reg['public_key_pem'], 5);
    }

    #[Test]
    public function rs256_register_then_login_succeeds_with_zero_counters(): void
    {
        $reg = $this->register('RS256', $ctx);
        $this->assertSame(-257, $reg['alg']);
        $ch = WebAuthn::b64uEncode(random_bytes(32));
        $cd = $this->clientData('webauthn.get', $ch);
        $ad = $this->authData(0x05, 0);
        $r = WebAuthn::verifyAssertion($cd, $ad, $this->sign($ctx['priv'], $ad, $cd), $ch, [self::ORIGIN], self::RP, $reg['public_key_pem'], 0);
        $this->assertSame(0, $r['sign_count']);
    }

    #[Test]
    public function assertion_is_rejected_when_tampered(): void
    {
        $reg = $this->register('ES256', $ctx);
        foreach (['challenge', 'origin', 'rp', 'uv', 'sig', 'type'] as $case) {
            $ch = WebAuthn::b64uEncode(random_bytes(32));
            $cd = $this->clientData($case === 'type' ? 'webauthn.create' : 'webauthn.get', $case === 'challenge' ? 'AAAA' : $ch, $case === 'origin' ? 'https://evil.test' : self::ORIGIN);
            $ad = $case === 'rp' ? hash('sha256', 'evil.test', true) . "\x05" . pack('N', 9) : $this->authData($case === 'uv' ? 0x01 : 0x05, 9);
            $sig = $this->sign($ctx['priv'], $ad, $cd);
            if ($case === 'sig') {
                $sig[10] = chr(ord($sig[10]) ^ 0xff);
            }
            try {
                WebAuthn::verifyAssertion($cd, $ad, $sig, $ch, [self::ORIGIN], self::RP, $reg['public_key_pem'], 0);
                $this->fail("tampered case accepted: {$case}");
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    #[Test]
    public function signature_from_another_key_is_rejected(): void
    {
        $reg = $this->register('ES256', $ctx);
        [$otherPriv] = $this->newKey('ES256');
        $ch = WebAuthn::b64uEncode(random_bytes(32));
        $cd = $this->clientData('webauthn.get', $ch);
        $ad = $this->authData(0x05, 1);
        $this->expectException(\InvalidArgumentException::class);
        WebAuthn::verifyAssertion($cd, $ad, $this->sign($otherPriv, $ad, $cd), $ch, [self::ORIGIN], self::RP, $reg['public_key_pem'], 0);
    }

    #[Test]
    public function registration_without_user_verification_is_rejected(): void
    {
        [, $cose] = $this->newKey('ES256');
        $ch = WebAuthn::b64uEncode(random_bytes(32));
        $att = $this->cEnc(['fmt' => 'none', 'attStmt' => [], 'authData' => $this->authData(0x41, 0, $cose, random_bytes(16))]);
        $this->expectException(\InvalidArgumentException::class);
        WebAuthn::verifyRegistration($this->clientData('webauthn.create', $ch), $att, $ch, [self::ORIGIN], self::RP);
    }
}
