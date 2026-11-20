<?php

namespace App\Support;

/** مطابقة IP مع عنوان أو CIDR (IPv4 + IPv6). مفيش اعتماد على أي مكتبة. */
final class IpMatcher
{
    private static function bin(string $ip): ?string
    {
        $ip = trim($ip);
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        $b = @inet_pton($ip);
        if ($b === false) {
            return null;
        }
        if (strlen($b) === 16 && substr($b, 0, 12) === str_repeat("\0", 10) . "\xff\xff") { // ::ffff:a.b.c.d → IPv4
            $b = substr($b, 12);
        }
        return $b;
    }

    /** @return array{0:string,1:int,2:bool}|null [binary network, prefix bits, hadPrefix] */
    private static function parse(string $cidr): ?array
    {
        $cidr = trim($cidr);
        $bits = null;
        if (str_contains($cidr, '/')) {
            [$cidr, $p] = explode('/', $cidr, 2);
            if ($p === '' || !ctype_digit($p)) {
                return null;
            }
            $bits = (int) $p;
        }
        $bin = self::bin($cidr);
        if ($bin === null) {
            return null;
        }
        $max = strlen($bin) * 8;
        if ($bits !== null && ($bits < 0 || $bits > $max)) {
            return null;
        }
        return [$bin, $bits ?? $max, $bits !== null];
    }

    public static function valid(string $cidr): bool
    {
        return self::parse($cidr) !== null;
    }

    /** الشكل المخزّن: عنوان مضغوط (+ /bits لو اتكتب). null لو غلط. */
    public static function canonical(string $cidr): ?string
    {
        $p = self::parse($cidr);
        if (!$p) {
            return null;
        }
        return inet_ntop($p[0]) . ($p[2] ? '/' . $p[1] : '');
    }

    public static function matches(string $ip, string $cidr): bool
    {
        $p = self::parse($cidr);
        $b = self::bin($ip);
        if (!$p || $b === null || strlen($p[0]) !== strlen($b)) {
            return false;
        }
        [$net, $bits] = $p;
        $bytes = intdiv($bits, 8);
        $rem = $bits % 8;
        if ($bytes > 0 && substr($net, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        if ($rem > 0) {
            $mask = (0xFF << (8 - $rem)) & 0xFF;
            return (ord($net[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
        }
        return true;
    }
}
