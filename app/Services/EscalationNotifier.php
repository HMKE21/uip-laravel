<?php

namespace App\Services;

use App\Support\SafeUrl;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * بند 7 — إرسال رسالة تصعيد على قناة واحدة (email / Slack / Telegram / Webhook عام).
 *
 * - إعدادات القناة (توكن/لينك/سر) بتتخزّن متشفّرة (Crypt) وعمرها ما بترجع للفرونت كاملة (hints بس).
 * - لينكات Slack/Webhook بتعدّي على SafeUrl (https:443 بس، IP عام بس، مثبّت في cURL، من غير redirects) —
 *   فمفيش SSRF على الشبكة الداخلية. Slack محصور على hooks.slack.com.
 * - Telegram على api.telegram.org بس (توكن + chat_id متحقق منهم).
 * - الـ Webhook العام بيتوقّع بـ HMAC-SHA256 في X-UIPE-Signature لو فيه secret.
 * - send() عمرها ما بترمي استثناء: بترجّع [ok, httpStatus, error].
 */
class EscalationNotifier
{
    public const TYPES = ['email', 'slack', 'telegram', 'webhook'];
    public const SEVERITIES = ['info', 'low', 'warning', 'medium', 'high', 'critical'];
    private const SECRET_KEYS = ['webhook_url', 'bot_token', 'url', 'secret'];

    public function __construct(private MailService $mail)
    {
    }

    /** ترتيب الخطورة (alerts: info<warning<high<critical، incidents: low<medium<high<critical). */
    public static function rank(?string $severity): int
    {
        return match (strtolower((string) $severity)) {
            'info' => 0, 'low' => 1, 'warning' => 2, 'medium' => 2, 'high' => 3, 'critical' => 4,
            default => 0,
        };
    }

    // ───────────── config handling ─────────────

    /** @param array<string,mixed> $config */
    public static function encryptConfig(array $config): string
    {
        return Crypt::encryptString(json_encode($config, JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string,mixed> */
    public static function decryptConfig(?string $blob): array
    {
        if (!$blob) {
            return [];
        }
        try {
            $d = json_decode(Crypt::decryptString($blob), true);

            return is_array($d) ? $d : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * تنضيف + تحقق إعدادات قناة. الحقول السرية الفاضية في التعديل بتحتفظ بالقيمة القديمة.
     *
     * @param array<string,mixed> $in
     * @param array<string,mixed> $existing الإعدادات الحالية (مفكوكة) عند التعديل
     * @return array<string,mixed>
     * @throws \InvalidArgumentException
     */
    public function normalizeConfig(string $type, array $in, array $existing = []): array
    {
        $pick = function (string $key) use ($in, $existing): string {
            $v = trim((string) ($in[$key] ?? ''));

            return $v !== '' ? $v : (string) ($existing[$key] ?? '');
        };

        switch ($type) {
            case 'email':
                $raw = $in['recipients'] ?? ($existing['recipients'] ?? []);
                $list = is_array($raw) ? $raw : preg_split('/[\s,;]+/', (string) $raw);
                $emails = [];
                foreach ($list as $e) {
                    $e = strtolower(trim((string) $e));
                    if ($e === '') {
                        continue;
                    }
                    if (!filter_var($e, FILTER_VALIDATE_EMAIL) || strlen($e) > 190) {
                        throw new \InvalidArgumentException("Invalid email address: {$e}");
                    }
                    $emails[$e] = true;
                }
                if (!$emails) {
                    throw new \InvalidArgumentException('Add at least one recipient email.');
                }
                if (count($emails) > 20) {
                    throw new \InvalidArgumentException('An email channel can have at most 20 recipients.');
                }

                return ['recipients' => array_keys($emails)];

            case 'slack':
                $url = $pick('webhook_url');
                // وقت الحفظ بنتحقق من الشكل بس (من غير DNS)؛ فحص الـ IP العام بيتعمل وقت الإرسال.
                $p = parse_url($url);
                $shapeOk = $p && strtolower((string) ($p['scheme'] ?? '')) === 'https' && strtolower((string) ($p['host'] ?? '')) === 'hooks.slack.com'
                    && str_starts_with((string) ($p['path'] ?? ''), '/services/') && !isset($p['user'], $p['pass'], $p['fragment'], $p['port']) && strlen($url) <= 500
                    && preg_match('/[\s\x00-\x1f\x7f\\\\]/', $url) !== 1;
                if ($url === '' || !$shapeOk) {
                    throw new \InvalidArgumentException('Slack webhook must be an https://hooks.slack.com/services/... URL.');
                }

                return ['webhook_url' => $url];

            case 'telegram':
                $token = $pick('bot_token');
                $chat = trim((string) ($in['chat_id'] ?? ($existing['chat_id'] ?? '')));
                if (!preg_match('/^\d{5,}:[A-Za-z0-9_-]{30,}$/', $token)) {
                    throw new \InvalidArgumentException('Telegram bot token looks invalid (expected 123456:ABC...).');
                }
                if (!preg_match('/^(-?\d{5,20}|@[A-Za-z0-9_]{5,64})$/', $chat)) {
                    throw new \InvalidArgumentException('Telegram chat id must be a number (e.g. -1001234567890) or @channelname.');
                }

                return ['bot_token' => $token, 'chat_id' => $chat];

            case 'webhook':
                $url = $pick('url');
                if ($url === '' || self::hostOf($url) === null) {
                    throw new \InvalidArgumentException('Webhook URL must be a public https:// address (port 443, no credentials).');
                }
                $secret = $pick('secret');
                if ($secret !== '' && (strlen($secret) < 16 || strlen($secret) > 128)) {
                    throw new \InvalidArgumentException('Signing secret must be 16–128 characters (or leave it empty).');
                }

                return ['url' => $url, 'secret' => $secret];
        }

        throw new \InvalidArgumentException('Unknown channel type.');
    }

    /**
     * نسخة آمنة للعرض: بتشيل الأسرار وتسيب تلميحات (host / آخر 4 حروف).
     *
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public static function publicConfig(string $type, array $config): array
    {
        return match ($type) {
            'email'    => ['recipients' => $config['recipients'] ?? []],
            'slack'    => ['webhook_hint' => self::hint((string) ($config['webhook_url'] ?? ''), true), 'has_secret' => !empty($config['webhook_url'])],
            'telegram' => ['chat_id' => $config['chat_id'] ?? '', 'token_hint' => '…' . substr((string) ($config['bot_token'] ?? ''), -4), 'has_secret' => !empty($config['bot_token'])],
            'webhook'  => ['url_hint' => self::hint((string) ($config['url'] ?? ''), true), 'signed' => !empty($config['secret']), 'has_secret' => !empty($config['url'])],
            default    => [],
        };
    }

    private static function hint(string $url, bool $hostOnly): string
    {
        $p = parse_url($url);
        $host = (string) ($p['host'] ?? '');

        return $host !== '' ? $host . ($hostOnly ? '/…' : (string) ($p['path'] ?? '')) : '';
    }

    // ───────────── URL safety ─────────────

    /** @return array<string,mixed>|null */
    private static function safeSlack(string $url): ?array
    {
        $p = parse_url($url);
        if (!$p || !str_starts_with((string) ($p['path'] ?? ''), '/services/')) {
            return null;
        }

        return SafeUrl::check($url, ['hooks.slack.com']);
    }

    /** يرجّع الـ host لو الشكل سليم (https:443، من غير user:pass)، وإلا null. فحص DNS الكامل بيتعمل وقت الإرسال. */
    private static function hostOf(string $url): ?string
    {
        if ($url === '' || strlen($url) > 2000 || preg_match('/[\s\x00-\x1f\x7f\\\\]/', $url) === 1) {
            return null;
        }
        $p = parse_url($url);
        if (!$p || strtolower((string) ($p['scheme'] ?? '')) !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])
            || (isset($p['port']) && (int) $p['port'] !== 443)) {
            return null;
        }
        $host = strtolower((string) $p['host']);
        if (filter_var($host, FILTER_VALIDATE_IP) !== false || !str_contains($host, '.')) {
            return null;
        }

        return $host;
    }

    // ───────────── sending ─────────────

    /**
     * @param array{type:string,config:array<string,mixed>} $channel
     * @param array{title:string,lines:string[],severity:string,url:?string,kind:string,fields?:array<string,string>} $msg
     * @return array{0:bool,1:?int,2:?string} [ok, http_status, error]
     */
    public function send(array $channel, array $msg): array
    {
        try {
            return match ($channel['type']) {
                'email'    => $this->sendEmail($channel['config'], $msg),
                'slack'    => $this->sendSlack($channel['config'], $msg),
                'telegram' => $this->sendTelegram($channel['config'], $msg),
                'webhook'  => $this->sendWebhook($channel['config'], $msg),
                default    => [false, null, 'Unknown channel type.'],
            };
        } catch (\Throwable $e) {
            return [false, null, mb_substr($e->getMessage(), 0, 240)];
        }
    }

    /** إيميل لعنوان واحد (بيستعمله المناوب و"كل الأدمنز" كمان). */
    public function sendEmailTo(string $to, array $msg): array
    {
        $lines = $msg['lines'];
        foreach ($msg['fields'] ?? [] as $k => $v) {
            $lines[] = "{$k}: {$v}";
        }
        $ok = $this->mail->sendSecurityEscalation($to, '[' . strtoupper($msg['severity']) . '] ' . $msg['title'], $msg['title'], $lines, $msg['url'] ?? null);

        return [$ok, null, $ok ? null : mb_substr((string) $this->mail->getLastError(), 0, 240)];
    }

    /** @return array{0:bool,1:?int,2:?string} */
    private function sendEmail(array $c, array $msg): array
    {
        $okAll = true;
        $err = null;
        foreach ((array) ($c['recipients'] ?? []) as $to) {
            [$ok, , $e] = $this->sendEmailTo((string) $to, $msg);
            if (!$ok) {
                $okAll = false;
                $err = $err ?? $e;
            }
        }

        return [$okAll, null, $err];
    }

    private static function plain(array $msg): string
    {
        $t = '[' . strtoupper($msg['severity']) . '] ' . $msg['title'];
        foreach ($msg['lines'] as $l) {
            $t .= "\n" . $l;
        }
        foreach ($msg['fields'] ?? [] as $k => $v) {
            $t .= "\n{$k}: {$v}";
        }
        if (!empty($msg['url'])) {
            $t .= "\n" . $msg['url'];
        }

        return mb_substr($t, 0, 3500);
    }

    private function sendSlack(array $c, array $msg): array
    {
        $safe = self::safeSlack((string) ($c['webhook_url'] ?? ''));
        if ($safe === null) {
            return [false, null, 'Slack webhook URL is not allowed or does not resolve to a public address.'];
        }
        $text = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], self::plain($msg));
        $r = Http::withOptions($safe['options'])->timeout(8)->asJson()->post($safe['url'], ['text' => $text]);

        return [$r->successful(), $r->status(), $r->successful() ? null : 'Slack answered HTTP ' . $r->status() . '.'];
    }

    private function sendTelegram(array $c, array $msg): array
    {
        $token = (string) ($c['bot_token'] ?? '');
        $chat = (string) ($c['chat_id'] ?? '');
        if (!preg_match('/^\d{5,}:[A-Za-z0-9_-]{30,}$/', $token) || $chat === '') {
            return [false, null, 'Telegram channel is not configured correctly.'];
        }
        $r = Http::timeout(8)->asJson()->post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chat, 'text' => self::plain($msg), 'disable_web_page_preview' => true,
        ]);
        $ok = $r->successful() && ($r->json('ok') === true);

        return [$ok, $r->status(), $ok ? null : 'Telegram answered HTTP ' . $r->status() . ': ' . mb_substr((string) $r->json('description'), 0, 120)];
    }

    private function sendWebhook(array $c, array $msg): array
    {
        $url = (string) ($c['url'] ?? '');
        $host = self::hostOf($url);
        $safe = $host ? SafeUrl::check($url, [$host]) : null;
        if ($safe === null) {
            return [false, null, 'Webhook URL does not resolve to a public https address.'];
        }
        $body = json_encode([
            'source'    => 'uipe-security',
            'kind'      => $msg['kind'],
            'severity'  => $msg['severity'],
            'title'     => $msg['title'],
            'lines'     => $msg['lines'],
            'fields'    => (object) ($msg['fields'] ?? []),
            'url'       => $msg['url'] ?? null,
            'sent_at'   => gmdate('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = ['Content-Type' => 'application/json', 'User-Agent' => 'UIPE-Security-Escalation/1.0'];
        if (!empty($c['secret'])) {
            $headers['X-UIPE-Signature'] = 'sha256=' . hash_hmac('sha256', $body, (string) $c['secret']);
        }
        $r = Http::withOptions($safe['options'])->withHeaders($headers)->timeout(8)->withBody($body, 'application/json')->post($safe['url']);

        return [$r->successful(), $r->status(), $r->successful() ? null : 'Endpoint answered HTTP ' . $r->status() . '.'];
    }
}
