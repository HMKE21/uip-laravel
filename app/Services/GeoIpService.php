<?php

namespace App\Services;

use App\Repositories\IpGeoCacheRepository;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\Log;

/**
 * منقولة من app/Services/GeoIpService.php القديمة بالكامل — بند 25
 * batch 3 (Country Restrictions). بترجّع IP لكود دولة ISO 3166-1
 * alpha-2، بتستخدمها CountryRestrictionPolicyService. مفيش قاعدة بيانات
 * GeoIP مبنية جوّه المشروع (MaxMind/GeoLite2 محتاج ترخيص + ملف .mmdb
 * مش موجود)، فبتنادي مزوّد HTTP خارجي (config('security.geoip')، افتراضيًا
 * ipapi.co) وتخزّن النتيجة في `ip_geo_cache` (migration 068) عشان
 * مايتكررش نفس الاستعلام لنفس العنوان.
 *
 * Logger::security() القديمة -> App\Support\SecurityLog::write() هنا
 * (بند 25 batch 4 — نفس القفلة اللي اتعملت في FileUploadService::
 * scanForMalware()/AccountLockoutService/إلخ)، مع الاحتفاظ بـ Log::warning() زي ما هي.
 */
class GeoIpService
{
    public function __construct(private IpGeoCacheRepository $cache)
    {
    }

    /**
     * @return array{status:string,code:?string,name:?string} status is one
     *         of 'ok' (resolved), 'local' (private/reserved address,
     *         cannot be geolocated), or 'error' (lookup unavailable/failed).
     */
    public function resolve(string $ip): array
    {
        if ($ip === '') {
            return ['status' => 'error', 'code' => null, 'name' => null];
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return ['status' => 'local', 'code' => null, 'name' => null];
        }

        $cached = $this->cache->find($ip);
        $cacheDays = max(1, (int) config('security.geoip.cache_days', 30));
        if ($cached && strtotime((string) $cached->resolved_at) > (time() - $cacheDays * 86400)) {
            return ['status' => $cached->lookup_status, 'code' => $cached->country_code, 'name' => $cached->country_name];
        }

        if (!config('security.geoip.enabled', true)) {
            return ['status' => 'error', 'code' => null, 'name' => null];
        }

        $result = $this->lookupRemote($ip);
        // فشل مؤقت (المزوّد واقع/timeout) متتخزّنش 30 يوم — وإلا الدولة تفضل "غير معروفة" لكل الفترة دي.
        if ($result['status'] !== 'error') {
            $this->cache->put($ip, $result['code'], $result['name'], $result['status']);
        }

        return $result;
    }

    /** @return array{status:string,code:?string,name:?string} */
    private function lookupRemote(string $ip): array
    {
        if (!function_exists('curl_init')) {
            SecurityLog::write('GeoIP lookup skipped - cURL not available', ['ip' => $ip]);
            Log::warning('GeoIP lookup skipped - cURL not available', ['ip' => $ip]);
            return ['status' => 'error', 'code' => null, 'name' => null];
        }

        $template = (string) config('security.geoip.provider_url', 'https://ipapi.co/{ip}/country/');
        $url = str_replace('{ip}', urlencode($ip), $template);
        $timeout = max(1, (int) config('security.geoip.timeout', 3));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(2, $timeout),
            CURLOPT_USERAGENT      => 'UIP-SecurityPortal/1.0',
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $status < 200 || $status >= 300 || !is_string($raw)) {
            SecurityLog::write('GeoIP lookup failed', ['ip' => $ip, 'http_status' => $status, 'curl_errno' => $errno]);
            Log::warning('GeoIP lookup failed', ['ip' => $ip, 'http_status' => $status, 'curl_errno' => $errno]);
            return ['status' => 'error', 'code' => null, 'name' => null];
        }

        $code = strtoupper(trim($raw));
        if (!preg_match('/^[A-Z]{2}$/', $code)) {
            // مزوّد رجّع حاجة مش كود من حرفين (مثلاً رسالة خطأ لعنوان
            // reserved/unrouteable مش عارف يتعرف عليه) — ده "مقدرناش نحله"
            // مش خطأ حقيقي، فبيترجع 'unknown'.
            return ['status' => 'unknown', 'code' => null, 'name' => null];
        }

        return ['status' => 'ok', 'code' => $code, 'name' => $this->countryName($code)];
    }

    /** بيدي اسم دولة مقروء للـ UI من غير ما يحتاج نداء HTTP تاني. */
    private function countryName(string $code): ?string
    {
        return self::COUNTRY_NAMES[$code] ?? null;
    }

    /** مجموعة فرعية شائعة — الـ UI بيرجع لعرض الكود المجرد لأي حاجة مش موجودة هنا. */
    private const COUNTRY_NAMES = [
        'EG' => 'Egypt', 'SA' => 'Saudi Arabia', 'AE' => 'United Arab Emirates', 'US' => 'United States',
        'GB' => 'United Kingdom', 'DE' => 'Germany', 'FR' => 'France', 'CN' => 'China', 'RU' => 'Russia',
        'IN' => 'India', 'JO' => 'Jordan', 'LB' => 'Lebanon', 'IQ' => 'Iraq', 'SY' => 'Syria', 'YE' => 'Yemen',
        'KW' => 'Kuwait', 'QA' => 'Qatar', 'BH' => 'Bahrain', 'OM' => 'Oman', 'LY' => 'Libya', 'TN' => 'Tunisia',
        'DZ' => 'Algeria', 'MA' => 'Morocco', 'SD' => 'Sudan', 'TR' => 'Turkey', 'IR' => 'Iran', 'IL' => 'Israel',
        'CA' => 'Canada', 'BR' => 'Brazil', 'AU' => 'Australia', 'JP' => 'Japan', 'KR' => 'South Korea',
        'KP' => 'North Korea', 'NG' => 'Nigeria', 'ZA' => 'South Africa', 'PK' => 'Pakistan', 'ID' => 'Indonesia',
    ];
}
