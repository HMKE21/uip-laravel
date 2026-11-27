<?php

namespace App\Services;

use App\Models\IpGeoCache;
use App\Repositories\IpGeoCacheRepository;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\Log;

/**
 * تحديد مكان الـ IP. كان بيرجّع دولة بس من مزوّد واحد (ipapi.co)؛ دلوقتي بيسأل كذا مزوّد بالتوازي
 * (curl_multi، يعني الوقت = أبطأ مزوّد مش مجموعهم) ويدمج الإجابات في موقع واحد:
 *
 *   1. الدولة بالتصويت المرجّح بين المزوّدين.
 *   2. من النقط اللي في نفس الدولة بنختار أكبر مجموعة متقاربة (≤ 75 كم)؛ المزوّد اللي بيشطح بيتتجاهل.
 *   3. الإحداثيات = متوسط مرجّح للمجموعة، ودقة التقدير (accuracy_km) = أبعد انحراف داخلها (5–50 كم).
 *   4. المدينة/المنطقة/المنطقة الزمنية/ISP/ASN من أقوى مزوّد في المجموعة، ونوع الاتصال
 *      (datacenter / proxy / mobile / broadband) من أعلام المزوّدين + اسم المزوّد (ISP).
 *   5. درجة ثقة 0–100 حسب اتفاق المزوّدين ووجود المدينة.
 *
 * المزوّدات (config('security.geoip.providers')): ip-api, ipwho, ipapi, geojs, freeipapi, ipinfo (بتوكن)، و
 * maxmind (قاعدة GeoLite2-City محلية لو مكتبة geoip2/geoip2 ومسار .mmdb موجودين — أدق وأسرع وبدون إنترنت).
 * الاعتماد على IP مهما كان بيدّي مدينة تقريبية بس، مش عنوان شارع — وأي IP ورا VPN/Proxy/شبكة موبايل
 * بيرجّع مكان الـ exit node مش مكان الشخص (is_proxy / connection بيوضّحوا ده).
 *
 * التخزين في `ip_geo_cache` (migration 068 + 2028_03_21). النتيجة قديمًا كانت ['status','code','name'] —
 * لسه كده، مع مفاتيح إضافية (city, lat, lng, …) فكل المستدعين القدام شغّالين زي ما هم.
 */
class GeoIpService
{
    /** @var array<string,int> وزن كل مزوّد في التصويت. */
    private const WEIGHTS = ['maxmind' => 4, 'ipinfo' => 3, 'ip-api' => 2, 'ipwho' => 2, 'ipapi' => 2, 'geojs' => 1, 'freeipapi' => 1];

    /** أقصى مسافة (كم) بين مزوّدين يُعتبروا متفقين على نفس المكان. */
    private const AGREE_KM = 75;

    public function __construct(private IpGeoCacheRepository $cache)
    {
    }

    /**
     * @param bool $detail true = محتاج موقع تفصيلي (مدينة/إحداثيات)، فصف قديم (دولة بس) هيتعمله lookup تاني.
     *                     false = الدولة تكفي (Country Restrictions / sessions) فأي صف في الكاش بيتستخدم.
     * @return array{status:string,code:?string,name:?string,city:?string,region:?string,lat:?float,lng:?float,accuracy_km:?int,timezone:?string,isp:?string,asn:?string,connection:?string,proxy:bool,hosting:bool,mobile:bool,confidence:?int,sources:?string}
     *         status: 'ok' (اتحلّ) | 'local' (عنوان خاص/محجوز) | 'unknown' (المزوّدين ردّوا بس مفيش بيانات) | 'error' (الخدمة مش متاحة).
     */
    public function resolve(string $ip, bool $detail = false): array
    {
        if ($ip === '') {
            return $this->blank('error');
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $this->blank('local');
        }

        $cached = $this->cache->find($ip);
        $cacheDays = max(1, (int) config('security.geoip.cache_days', 30));
        if ($cached && strtotime((string) $cached->resolved_at) > (time() - $cacheDays * 86400)) {
            $upgrade = $detail && $cached->detail_at === null && in_array($cached->lookup_status, ['ok', 'unknown'], true)
                && $this->hasDetailColumn();
            if (!$upgrade) {
                return $this->fromRow($cached);
            }
        }

        if (!config('security.geoip.enabled', true)) {
            return $cached ? $this->fromRow($cached) : $this->blank('error');
        }

        $result = $this->lookupRemote($ip);
        // فشل مؤقت (كل المزوّدين واقعين/timeout) متتخزّنش 30 يوم — وإلا الموقع يفضل "غير معروف" لكل الفترة دي.
        if ($result['status'] === 'error') {
            return $cached ? $this->fromRow($cached) : $result;
        }

        $this->cache->put($ip, $result['code'], $result['name'], $result['status'], [
            'region'          => $result['region'],
            'city'            => $result['city'],
            'postal'          => $result['postal'] ?? null,
            'latitude'        => $result['lat'],
            'longitude'       => $result['lng'],
            'accuracy_km'     => $result['accuracy_km'],
            'timezone'        => $result['timezone'],
            'isp'             => $result['isp'],
            'asn'             => $result['asn'],
            'connection_type' => $result['connection'],
            'is_proxy'        => $result['proxy'],
            'is_hosting'      => $result['hosting'],
            'is_mobile'       => $result['mobile'],
            'confidence'      => $result['confidence'],
            'sources'         => $result['sources'],
            'detail_at'       => now(),
        ]);
        unset($result['postal']);

        return $result;
    }

    /** نتيجة تفصيلية لعنوان واحد (للـ endpoint بتاع "IP lookup") — بتتخطى أي صف دولة-بس قديم. */
    public function lookup(string $ip): array
    {
        return $this->resolve($ip, true);
    }

    // ------------------------------------------------------------------ remote

    /** @return array<string,mixed> */
    private function lookupRemote(string $ip): array
    {
        $timeout = max(1, (int) config('security.geoip.timeout', 3));
        $answers = [];   // اسم المزوّد => نتيجة مطبّعة
        $replied = false; // أي مزوّد رد برد سليم (حتى لو من غير بيانات)

        // 1) قاعدة محلية (لو متاحة) — فورية
        if (in_array('maxmind', $this->providerNames(), true)) {
            $local = $this->maxmind($ip);
            if ($local !== null) {
                $answers['maxmind'] = $local;
                $replied = true;
            }
        }

        // 2) المزوّدين الخارجيين بالتوازي
        if (function_exists('curl_init') && function_exists('curl_multi_init')) {
            $requests = [];
            foreach ($this->providerNames() as $name) {
                $url = $this->providerUrl($name, $ip);
                if ($url !== null) {
                    $requests[$name] = $url;
                }
            }
            foreach ($this->fetchAll($requests, $timeout) as $name => $res) {
                if ($res['http'] < 200 || $res['http'] >= 300 || !is_string($res['body']) || $res['body'] === '') {
                    continue;
                }
                $json = json_decode($res['body'], true);
                if (!is_array($json)) {
                    continue;
                }
                $replied = true;
                $norm = $this->parseProvider($name, $json);
                if ($norm !== null) {
                    $answers[$name] = $norm;
                }
            }
        } else {
            SecurityLog::write('GeoIP lookup skipped - cURL not available', ['ip' => $ip]);
            Log::warning('GeoIP lookup skipped - cURL not available', ['ip' => $ip]);
        }

        // 3) fallback قديم: مزوّد بيرجّع كود دولة نصي بس (GEOIP_PROVIDER_URL) — لو مفيش أي نتيجة تانية
        if (!$answers && function_exists('curl_init')) {
            $legacy = $this->legacyCountryOnly($ip, $timeout);
            if ($legacy !== null) {
                $answers['country-url'] = $legacy;
                $replied = true;
            }
        }

        if (!$answers) {
            if (!$replied) {
                SecurityLog::write('GeoIP lookup failed', ['ip' => $ip, 'providers' => $this->providerNames()]);
                Log::warning('GeoIP lookup failed', ['ip' => $ip]);

                return $this->blank('error');
            }

            // مزوّدين ردّوا بس مفيش بيانات (عنوان reserved/unrouteable) — "مقدرناش نحله" مش خطأ حقيقي.
            return $this->blank('unknown');
        }

        return self::consensus($answers);
    }

    /**
     * يدمج إجابات المزوّدين (مطبّعة) في موقع واحد. public static عشان تتختبر من غير شبكة.
     *
     * @param array<string,array<string,mixed>> $answers provider => [code,country,region,city,postal,lat,lng,timezone,isp,asn,mobile,proxy,hosting]
     * @return array<string,mixed>
     */
    public static function consensus(array $answers): array
    {
        // ---- الدولة: تصويت مرجّح
        $votes = [];
        foreach ($answers as $name => $a) {
            $code = strtoupper((string) ($a['code'] ?? ''));
            if (preg_match('/^[A-Z]{2}$/', $code)) {
                $votes[$code] = ($votes[$code] ?? 0) + (self::WEIGHTS[$name] ?? 1);
            }
        }
        if (!$votes) {
            return self::blankResult('unknown');
        }
        arsort($votes);
        $code = (string) array_key_first($votes);
        $totalW = array_sum($votes);

        $inCountry = array_filter($answers, fn ($a) => strtoupper((string) ($a['code'] ?? '')) === $code);
        $countryName = null;
        foreach ($inCountry as $a) {
            if (!empty($a['country'])) {
                $countryName = (string) $a['country'];
                break;
            }
        }
        $countryName = self::COUNTRY_NAMES[$code] ?? $countryName;

        // ---- النقط اللي ليها إحداثيات
        $pts = [];
        foreach ($inCountry as $name => $a) {
            if (isset($a['lat'], $a['lng']) && is_numeric($a['lat']) && is_numeric($a['lng'])
                && abs((float) $a['lat']) <= 90 && abs((float) $a['lng']) <= 180 && !((float) $a['lat'] === 0.0 && (float) $a['lng'] === 0.0)) {
                $pts[] = ['name' => $name, 'w' => self::WEIGHTS[$name] ?? 1, 'lat' => (float) $a['lat'], 'lng' => (float) $a['lng'], 'a' => $a];
            }
        }

        $lat = $lng = null;
        $accuracy = null;
        $cluster = [];
        if ($pts) {
            // أكتر نقطة عليها دعم (مجموع أوزان النقط في نطاق AGREE_KM منها)
            $best = null;
            $bestSupport = -1;
            foreach ($pts as $i => $p) {
                $support = 0;
                foreach ($pts as $q) {
                    if (self::km($p['lat'], $p['lng'], $q['lat'], $q['lng']) <= self::AGREE_KM) {
                        $support += $q['w'];
                    }
                }
                if ($support > $bestSupport || ($support === $bestSupport && $p['w'] > ($pts[$best]['w'] ?? 0))) {
                    $best = $i;
                    $bestSupport = $support;
                }
            }
            foreach ($pts as $q) {
                if (self::km($pts[$best]['lat'], $pts[$best]['lng'], $q['lat'], $q['lng']) <= self::AGREE_KM) {
                    $cluster[] = $q;
                }
            }
            $sw = array_sum(array_column($cluster, 'w'));
            $lat = array_sum(array_map(fn ($q) => $q['lat'] * $q['w'], $cluster)) / $sw;
            $lng = array_sum(array_map(fn ($q) => $q['lng'] * $q['w'], $cluster)) / $sw;
            $spread = 0.0;
            foreach ($cluster as $q) {
                $spread = max($spread, self::km($lat, $lng, $q['lat'], $q['lng']));
            }
            $accuracy = (int) round(count($cluster) >= 2 ? min(50, max(5, $spread)) : 30);
            $lat = round($lat, 5);
            $lng = round($lng, 5);
        }

        // ---- الحقول النصية: من أعلى وزن في المجموعة (أو من أي مزوّد لو مفيش إحداثيات)
        $pool = $cluster ?: array_map(fn ($a, $n) => ['name' => $n, 'w' => self::WEIGHTS[$n] ?? 1, 'a' => $a], $inCountry, array_keys($inCountry));
        usort($pool, fn ($x, $y) => $y['w'] <=> $x['w']);
        $pick = function (string $field) use ($pool) {
            foreach ($pool as $p) {
                $v = trim((string) ($p['a'][$field] ?? ''));
                if ($v !== '') {
                    return mb_substr($v, 0, 160);
                }
            }

            return null;
        };

        $city = $pick('city');
        $isp = $pick('isp');
        $asn = $pick('asn');

        // ---- أعلام الاتصال (أي مزوّد قال true)
        $proxy = (bool) array_filter($answers, fn ($a) => !empty($a['proxy']));
        $hosting = (bool) array_filter($answers, fn ($a) => !empty($a['hosting']));
        $mobile = (bool) array_filter($answers, fn ($a) => !empty($a['mobile']));
        $text = strtolower(trim(($isp ?? '') . ' ' . ($answers['ip-api']['org'] ?? '')));
        if (!$hosting && $text !== '' && preg_match('/(amazon|aws|google cloud|google llc|microsoft|azure|digitalocean|ovh|hetzner|linode|akamai|vultr|choopa|cloudflare|contabo|leaseweb|m247|oracle|alibaba|tencent|hosting|datacenter|data center|colo|vps|server|cloud)/', $text)) {
            $hosting = true;
        }
        if (!$mobile && $text !== '' && preg_match('/(mobile|cellular|wireless|\blte\b|\b4g\b|\b5g\b|gsm)/', $text)) {
            $mobile = true;
        }
        $connection = $proxy ? 'proxy' : ($hosting ? 'datacenter' : ($mobile ? 'mobile' : ($isp !== null ? 'broadband' : null)));

        // ---- الثقة
        if ($lat !== null) {
            $clusterW = array_sum(array_column($cluster, 'w'));
            $allW = max(1, array_sum(array_column($pts, 'w')));
            $confidence = 30 + (int) round(35 * ($clusterW / $allW)) + min(15, 5 * (count($cluster) - 1)) + ($city !== null ? 10 : 0);
            $confidence += (int) round(10 * ($votes[$code] / max(1, $totalW)));
            if ($accuracy > 30) {
                $confidence -= 10;
            }
        } else {
            $confidence = 20 + (int) round(25 * ($votes[$code] / max(1, $totalW)));
        }
        if ($proxy || $hosting) {
            $confidence = (int) round($confidence * 0.8); // مكان الـ exit node مش مكان الشخص
        }
        $confidence = max(5, min(98, $confidence));

        return [
            'status'      => 'ok',
            'code'        => $code,
            'name'        => $countryName,
            'region'      => $pick('region'),
            'city'        => $city,
            'postal'      => $pick('postal'),
            'lat'         => $lat,
            'lng'         => $lng,
            'accuracy_km' => $accuracy,
            'timezone'    => $pick('timezone'),
            'isp'         => $isp,
            'asn'         => $asn,
            'connection'  => $connection,
            'proxy'       => $proxy,
            'hosting'     => $hosting,
            'mobile'      => $mobile,
            'confidence'  => $confidence,
            'sources'     => mb_substr(implode(',', array_keys($inCountry)), 0, 120),
        ];
    }

    // ------------------------------------------------------------------ providers

    /** @return array<int,string> */
    private function providerNames(): array
    {
        $list = config('security.geoip.providers');
        if (is_string($list)) {
            $list = explode(',', $list);
        }
        $list = array_values(array_filter(array_map('trim', (array) $list)));

        return $list ?: ['ip-api', 'ipwho', 'ipapi', 'geojs'];
    }

    private function providerUrl(string $name, string $ip): ?string
    {
        $e = urlencode($ip);
        switch ($name) {
            case 'ip-api':
                $key = (string) config('security.geoip.ipapi_key', '');
                $fields = 'status,message,countryCode,country,regionName,city,zip,lat,lon,timezone,isp,org,as,mobile,proxy,hosting';
                // النسخة المجانية HTTP بس (من السيرفر مش المتصفح، فمفيش مشكلة CSP)، والمدفوعة HTTPS.
                return $key !== '' ? "https://pro.ip-api.com/json/{$e}?fields={$fields}&key=" . urlencode($key) : "http://ip-api.com/json/{$e}?fields={$fields}";
            case 'ipwho':
                return "https://ipwho.is/{$e}";
            case 'ipapi':
                return "https://ipapi.co/{$e}/json/";
            case 'geojs':
                return "https://get.geojs.io/v1/ip/geo/{$e}.json";
            case 'freeipapi':
                return "https://freeipapi.com/api/json/{$e}";
            case 'ipinfo':
                $token = (string) config('security.geoip.ipinfo_token', '');
                return $token !== '' ? "https://ipinfo.io/{$e}/json?token=" . urlencode($token) : null;
            default:
                return null; // maxmind محلي
        }
    }

    /**
     * @param array<string,mixed> $j
     * @return array<string,mixed>|null
     */
    private function parseProvider(string $name, array $j): ?array
    {
        $num = fn ($v) => is_numeric($v) ? (float) $v : null;
        switch ($name) {
            case 'ip-api':
                if (($j['status'] ?? '') !== 'success') {
                    return null;
                }
                return [
                    'code' => $j['countryCode'] ?? null, 'country' => $j['country'] ?? null, 'region' => $j['regionName'] ?? null,
                    'city' => $j['city'] ?? null, 'postal' => $j['zip'] ?? null, 'lat' => $num($j['lat'] ?? null), 'lng' => $num($j['lon'] ?? null),
                    'timezone' => $j['timezone'] ?? null, 'isp' => $j['isp'] ?? ($j['org'] ?? null), 'org' => $j['org'] ?? null,
                    'asn' => isset($j['as']) ? strtok((string) $j['as'], ' ') : null,
                    'mobile' => (bool) ($j['mobile'] ?? false), 'proxy' => (bool) ($j['proxy'] ?? false), 'hosting' => (bool) ($j['hosting'] ?? false),
                ];
            case 'ipwho':
                if (empty($j['success'])) {
                    return null;
                }
                $c = (array) ($j['connection'] ?? []);
                return [
                    'code' => $j['country_code'] ?? null, 'country' => $j['country'] ?? null, 'region' => $j['region'] ?? null,
                    'city' => $j['city'] ?? null, 'postal' => $j['postal'] ?? null, 'lat' => $num($j['latitude'] ?? null), 'lng' => $num($j['longitude'] ?? null),
                    'timezone' => $j['timezone']['id'] ?? null, 'isp' => $c['isp'] ?? ($c['org'] ?? null),
                    'asn' => isset($c['asn']) ? 'AS' . $c['asn'] : null,
                ];
            case 'ipapi':
                if (!empty($j['error'])) {
                    return null;
                }
                return [
                    'code' => $j['country_code'] ?? ($j['country'] ?? null), 'country' => $j['country_name'] ?? null, 'region' => $j['region'] ?? null,
                    'city' => $j['city'] ?? null, 'postal' => $j['postal'] ?? null, 'lat' => $num($j['latitude'] ?? null), 'lng' => $num($j['longitude'] ?? null),
                    'timezone' => $j['timezone'] ?? null, 'isp' => $j['org'] ?? null, 'asn' => $j['asn'] ?? null,
                ];
            case 'geojs':
                return [
                    'code' => $j['country_code'] ?? null, 'country' => $j['country'] ?? null, 'region' => $j['region'] ?? null,
                    'city' => $j['city'] ?? null, 'lat' => $num($j['latitude'] ?? null), 'lng' => $num($j['longitude'] ?? null),
                    'timezone' => $j['timezone'] ?? null, 'isp' => $j['organization_name'] ?? ($j['organization'] ?? null),
                    'asn' => isset($j['asn']) ? 'AS' . ltrim((string) $j['asn'], 'ASas') : null,
                ];
            case 'freeipapi':
                return [
                    'code' => $j['countryCode'] ?? null, 'country' => $j['countryName'] ?? null, 'region' => $j['regionName'] ?? null,
                    'city' => $j['cityName'] ?? null, 'postal' => $j['zipCode'] ?? null, 'lat' => $num($j['latitude'] ?? null), 'lng' => $num($j['longitude'] ?? null),
                    'timezone' => $j['timeZone'] ?? ($j['timeZones'][0] ?? null), 'proxy' => (bool) ($j['isProxy'] ?? false),
                ];
            case 'ipinfo':
                [$la, $lo] = array_pad(explode(',', (string) ($j['loc'] ?? ''), 2), 2, null);
                $org = (string) ($j['org'] ?? '');
                return [
                    'code' => $j['country'] ?? null, 'region' => $j['region'] ?? null, 'city' => $j['city'] ?? null, 'postal' => $j['postal'] ?? null,
                    'lat' => $num($la), 'lng' => $num($lo), 'timezone' => $j['timezone'] ?? null,
                    'isp' => preg_replace('/^AS\d+\s+/', '', $org) ?: null,
                    'asn' => preg_match('/^(AS\d+)/', $org, $m) ? $m[1] : null,
                    'proxy' => (bool) ($j['privacy']['proxy'] ?? false) || (bool) ($j['privacy']['vpn'] ?? false) || (bool) ($j['privacy']['tor'] ?? false),
                    'hosting' => (bool) ($j['privacy']['hosting'] ?? false),
                ];
            default:
                return null;
        }
    }

    /** MaxMind GeoLite2/GeoIP2 City محلي (اختياري): composer require geoip2/geoip2 + GEOIP_MMDB_CITY=/path/GeoLite2-City.mmdb */
    private function maxmind(string $ip): ?array
    {
        $path = (string) config('security.geoip.mmdb_city', '');
        $reader = '\\GeoIp2\\Database\\Reader';
        if ($path === '' || !is_file($path) || !class_exists($reader)) {
            return null;
        }
        try {
            $r = (new $reader($path))->city($ip);
            $sub = $r->subdivisions[0] ?? null;

            return [
                'code' => $r->country->isoCode, 'country' => $r->country->name, 'region' => $sub?->name, 'city' => $r->city->name,
                'postal' => $r->postal->code, 'lat' => $r->location->latitude, 'lng' => $r->location->longitude, 'timezone' => $r->location->timeZone,
            ];
        } catch (\Throwable) {
            return null; // العنوان مش في القاعدة
        }
    }

    private function legacyCountryOnly(string $ip, int $timeout): ?array
    {
        $template = (string) config('security.geoip.provider_url', '');
        if ($template === '') {
            return null;
        }
        $res = $this->fetchAll(['country-url' => str_replace('{ip}', urlencode($ip), $template)], $timeout)['country-url'] ?? null;
        if (!$res || $res['http'] < 200 || $res['http'] >= 300 || !is_string($res['body'])) {
            return null;
        }
        $code = strtoupper(trim($res['body']));

        return preg_match('/^[A-Z]{2}$/', $code) ? ['code' => $code] : null;
    }

    /**
     * @param array<string,string> $urls name => url
     * @return array<string,array{body:?string,http:int}>
     */
    private function fetchAll(array $urls, int $timeout): array
    {
        $out = [];
        if (!$urls) {
            return $out;
        }
        $mh = curl_multi_init();
        $handles = [];
        foreach ($urls as $name => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(2, $timeout),
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_USERAGENT      => 'UIP-SecurityPortal/1.0',
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$name] = $ch;
        }

        $running = 0;
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 0.2);
            }
        } while ($running > 0 && $st === CURLM_OK);

        foreach ($handles as $name => $ch) {
            $body = curl_multi_getcontent($ch);
            $out[$name] = ['body' => is_string($body) ? $body : null, 'http' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE)];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        return $out;
    }

    // ------------------------------------------------------------------ helpers

    private function hasDetailColumn(): bool
    {
        static $memo = null;
        if ($memo === null) {
            try {
                $memo = \Illuminate\Support\Facades\Schema::hasColumn('ip_geo_cache', 'detail_at');
            } catch (\Throwable) {
                $memo = false;
            }
        }

        return $memo;
    }

    /** @return array<string,mixed> */
    private function fromRow(IpGeoCache $r): array
    {
        $out = self::blankResult((string) $r->lookup_status);
        $out['code'] = $r->country_code ? strtoupper((string) $r->country_code) : null;
        $out['name'] = $r->country_name;
        foreach (['region', 'city', 'timezone', 'isp', 'asn', 'sources'] as $f) {
            $out[$f] = $r->{$f} ?? null;
        }
        $out['lat'] = isset($r->latitude) ? (float) $r->latitude : null;
        $out['lng'] = isset($r->longitude) ? (float) $r->longitude : null;
        $out['accuracy_km'] = isset($r->accuracy_km) ? (int) $r->accuracy_km : null;
        $out['connection'] = $r->connection_type ?? null;
        $out['proxy'] = (bool) ($r->is_proxy ?? false);
        $out['hosting'] = (bool) ($r->is_hosting ?? false);
        $out['mobile'] = (bool) ($r->is_mobile ?? false);
        $out['confidence'] = isset($r->confidence) ? (int) $r->confidence : null;

        return $out;
    }

    /** @return array<string,mixed> */
    private function blank(string $status): array
    {
        return self::blankResult($status);
    }

    /** @return array<string,mixed> */
    private static function blankResult(string $status): array
    {
        return [
            'status' => $status, 'code' => null, 'name' => null, 'region' => null, 'city' => null, 'postal' => null,
            'lat' => null, 'lng' => null, 'accuracy_km' => null, 'timezone' => null, 'isp' => null, 'asn' => null,
            'connection' => null, 'proxy' => false, 'hosting' => false, 'mobile' => false, 'confidence' => null, 'sources' => null,
        ];
    }

    /** مسافة كبيرة-الدائرة بالكيلومتر (haversine). */
    private static function km(float $la1, float $lo1, float $la2, float $lo2): float
    {
        $r = 6371.0088;
        $p1 = deg2rad($la1);
        $p2 = deg2rad($la2);
        $dp = $p2 - $p1;
        $dl = deg2rad($lo2 - $lo1);
        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }

    /** مجموعة فرعية شائعة — الـ UI بيرجع لاسم المزوّد أو الكود المجرد لأي حاجة مش موجودة هنا. */
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
