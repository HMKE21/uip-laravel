<?php

namespace App\Support;

/**
 * قراءة خفيفة للـ User-Agent (من غير مكتبة): نوع الجهاز + النظام + المتصفح.
 * type: mobile | tablet | desktop | bot (زواحف معروفة) | tool (سكربت/أداة فحص/curl…) | unknown.
 * ملحوظة: الـ User-Agent بيتزوّر بسهولة، فده مؤشر مش دليل — خصوصًا في الهجمات.
 */
final class UserAgentInfo
{
    private const TOOLS = '/(sqlmap|nikto|nmap|masscan|zgrab|nuclei|wpscan|acunetix|netsparker|burp|dirbuster|gobuster|ffuf|hydra|metasploit|curl\/|wget|python-requests|python-urllib|aiohttp|httpx|go-http-client|java\/|libwww|scrapy|postman|insomnia|axios|node-fetch|okhttp|headlesschrome|phantomjs|puppeteer|playwright|selenium)/i';

    private const BOTS = '/(googlebot|bingbot|slurp|duckduckbot|baiduspider|yandex|facebookexternalhit|twitterbot|linkedinbot|applebot|semrush|ahrefs|petalbot|bytespider|crawler|spider|bot\b)/i';

    /** @return array{type:string,os:?string,browser:?string,label:?string} */
    public static function parse(?string $ua): array
    {
        $ua = trim((string) $ua);
        if ($ua === '') {
            return ['type' => 'unknown', 'os' => null, 'browser' => null, 'label' => null];
        }

        $os = self::os($ua);
        $browser = self::browser($ua);

        if (preg_match(self::BOTS, $ua)) {
            $type = 'bot';
        } elseif (preg_match(self::TOOLS, $ua)) {
            $type = 'tool';
        } elseif (preg_match('/(ipad|tablet|kindle|silk\/|playbook)/i', $ua) || (stripos($ua, 'android') !== false && stripos($ua, 'mobile') === false)) {
            $type = 'tablet';
        } elseif (preg_match('/(iphone|ipod|android|mobile|windows phone|opera mini|blackberry|iemobile)/i', $ua)) {
            $type = 'mobile';
        } elseif (preg_match('/(windows nt|macintosh|mac os x|x11|linux|cros)/i', $ua)) {
            $type = 'desktop';
        } else {
            $type = 'unknown';
        }

        $label = trim(implode(' · ', array_filter([$os, $browser])));

        return ['type' => $type, 'os' => $os, 'browser' => $browser, 'label' => $label !== '' ? $label : null];
    }

    private static function os(string $ua): ?string
    {
        return match (true) {
            (bool) preg_match('/windows phone/i', $ua)                 => 'Windows Phone',
            (bool) preg_match('/windows nt 10\.0/i', $ua)              => 'Windows 10/11',
            (bool) preg_match('/windows nt 6\.[123]/i', $ua)           => 'Windows 7/8',
            (bool) preg_match('/windows/i', $ua)                       => 'Windows',
            (bool) preg_match('/(iphone|ipad|ipod)/i', $ua)            => 'iOS',
            (bool) preg_match('/android\s?([\d.]+)?/i', $ua, $m)       => 'Android' . (!empty($m[1]) ? ' ' . $m[1] : ''),
            (bool) preg_match('/(macintosh|mac os x)/i', $ua)          => 'macOS',
            (bool) preg_match('/cros/i', $ua)                          => 'ChromeOS',
            (bool) preg_match('/(ubuntu|debian|fedora|linux|x11)/i', $ua) => 'Linux',
            default                                                    => null,
        };
    }

    private static function browser(string $ua): ?string
    {
        return match (true) {
            (bool) preg_match('/edg(e|a|ios)?\//i', $ua)   => 'Edge',
            (bool) preg_match('/(opr|opera)\//i', $ua)     => 'Opera',
            (bool) preg_match('/samsungbrowser/i', $ua)    => 'Samsung Internet',
            (bool) preg_match('/(firefox|fxios)/i', $ua)   => 'Firefox',
            (bool) preg_match('/(chrome|crios)/i', $ua)    => 'Chrome',
            (bool) preg_match('/safari/i', $ua)            => 'Safari',
            (bool) preg_match('/(msie|trident)/i', $ua)    => 'Internet Explorer',
            (bool) preg_match('/curl\//i', $ua)            => 'curl',
            (bool) preg_match('/python/i', $ua)            => 'Python',
            default                                        => null,
        };
    }
}
