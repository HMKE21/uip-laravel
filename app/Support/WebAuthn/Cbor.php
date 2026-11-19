<?php

namespace App\Support\WebAuthn;

/**
 * مفكّك CBOR صغير (RFC 8949) بيكفي WebAuthn: أرقام صحيحة، byte/text strings، arrays، maps، true/false/null.
 * مفيش dependency على Laravel. أي بيانات باظة → \InvalidArgumentException.
 */
final class Cbor
{
    private const MAX_DEPTH = 8;
    private const MAX_ITEMS = 1024;

    public static function decode(string $data, int &$offset = 0, int $depth = 0)
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException('CBOR nesting too deep');
        }
        if ($offset >= strlen($data)) {
            throw new \InvalidArgumentException('CBOR truncated');
        }
        $ib = ord($data[$offset++]);
        $major = $ib >> 5;
        $info = $ib & 0x1f;

        if ($major === 7) {
            return match ($info) {
                20 => false, 21 => true, 22, 23 => null,
                default => throw new \InvalidArgumentException('CBOR unsupported simple/float value'),
            };
        }

        $arg = self::readArg($data, $offset, $info);

        switch ($major) {
            case 0:
                return $arg;
            case 1:
                return -1 - $arg;
            case 2:
            case 3:
                if ($arg > strlen($data) - $offset) {
                    throw new \InvalidArgumentException('CBOR string overruns input');
                }
                $s = substr($data, $offset, $arg);
                $offset += $arg;
                return $s;
            case 4:
                if ($arg > self::MAX_ITEMS) {
                    throw new \InvalidArgumentException('CBOR array too large');
                }
                $out = [];
                for ($i = 0; $i < $arg; $i++) {
                    $out[] = self::decode($data, $offset, $depth + 1);
                }
                return $out;
            case 5:
                if ($arg > self::MAX_ITEMS) {
                    throw new \InvalidArgumentException('CBOR map too large');
                }
                $out = [];
                for ($i = 0; $i < $arg; $i++) {
                    $k = self::decode($data, $offset, $depth + 1);
                    if (!is_int($k) && !is_string($k)) {
                        throw new \InvalidArgumentException('CBOR map key type unsupported');
                    }
                    $out[$k] = self::decode($data, $offset, $depth + 1);
                }
                return $out;
            case 6:
                return self::decode($data, $offset, $depth + 1);
        }
        throw new \InvalidArgumentException('CBOR unsupported type');
    }

    private static function readArg(string $d, int &$o, int $info): int
    {
        if ($info < 24) {
            return $info;
        }
        $len = match ($info) { 24 => 1, 25 => 2, 26 => 4, 27 => 8, default => throw new \InvalidArgumentException('CBOR indefinite/reserved length unsupported') };
        if ($len > strlen($d) - $o) {
            throw new \InvalidArgumentException('CBOR truncated');
        }
        $raw = substr($d, $o, $len);
        $o += $len;
        if ($len === 8) {
            $v = unpack('J', $raw)[1];
            if ($v < 0) {
                throw new \InvalidArgumentException('CBOR integer too large');
            }
            return $v;
        }
        return (int) hexdec(bin2hex($raw));
    }
}
