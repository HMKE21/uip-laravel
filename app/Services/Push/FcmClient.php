<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Support\PlatformPolicy;

/**
 * عميل Firebase Cloud Messaging (HTTP v1) من غير أي مكتبة خارجية: بيوقّع JWT بالـ service account
 * (RS256) ويبدّله بـ OAuth access token (بيتخزّن في الكاش) ويبعت الرسالة.
 *
 * الإعداد — الأولوية:
 *   1) من الداشبورد: Admin → Platform Policy → Push notifications (بيتخزّن مشفّر في جدول settings)
 *   2) من الـ .env:
 *   FCM_CREDENTIALS_PATH=/مسار/كامل/firebase-service-account.json     (أو)
 *   FCM_CREDENTIALS_JSON=<محتوى الملف نفسه، أو base64 له>
 *   FCM_PROJECT_ID=uip-nub            (اختياري — بياخده من الملف لو فاضي)
 * لو مفيش credentials: isConfigured() بترجع false وكل الإرسال بيتجاهل بهدوء.
 */
class FcmClient
{
    public const OK = 'ok';
    public const INVALID_TOKEN = 'invalid_token';
    public const FAILED = 'failed';

    private ?array $creds = null;
    private bool $credsLoaded = false;
    private int $credsAt = 0;

    public function isConfigured(): bool
    {
        return $this->credentials() !== null;
    }

    /** @return array{client_email:string,private_key:string,project_id:string}|null */
    private function credentials(): ?array
    {
        // الـ memo لثواني بس (مش لحد آخر الـ process) عشان تغيير الإعداد من الداشبورد يتطبّق من غير restart.
        if ($this->credsLoaded && (time() - $this->credsAt) < 15) {
            return $this->creds;
        }
        $this->credsLoaded = true;
        $this->credsAt = time();

        $raw = '';
        // 1) الداشبورد (لو الأدمن حفظ قيمة)
        if (PlatformPolicy::isValueOverridden('fcm_credentials_json')) {
            $raw = trim((string) PlatformPolicy::value('fcm_credentials_json'));
        }
        // 2) .env: مسار ملف
        $path = (string) config('services.fcm.credentials_path', '');
        if ($raw === '' && $path !== '' && is_readable($path)) {
            $raw = (string) file_get_contents($path);
        }
        // 3) .env: المحتوى نفسه (JSON أو base64)
        if ($raw === '') {
            $inline = trim((string) config('services.fcm.credentials_json', ''));
            if ($inline !== '') {
                $raw = $inline[0] === '{' ? $inline : (string) base64_decode($inline, true);
            }
        }
        $json = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
            return $this->creds = null;
        }
        $project = trim((string) PlatformPolicy::value('fcm_project_id')) ?: (string) ($json['project_id'] ?? '');
        if ($project === '') {
            return $this->creds = null;
        }
        return $this->creds = [
            'client_email' => (string) $json['client_email'],
            'private_key'  => (string) $json['private_key'],
            'project_id'   => $project,
        ];
    }

    /** لون الـ LED + مدة الوميض (#RRGGBB → قيم 0..1 اللي FCM عايزها). */
    private static function light(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            $hex = '4F5BE8';
        }
        return [
            'color' => [
                'red'   => round(hexdec(substr($hex, 0, 2)) / 255, 3),
                'green' => round(hexdec(substr($hex, 2, 2)) / 255, 3),
                'blue'  => round(hexdec(substr($hex, 4, 2)) / 255, 3),
                'alpha' => 1,
            ],
            'light_on_duration'  => '1s',
            'light_off_duration' => '2s',
        ];
    }

    private static function b64u(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private function accessToken(): ?string
    {
        $creds = $this->credentials();
        if (!$creds) {
            return null;
        }
        return Cache::remember('fcm_access_token:' . md5($creds['client_email']), 3000, function () use ($creds) {
            $now = time();
            $head = self::b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = self::b64u(json_encode([
                'iss'   => $creds['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud'   => 'https://oauth2.googleapis.com/token',
                'iat'   => $now,
                'exp'   => $now + 3600,
            ]));
            $signature = '';
            if (!openssl_sign("$head.$claims", $signature, $creds['private_key'], OPENSSL_ALGO_SHA256)) {
                Log::warning('FCM: could not sign the service-account JWT (is the private_key valid?)');
                return null;
            }
            $res = Http::asForm()->timeout(8)->connectTimeout(4)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => "$head.$claims." . self::b64u($signature),
            ]);
            if (!$res->ok() || !$res->json('access_token')) {
                Log::warning('FCM: token request failed', ['status' => $res->status(), 'body' => mb_substr($res->body(), 0, 300)]);
                return null;
            }
            return (string) $res->json('access_token');
        });
    }

    /**
     * @param array<string,scalar|null> $data قيم نصية بس (شرط FCM)
     * @return string self::OK | self::INVALID_TOKEN (امسح التوكن) | self::FAILED
     */
    /** @param array{channel_id?:string,tag?:string,color?:string,ttl?:string,icon?:string,count?:int,ticker?:string,timeout?:int} $opts */
    public function send(string $token, string $title, string $body, array $data = [], array $opts = []): string
    {
        $creds = $this->credentials();
        $access = $this->accessToken();
        if (!$creds || !$access) {
            Cache::forget('fcm_access_token:' . md5($creds['client_email'] ?? ''));
            return self::FAILED;
        }

        $payload = ['message' => [
            'token'        => $token,
            'notification' => ['title' => mb_substr($title, 0, 120), 'body' => mb_substr($body, 0, 240)],
            'data'         => array_map(fn ($v) => (string) $v, $data),
            'android'      => [
                'priority'     => 'HIGH',
                'ttl'          => ($opts['ttl'] ?? '86400s'),
                'notification' => [
                    'channel_id' => $opts['channel_id'] ?? 'uip',   // نفس القناة اللي التطبيق بيعملها (native.js)
                    'icon'       => $opts['icon'] ?? 'ic_stat_uip',     // native/android/res/drawable/ic_stat_uip*.xml
                    'color'      => $opts['color'] ?? '#4F5BE8',
                    'sound'      => 'default',
                    'notification_priority' => 'PRIORITY_HIGH',
                    'visibility' => 'PUBLIC',
                    'default_vibrate_timings' => true,
                    'light_settings' => self::light($opts['color'] ?? '#4F5BE8'),
                    ...(!empty($opts['tag']) ? ['tag' => (string) $opts['tag']] : []),
                    ...(!empty($opts['count']) ? ['notification_count' => (int) $opts['count']] : []),
                    ...(!empty($opts['ticker']) ? ['ticker' => mb_substr((string) $opts['ticker'], 0, 120)] : []),
                ],
            ],
        ]];

        return $this->post($creds['project_id'], $access, $payload, (int) ($opts['timeout'] ?? 8));
    }

    /**
     * رسالة data-only بأولوية HIGH (من غير notification): بتصحّي خدمة FCM في التطبيق حتى لو مقفول، والتطبيق نفسه (UipMessagingService)
     * هو اللي بيرسم الإشعار — بنستخدمها لرنّة المكالمة (full-screen) وإلغائها. المفاتيح والقيم نصية بس.
     * @param array<string,scalar|null> $data
     * @param array{ttl?:string,timeout?:int} $opts
     * @return string self::OK | self::INVALID_TOKEN | self::FAILED
     */
    public function sendData(string $token, array $data, array $opts = []): string
    {
        $creds = $this->credentials();
        $access = $this->accessToken();
        if (!$creds || !$access) {
            Cache::forget('fcm_access_token:' . md5($creds['client_email'] ?? ''));
            return self::FAILED;
        }
        $payload = ['message' => [
            'token'   => $token,
            'data'    => array_map(fn ($v) => (string) $v, $data),
            'android' => ['priority' => 'HIGH', 'ttl' => ($opts['ttl'] ?? '60s')],
        ]];
        return $this->post($creds['project_id'], $access, $payload, (int) ($opts['timeout'] ?? 5));
    }

    private function post(string $projectId, string $access, array $payload, int $timeout): string
    {
        try {
            $res = Http::withToken($access)->timeout($timeout)->connectTimeout(min(4, $timeout))
                ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", $payload);
        } catch (\Throwable $e) {
            Log::warning('FCM: send failed', ['error' => $e->getMessage()]);
            return self::FAILED;
        }

        if ($res->successful()) {
            return self::OK;
        }
        if ($res->status() === 401) {
            $creds = $this->credentials();
            Cache::forget('fcm_access_token:' . md5($creds['client_email'] ?? ''));
        }
        $status = (string) $res->json('error.status');
        $code = '';
        foreach ((array) $res->json('error.details', []) as $d) {
            $code = (string) ($d['errorCode'] ?? $code);
        }
        // التوكن بس هو اللي يتمسح: 404/UNREGISTERED، أو 400 والغلط فعلًا في message.token. أي 400 تاني (حقل غلط في الـ payload مثلًا)
        // كان بيمسح كل توكنات الأجهزة بصمت ومفيش إشعار بعدها يوصل — دلوقتي بيتسجّل في اللوج والتوكن يفضل.
        $body = (string) $res->body();
        $badToken = $res->status() === 400 && $status === 'INVALID_ARGUMENT' && $code !== 'SENDER_ID_MISMATCH'
            && (stripos($body, 'message.token') !== false || stripos($body, 'registration token') !== false);
        if ($res->status() === 404 || $code === 'UNREGISTERED' || $badToken) {
            return self::INVALID_TOKEN;
        }
        Log::warning('FCM: send rejected', ['status' => $res->status(), 'body' => mb_substr($res->body(), 0, 300)]);
        return self::FAILED;
    }
}
