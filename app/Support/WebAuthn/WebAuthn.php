<?php

namespace App\Support\WebAuthn;

/**
 * التحقق من WebAuthn (Passkeys) بدون مكتبات خارجية — openssl بس. مفيش dependency على Laravel.
 *  - التسجيل: attestation "none" → بنستخرج credentialId + المفتاح العام من authData (مفيش تحقق من سلسلة شهادات).
 *  - الدخول: توقيع على authData || SHA-256(clientDataJSON) بالمفتاح العام المخزّن. الخوارزميات: ES256 (-7) و RS256 (-257).
 * كل دالة بترمي \InvalidArgumentException لو التحقق فشل.
 */
final class WebAuthn
{
    public const ALG_ES256 = -7;
    public const ALG_RS256 = -257;

    public const FLAG_UP = 0x01;
    public const FLAG_UV = 0x04;
    public const FLAG_BE = 0x08;
    public const FLAG_BS = 0x10;
    public const FLAG_AT = 0x40;

    public static function b64uEncode(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $s): string
    {
        $r = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        if ($r === false) {
            throw new \InvalidArgumentException('Bad base64url');
        }
        return $r;
    }

    /** @param string[] $origins */
    private static function checkClientData(string $clientDataJSON, string $type, string $expectedChallenge, array $origins): void
    {
        $cd = json_decode($clientDataJSON, true);
        if (!is_array($cd)) {
            throw new \InvalidArgumentException('Bad clientDataJSON');
        }
        if (($cd['type'] ?? null) !== $type) {
            throw new \InvalidArgumentException('Wrong ceremony type');
        }
        if (!is_string($cd['challenge'] ?? null) || !hash_equals($expectedChallenge, $cd['challenge'])) {
            throw new \InvalidArgumentException('Challenge mismatch');
        }
        if (!is_string($cd['origin'] ?? null) || !in_array($cd['origin'], $origins, true)) {
            throw new \InvalidArgumentException('Origin not allowed');
        }
    }

    /** @return array{flags:int,signCount:int,aaguid:string,credentialId:string,coseKey:?array,rest:string} */
    public static function parseAuthData(string $ad, string $rpId, bool $requireUv): array
    {
        if (strlen($ad) < 37) {
            throw new \InvalidArgumentException('authData too short');
        }
        if (!hash_equals(hash('sha256', $rpId, true), substr($ad, 0, 32))) {
            throw new \InvalidArgumentException('RP ID mismatch');
        }
        $flags = ord($ad[32]);
        if (!($flags & self::FLAG_UP)) {
            throw new \InvalidArgumentException('User presence missing');
        }
        if ($requireUv && !($flags & self::FLAG_UV)) {
            throw new \InvalidArgumentException('User verification missing');
        }
        $out = ['flags' => $flags, 'signCount' => unpack('N', substr($ad, 33, 4))[1], 'aaguid' => '', 'credentialId' => '', 'coseKey' => null, 'rest' => ''];
        if ($flags & self::FLAG_AT) {
            if (strlen($ad) < 55) {
                throw new \InvalidArgumentException('authData truncated');
            }
            $out['aaguid'] = substr($ad, 37, 16);
            $idLen = unpack('n', substr($ad, 53, 2))[1];
            if ($idLen < 1 || $idLen > 1023 || strlen($ad) < 55 + $idLen) {
                throw new \InvalidArgumentException('Bad credential id length');
            }
            $out['credentialId'] = substr($ad, 55, $idLen);
            $off = 55 + $idLen;
            $out['coseKey'] = Cbor::decode($ad, $off);
            $out['rest'] = substr($ad, $off);
            if (!is_array($out['coseKey'])) {
                throw new \InvalidArgumentException('Bad COSE key');
            }
        }
        return $out;
    }

    /**
     * @param string[] $origins
     * @return array{credential_id:string,public_key_pem:string,alg:int,sign_count:int,backed_up:bool,uv:bool}
     */
    public static function verifyRegistration(string $clientDataJSON, string $attestationObject, string $expectedChallenge, array $origins, string $rpId): array
    {
        self::checkClientData($clientDataJSON, 'webauthn.create', $expectedChallenge, $origins);
        $off = 0;
        $att = Cbor::decode($attestationObject, $off);
        if (!is_array($att) || !is_string($att['authData'] ?? null)) {
            throw new \InvalidArgumentException('Bad attestationObject');
        }
        $p = self::parseAuthData($att['authData'], $rpId, true);
        if (!($p['flags'] & self::FLAG_AT) || $p['coseKey'] === null) {
            throw new \InvalidArgumentException('No credential data');
        }
        [$pem, $alg] = self::coseToPem($p['coseKey']);
        return ['credential_id' => self::b64uEncode($p['credentialId']), 'public_key_pem' => $pem, 'alg' => $alg, 'sign_count' => $p['signCount'],
            'backed_up' => (bool) ($p['flags'] & self::FLAG_BS), 'uv' => (bool) ($p['flags'] & self::FLAG_UV)];
    }

    /**
     * @param string[] $origins
     * @return array{sign_count:int,backed_up:bool}
     */
    public static function verifyAssertion(string $clientDataJSON, string $authenticatorData, string $signature, string $expectedChallenge, array $origins, string $rpId,
        string $publicKeyPem, int $storedSignCount): array
    {
        self::checkClientData($clientDataJSON, 'webauthn.get', $expectedChallenge, $origins);
        $p = self::parseAuthData($authenticatorData, $rpId, true);
        $signed = $authenticatorData . hash('sha256', $clientDataJSON, true);
        $ok = openssl_verify($signed, $signature, $publicKeyPem, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new \InvalidArgumentException('Bad signature');
        }
        // كشف النسخ: العدّاد لازم يزيد، إلا لو الجهاز بيرجّع 0 دايمًا (iCloud/Google passkeys).
        if (($p['signCount'] !== 0 || $storedSignCount !== 0) && $p['signCount'] <= $storedSignCount) {
            throw new \InvalidArgumentException('Signature counter did not increase');
        }
        return ['sign_count' => $p['signCount'], 'backed_up' => (bool) ($p['flags'] & self::FLAG_BS)];
    }

    /** @return array{0:string,1:int} [PEM, alg] */
    public static function coseToPem(array $k): array
    {
        $kty = $k[1] ?? null;
        $alg = $k[3] ?? null;
        if ($kty === 2 && $alg === self::ALG_ES256 && ($k[-1] ?? null) === 1) {
            $x = $k[-2] ?? null;
            $y = $k[-3] ?? null;
            if (!is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
                throw new \InvalidArgumentException('Bad EC key');
            }
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
            return [self::pem($der), self::ALG_ES256];
        }
        if ($kty === 3 && $alg === self::ALG_RS256) {
            $n = $k[-1] ?? null;
            $e = $k[-2] ?? null;
            if (!is_string($n) || !is_string($e) || strlen($n) < 128 || strlen($n) > 1024 || $e === '') {
                throw new \InvalidArgumentException('Bad RSA key');
            }
            $rsaKey = self::derSeq(self::derInt($n) . self::derInt($e));
            $algId = self::derSeq("\x06\x09" . hex2bin('2a864886f70d010101') . "\x05\x00");
            return [self::pem(self::derSeq($algId . "\x03" . self::derLen(strlen($rsaKey) + 1) . "\x00" . $rsaKey)), self::ALG_RS256];
        }
        throw new \InvalidArgumentException('Unsupported key type or algorithm');
    }

    private static function derLen(int $n): string
    {
        if ($n < 128) {
            return chr($n);
        }
        $b = ltrim(pack('N', $n), "\x00");
        return chr(0x80 | strlen($b)) . $b;
    }

    private static function derSeq(string $c): string
    {
        return "\x30" . self::derLen(strlen($c)) . $c;
    }

    private static function derInt(string $be): string
    {
        $be = ltrim($be, "\x00");
        if ($be === '' || (ord($be[0]) & 0x80)) {
            $be = "\x00" . $be;
        }
        return "\x02" . self::derLen(strlen($be)) . $be;
    }

    private static function pem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }
}
