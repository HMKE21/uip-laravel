<?php

namespace App\Helpers;

/**
 * منقولة من app/Helpers/UserAgentParser.php القديمة حرفيًا — بند 25
 * batch 4 (Logs). محلّل User-Agent بسيط بدون حزمة composer (زي القديمة
 * بالظبط)، غرضه الوحيد تغذية فلاتر device/browser/os في شاشة Security
 * & Audit Logs — مش قاعدة بيانات UA شاملة.
 */
class UserAgentParser
{
    /** @return array{device:string, browser:string, os:string} */
    public static function parse(?string $userAgent): array
    {
        if (!$userAgent) {
            return ['device' => 'Unknown', 'browser' => 'Unknown', 'os' => 'Unknown'];
        }

        return [
            'device'  => self::device($userAgent),
            'browser' => self::browser($userAgent),
            'os'      => self::os($userAgent),
        ];
    }

    private static function device(string $ua): string
    {
        if (preg_match('/iPad|Tablet/i', $ua)) {
            return 'Tablet';
        }
        if (preg_match('/Mobi|Android.*Mobile|iPhone/i', $ua)) {
            return 'Mobile';
        }
        return 'Desktop';
    }

    private static function browser(string $ua): string
    {
        $map = [
            'Edg/'     => 'Edge',
            'OPR/'     => 'Opera',
            'Opera'    => 'Opera',
            'SamsungBrowser' => 'Samsung Internet',
            'Firefox/' => 'Firefox',
            'Chrome/'  => 'Chrome',
            'CriOS/'   => 'Chrome (iOS)',
            'FxiOS/'   => 'Firefox (iOS)',
            'Safari/'  => 'Safari',
            'MSIE '    => 'Internet Explorer',
            'Trident/' => 'Internet Explorer',
        ];
        foreach ($map as $needle => $name) {
            if (stripos($ua, $needle) !== false) {
                return $name;
            }
        }
        return 'Other';
    }

    private static function os(string $ua): string
    {
        $map = [
            'Windows NT 10.0' => 'Windows 10/11',
            'Windows NT 6.3'  => 'Windows 8.1',
            'Windows NT 6.1'  => 'Windows 7',
            'Windows'         => 'Windows',
            'Mac OS X'        => 'macOS',
            'iPhone OS'       => 'iOS',
            'iPad'            => 'iPadOS',
            'Android'         => 'Android',
            'CrOS'            => 'ChromeOS',
            'Linux'           => 'Linux',
        ];
        foreach ($map as $needle => $name) {
            if (stripos($ua, $needle) !== false) {
                return $name;
            }
        }
        return 'Other';
    }
}
