<?php

namespace App\Support;

use App\Repositories\SettingRepository;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Runtime platform switches the admin controls from the dashboard (Admin → Platform Policy),
 * stored in the `settings` table (scope=global, keys prefixed `policy_`) — no .env edit / redeploy.
 *
 *  - e-mail rules: block list (exact address / domain / `*` wildcard), allow list (always wins),
 *    and on/off switches for each built-in check (disposable domains, "+tag"/dotted-Gmail patterns, DNS/MX);
 *  - public registration on/off;
 *  - API response encryption on/off (read by UipResponseEncryptionMiddleware / SecureHandshakeController).
 *
 * Reads are cached for a few seconds (and flushed on every save), and every read falls back to the
 * safe default when the DB is unreachable, so a settings outage can never lock people out.
 *
 * Rule syntax (one per entry, case-insensitive):
 *   spam.com            -> type "domain":  spam.com and every sub-domain (x.spam.com)
 *   @spam.com           -> same as above
 *   bad@x.com           -> type "email":   that exact address
 *   bot*@*.xyz          -> type "pattern": `*` matches any run of characters
 */
final class PlatformPolicy
{
    public const PREFIX = 'policy_';
    public const LIST_BLOCK = 'block';
    public const LIST_ALLOW = 'allow';
    private const RULES_KEY = ['block' => 'policy_email_block_rules', 'allow' => 'policy_email_allow_rules'];
    private const CACHE_TTL = 15;
    public const MAX_RULES = 2000;

    /** Boolean switches the admin can flip: key => default. Defaults for e-mail checks follow config/mail.php. */
    public const FLAGS = [
        'email_block_enabled'    => true,  // custom block list active
        'disposable_check'       => true,  // built-in temp-mail provider list
        'plus_addressing_check'  => true,  // name+tag@... aliases
        'suspicious_gmail_check' => true,  // dotted / numeric Gmail temp addr
        'email_dns_check'        => true,  // domain must have MX / A record (sign-up only)
        'registration_enabled'   => true,  // public sign-up (form + Google)
        'response_encryption'    => true,  // AES-GCM envelope on JSON responses
        'response_enc_enforce'   => false, // clients without a session key are refused (default = RESPONSE_ENC_ENFORCE)
        'request_enc_enforce'    => false, // plain JSON request bodies are refused   (default = REQUEST_ENC_ENFORCE)
        'persistent_login'       => true,  // Facebook-style: sessions never expire from inactivity; only an explicit sign-out / revoke / device-limit eviction ends them
        'animations_enabled'     => true,  // platform-wide UI motion (ambient backdrop, pill/chip + page-enter animations); OFF = a calm, static UI for every user
        'messaging_restricted'   => false, // ON = students may only start chats with their project group / own institution; OFF = anyone can message anyone
        'require_university_link' => true, // ON = a student account with no university can't use the platform (meetings, messaging, projects, My PC…) until a university/faculty approves it or the admin/university/faculty grants it access
    ];

    /**
     * Typed values the admin can edit instead of touching .env. `default` is read from config (.env) when
     * the admin never saved one, and "reset" simply deletes the row so the .env value applies again.
     * Secrets (keys, DB, JWT, proxy secret…) are deliberately NOT here.
     */
    public const VALUES = [
        // Android app API token (issued in /admin/mobile). Stored ENCRYPTED, never echoed back in full (see secret()).
        'app_token'            => ['type' => 'string', 'min' => 0,  'max' => 200, 'secret' => true], // VITE_APP_TOKEN
        'platform_name'        => ['type' => 'string', 'min' => 1,  'max' => 60],   // APP_NAME  (e-mail header / footer)
        'session_live_seconds' => ['type' => 'int',    'min' => 30, 'max' => 1800], // SESSION_LIVE_SECONDS
        'google_client_ids'    => ['type' => 'string', 'min' => 0,  'max' => 700],  // GOOGLE_CLIENT_IDS (public IDs)
        // Connect GitHub (OAuth App). Secret is stored ENCRYPTED and never echoed back in full.
        'github_client_id'     => ['type' => 'string', 'min' => 0,  'max' => 100],  // GITHUB_CLIENT_ID
        'github_client_secret' => ['type' => 'string', 'min' => 0,  'max' => 200, 'secret' => true], // GITHUB_CLIENT_SECRET
        'github_oauth_redirect' => ['type' => 'string', 'min' => 0, 'max' => 300], // GITHUB_OAUTH_REDIRECT
        // Android app download (shown on the landing page / footer). Empty = the "Get the app" buttons are hidden.
        'app_download_url'     => ['type' => 'string', 'min' => 0, 'max' => 500],
        'app_download_version' => ['type' => 'string', 'min' => 0, 'max' => 30],
        // Mobile push (Firebase Cloud Messaging). The service-account JSON is stored ENCRYPTED and never echoed back (see maskedFor()).
        'fcm_credentials_json' => ['type' => 'string', 'min' => 0, 'max' => 8000, 'secret' => true], // FCM_CREDENTIALS_JSON / FCM_CREDENTIALS_PATH
        'fcm_project_id'       => ['type' => 'string', 'min' => 0, 'max' => 100],                    // FCM_PROJECT_ID
        // حماية رموز OTP (OtpGuard): غلطات متراكمة => حظر، وحد إرسال في النافذة.
        'otp_max_wrong'           => ['type' => 'int', 'min' => 1, 'max' => 20],   // OTP_MAX_WRONG
        'otp_block_minutes'       => ['type' => 'int', 'min' => 1, 'max' => 1440], // OTP_BLOCK_MINUTES
        'otp_max_sends'           => ['type' => 'int', 'min' => 1, 'max' => 50],   // OTP_MAX_SENDS
        'otp_send_window_minutes' => ['type' => 'int', 'min' => 1, 'max' => 1440], // OTP_SEND_WINDOW_MINUTES
    ];

    /** @var array<string,string|null>|null */
    private static ?array $memo = null;
    private static int $memoAt = 0;

    // ───────────────────────── storage ─────────────────────────

    /** @return array<string,string|null> every `policy_*` row, cached briefly. Empty on any DB failure. */
    private static function all(): array
    {
        if (self::$memo !== null && (time() - self::$memoAt) < self::CACHE_TTL) {
            return self::$memo;
        }
        try {
            $rows = DB::table('settings')->where('scope', 'global')->whereNull('user_id')
                ->where('key', 'like', self::PREFIX . '%')->get(['key', 'value']);
            $out = [];
            foreach ($rows as $r) {
                $out[$r->key] = $r->value;
            }
        } catch (\Throwable $e) {
            // DB down / table missing: keep serving defaults for a few seconds instead of hammering it.
            $out = [];
        }
        self::$memo = $out;
        self::$memoAt = time();

        return $out;
    }

    public static function flush(): void
    {
        self::$memo = null;
        self::$memoAt = 0;
    }

    private static function put(string $key, string $value): void
    {
        app(SettingRepository::class)->set($key, $value, 'global', null);
        self::flush();
    }

    // ───────────────────────── switches ─────────────────────────

    /** Default of a switch when the admin never touched it (e-mail checks follow the .env/config values). */
    public static function defaultFor(string $flag): bool
    {
        return match ($flag) {
            'plus_addressing_check'  => (bool) config('mail.block_plus_addressing', true),
            'suspicious_gmail_check' => (bool) config('mail.block_suspicious_gmail', true),
            'response_enc_enforce'   => (bool) config('secure_transport.enforce', false),
            'request_enc_enforce'    => (bool) config('secure_transport.enforce_requests', false),
            default                  => self::FLAGS[$flag] ?? false,
        };
    }

    public static function flag(string $flag): bool
    {
        if (!array_key_exists($flag, self::FLAGS)) {
            return false;
        }
        $stored = self::all()[self::PREFIX . $flag] ?? null;

        return $stored === null ? self::defaultFor($flag) : $stored === '1';
    }

    /** @return array<string,bool> effective value of every switch */
    public static function flags(): array
    {
        $out = [];
        foreach (array_keys(self::FLAGS) as $k) {
            $out[$k] = self::flag($k);
        }

        return $out;
    }

    /** True when the switch was explicitly saved by an admin (vs. still on its default). */
    public static function isOverridden(string $flag): bool
    {
        return array_key_exists(self::PREFIX . $flag, self::all());
    }

    public static function setFlag(string $flag, bool $value): void
    {
        if (!array_key_exists($flag, self::FLAGS)) {
            throw new \InvalidArgumentException("Unknown policy switch: {$flag}");
        }
        self::put(self::PREFIX . $flag, $value ? '1' : '0');
    }

    /** Is the AES envelope active right now? (.env RESPONSE_ENC_ENABLED is the hard master switch.) */
    public static function encryptionOn(): bool
    {
        return (bool) config('secure_transport.enabled', true) && self::flag('response_encryption');
    }

    public static function registrationOpen(): bool
    {
        return self::flag('registration_enabled');
    }

    public static function registrationClosedMessage(string $locale): string
    {
        return $locale === 'ar'
            ? 'التسجيل الجديد مغلق حاليًا. تواصل مع إدارة المنصة.'
            : 'New registrations are currently closed. Please contact the platform administrators.';
    }

    /** Response enforcement actually applied (dashboard value, else .env). Only meaningful while encryption is on. */
    public static function responseEncEnforced(): bool
    {
        return self::flag('response_enc_enforce');
    }

    public static function requestEncEnforced(): bool
    {
        return self::flag('request_enc_enforce');
    }

    // ───────────────────────── editable values ─────────────────────────

    /** The .env / config value used while the admin has not saved one. */
    public static function defaultValue(string $key): string|int
    {
        return match ($key) {
            'platform_name'        => (string) config('app.name', 'UIP'),
            'session_live_seconds' => max(30, (int) config('security.single_session.live_seconds', 120)),
            'google_client_ids'    => (string) config('services.google.client_ids', ''),
            'app_token'            => (string) config('site.app_token', ''),
            'github_client_id'     => (string) config('services.github.client_id', ''),
            'github_client_secret' => (string) config('services.github.client_secret', ''),
            'github_oauth_redirect' => (string) config('services.github.redirect', ''),
            'fcm_credentials_json' => (string) config('services.fcm.credentials_json', ''),
            'fcm_project_id'       => (string) config('services.fcm.project_id', ''),
            'otp_max_wrong'           => max(1, (int) config('security.otp.max_wrong', 5)),
            'otp_block_minutes'       => max(1, (int) config('security.otp.block_minutes', 10)),
            'otp_max_sends'           => max(1, (int) config('security.otp.max_sends', 5)),
            'otp_send_window_minutes' => max(1, (int) config('security.otp.send_window_minutes', 60)),
            default                => '',
        };
    }

    public static function isValueOverridden(string $key): bool
    {
        return array_key_exists(self::PREFIX . $key, self::all());
    }

    /** Effective value: what the dashboard saved, otherwise the .env default. */
    public static function value(string $key): string|int
    {
        $def = self::VALUES[$key] ?? null;
        if ($def === null) {
            return '';
        }
        $stored = self::all()[self::PREFIX . $key] ?? null;
        if ($stored === null) {
            return self::defaultValue($key);
        }
        if (!empty($def['secret'])) {
            try {
                return Crypt::decryptString($stored);
            } catch (\Throwable $e) {
                return ''; // APP_KEY rotated / corrupt row → treated as "not set"
            }
        }

        return $def['type'] === 'int' ? (int) $stored : (string) $stored;
    }

    /** First 4 + last 4 chars only, so the dashboard / audit log can show "a token is set" without leaking it. */
    public static function mask(string $secret): string
    {
        $n = mb_strlen($secret);
        if ($n === 0) {
            return '';
        }

        return $n <= 12 ? str_repeat('•', $n) : mb_substr($secret, 0, 4) . str_repeat('•', 8) . mb_substr($secret, -4);
    }

    /** Masked preview of a secret for the dashboard / audit log. The Firebase key shows which service account is saved (its e-mail is not a secret). */
    public static function maskedFor(string $key, string $secret): string
    {
        if ($key === 'fcm_credentials_json' && $secret !== '') {
            $j = json_decode($secret, true);
            if (is_array($j) && !empty($j['client_email'])) {
                return $j['client_email'] . ' (' . ($j['project_id'] ?? '?') . ')';
            }
        }

        return self::mask($secret);
    }

    /** @return array<string,string|int> effective values; secrets are masked (use value() for the real one). */
    public static function values(): array
    {
        $out = [];
        foreach (array_keys(self::VALUES) as $k) {
            $v = self::value($k);
            $out[$k] = !empty(self::VALUES[$k]['secret']) ? self::maskedFor($k, (string) $v) : $v;
        }

        return $out;
    }

    /**
     * Validates what the admin typed. Returns [value, null] or [null, error message].
     * @return array{0:string|int|null,1:?string}
     */
    public static function normalizeValue(string $key, $raw): array
    {
        $def = self::VALUES[$key] ?? null;
        if ($def === null) {
            return [null, "Unknown setting: {$key}"];
        }
        if ($def['type'] === 'int') {
            if (!is_numeric($raw) || (string) (int) $raw !== trim((string) $raw)) {
                return [null, "{$key} must be a whole number."];
            }
            $n = (int) $raw;
            if ($n < $def['min'] || $n > $def['max']) {
                return [null, "{$key} must be between {$def['min']} and {$def['max']}."];
            }

            return [$n, null];
        }
        if ($key === 'fcm_credentials_json') {
            return self::normalizeFcmCredentials((string) $raw);
        }
        $v = trim((string) $raw);
        if (preg_match('/[\x00-\x1F\x7F<>]/u', $v)) {
            return [null, "{$key} contains characters that are not allowed."];
        }
        if (mb_strlen($v) < $def['min'] || mb_strlen($v) > $def['max']) {
            return [null, $def['min'] > 0
                ? "{$key} must be {$def['min']}–{$def['max']} characters."
                : "{$key} is too long (max {$def['max']})."];
        }
        if ($key === 'app_token' && $v !== '') {
            if (!preg_match('/^[A-Za-z0-9._~+\/=-]+$/', $v)) {
                return [null, 'The token has characters that are not allowed.'];
            }
            if (!app(\App\Repositories\ApiTokenRepository::class)->findActiveByRawToken($v)) {
                return [null, 'This is not an active token. Issue one from Mobile App Management and paste it here.'];
            }
        }
        if (in_array($key, ['github_client_id', 'github_client_secret'], true) && $v !== '' && !preg_match('/^[A-Za-z0-9._-]+$/', $v)) {
            return [null, 'The GitHub value has characters that are not allowed (letters, digits, . _ - only).'];
        }
        if ($key === 'app_download_url' && $v !== '') {
            if (!filter_var($v, FILTER_VALIDATE_URL) || !preg_match('#^https://#i', $v)) {
                return [null, 'The download link must be a full https:// address.'];
            }
        }
        if ($key === 'app_download_version' && $v !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._ -]{0,29}$/', $v)) {
            return [null, 'The version may only contain letters, digits, dots, dashes and spaces (for example 1.4.0).'];
        }
        if ($key === 'fcm_project_id' && $v !== '' && !preg_match('/^[a-z0-9][a-z0-9-]{3,98}[a-z0-9]$/', $v)) {
            return [null, 'The Firebase project ID may only contain lowercase letters, digits and hyphens (for example uip-nub).'];
        }
        if ($key === 'github_oauth_redirect' && $v !== '') {
            if (!filter_var($v, FILTER_VALIDATE_URL) || !preg_match('#^https://#i', $v) && !preg_match('#^http://(localhost|127\.0\.0\.1)#i', $v)) {
                return [null, 'The callback URL must be a full https:// address.'];
            }
            if (!preg_match('#/api/v1/github/callback/?$#', $v)) {
                return [null, 'The callback URL must end with /api/v1/github/callback.'];
            }
        }
        if ($key === 'google_client_ids' && $v !== '') {
            $ids = array_values(array_filter(array_map('trim', explode(',', $v))));
            if (count($ids) > 5) {
                return [null, 'At most 5 Google client IDs.'];
            }
            foreach ($ids as $id) {
                if (!preg_match('/^[A-Za-z0-9._-]+\.apps\.googleusercontent\.com$/', $id)) {
                    return [null, "\"{$id}\" is not a valid Google client ID (…apps.googleusercontent.com)."];
                }
            }
            $v = implode(',', $ids);
        }

        return [$v, null];
    }

    /**
     * Accepts the Firebase service-account file pasted as JSON (or its base64) and stores one compact JSON line.
     * @return array{0:?string,1:?string}
     */
    private static function normalizeFcmCredentials(string $raw): array
    {
        $v = trim($raw);
        if ($v === '') {
            return ['', null];
        }
        if ($v[0] !== '{') {
            $dec = base64_decode((string) preg_replace('/\s+/', '', $v), true);
            if ($dec === false) {
                return [null, 'Paste the contents of the Firebase service-account .json file (or its base64).'];
            }
            $v = trim($dec);
        }
        $json = json_decode($v, true);
        if (!is_array($json) || ($json['type'] ?? '') !== 'service_account'
            || empty($json['client_email']) || empty($json['private_key']) || empty($json['project_id'])) {
            return [null, 'This is not a Firebase service-account key. Download it from Firebase Console → Project settings → Service accounts → Generate new private key.'];
        }
        if (!is_string($json['private_key']) || !str_contains($json['private_key'], 'BEGIN PRIVATE KEY')) {
            return [null, 'The private_key inside the file is not valid.'];
        }
        $keep = array_intersect_key($json, array_flip(['type', 'project_id', 'private_key_id', 'private_key', 'client_email', 'client_id', 'token_uri']));
        $compact = json_encode($keep, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($compact === false || strlen($compact) > self::VALUES['fcm_credentials_json']['max']) {
            return [null, 'The file is too large to be a service-account key.'];
        }

        return [$compact, null];
    }

    public static function setValue(string $key, string|int $value): void
    {
        if (!isset(self::VALUES[$key])) {
            throw new \InvalidArgumentException("Unknown setting: {$key}");
        }
        $stored = !empty(self::VALUES[$key]['secret']) ? Crypt::encryptString((string) $value) : (string) $value;
        self::put(self::PREFIX . $key, $stored);
    }

    /** The Android app token to hand to official builds: dashboard value, else VITE_APP_TOKEN-equivalent env (APP_TOKEN). */
    public static function appToken(): string
    {
        return (string) self::value('app_token');
    }

    /** Drops the dashboard value so the .env default applies again. */
    public static function resetValue(string $key): void
    {
        if (!isset(self::VALUES[$key])) {
            throw new \InvalidArgumentException("Unknown setting: {$key}");
        }
        DB::table('settings')->where('scope', 'global')->whereNull('user_id')->where('key', self::PREFIX . $key)->delete();
        self::flush();
    }

    // ───────────────────────── devices per account ─────────────────────────

    public const DEVICES_KEY = 'policy_devices';
    /** What happens when a sign-in would exceed the limit: sign the oldest device out, or refuse the new sign-in. */
    public const DEVICE_MODES = ['replace_oldest', 'block'];
    /** Upper bound the dashboard accepts for one role. 0 always means "no limit". */
    public const DEVICE_LIMIT_MAX = 1000000;

    /** Limit used for a role the admin never configured (the old "one session per account" behaviour unless .env turned it off). 0 = unlimited. */
    public static function defaultDeviceLimit(): int
    {
        return (bool) config('security.single_session.enabled', true) ? 1 : 0;
    }

    /**
     * @return array{default:int,roles:array<string,int>,mode:string}
     *   default = limit for roles not listed; roles = per-role overrides; 0 = unlimited.
     */
    public static function deviceConfig(): array
    {
        $cfg = ['default' => self::defaultDeviceLimit(), 'roles' => [], 'mode' => 'replace_oldest'];
        $raw = self::all()[self::DEVICES_KEY] ?? null;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return $cfg;
        }
        if (isset($decoded['default']) && is_numeric($decoded['default'])) {
            $cfg['default'] = max(0, min(self::DEVICE_LIMIT_MAX, (int) $decoded['default']));
        }
        if (is_array($decoded['roles'] ?? null)) {
            foreach ($decoded['roles'] as $slug => $n) {
                if (is_string($slug) && $slug !== '' && is_numeric($n)) {
                    $cfg['roles'][$slug] = max(0, min(self::DEVICE_LIMIT_MAX, (int) $n));
                }
            }
        }
        if (in_array($decoded['mode'] ?? null, self::DEVICE_MODES, true)) {
            $cfg['mode'] = $decoded['mode'];
        }

        return $cfg;
    }

    public static function devicesOverridden(): bool
    {
        return array_key_exists(self::DEVICES_KEY, self::all());
    }

    /** Devices a role may be signed in on at the same time. 0 = unlimited. */
    public static function deviceLimitFor(?string $role): int
    {
        $cfg = self::deviceConfig();

        return ($role !== null && array_key_exists($role, $cfg['roles'])) ? $cfg['roles'][$role] : $cfg['default'];
    }

    public static function deviceMode(): string
    {
        return self::deviceConfig()['mode'];
    }

    /**
     * Validates what the dashboard sent: { default?: int, roles?: { slug: int }, mode?: string }.
     * Missing parts keep their current value. Returns [config, null] or [null, error].
     * @return array{0:?array{default:int,roles:array<string,int>,mode:string},1:?string}
     */
    public static function normalizeDevices($input): array
    {
        if (!is_array($input)) {
            return [null, 'Send the device limits as an object.'];
        }
        $cfg = self::deviceConfig();

        $check = static function ($raw, string $label): array {
            if (is_string($raw)) {
                $raw = trim($raw);
            }
            if (is_bool($raw) || $raw === '' || $raw === null || !is_numeric($raw) || (float) $raw != (int) $raw) {
                return [null, "{$label} must be a whole number (0 = unlimited)."];
            }
            $n = (int) $raw;
            if ($n < 0 || $n > self::DEVICE_LIMIT_MAX) {
                return [null, "{$label} must be between 0 (unlimited) and " . self::DEVICE_LIMIT_MAX . '.'];
            }

            return [$n, null];
        };

        if (array_key_exists('default', $input)) {
            [$n, $err] = $check($input['default'], 'The default limit');
            if ($err) {
                return [null, $err];
            }
            $cfg['default'] = $n;
        }
        if (array_key_exists('roles', $input)) {
            if (!is_array($input['roles'])) {
                return [null, 'Roles must be an object of role => limit.'];
            }
            $roles = [];
            foreach ($input['roles'] as $slug => $raw) {
                $slug = (string) $slug;
                if (!preg_match('/^[a-z0-9_.-]{1,50}$/i', $slug)) {
                    return [null, "\"{$slug}\" is not a valid role."];
                }
                [$n, $err] = $check($raw, "The limit for {$slug}");
                if ($err) {
                    return [null, $err];
                }
                $roles[$slug] = $n;
            }
            $cfg['roles'] = $roles;
        }
        if (array_key_exists('mode', $input)) {
            if (!in_array($input['mode'], self::DEVICE_MODES, true)) {
                return [null, 'The mode must be "replace_oldest" or "block".'];
            }
            $cfg['mode'] = $input['mode'];
        }

        return [$cfg, null];
    }

    public static function setDevices(array $cfg): void
    {
        self::put(self::DEVICES_KEY, json_encode([
            'default' => (int) $cfg['default'],
            'roles'   => (object) $cfg['roles'],
            'mode'    => (string) $cfg['mode'],
        ], JSON_UNESCAPED_UNICODE));
    }

    /** Back to the server default (one device per account, newest sign-in wins). */
    public static function resetDevices(): void
    {
        DB::table('settings')->where('scope', 'global')->whereNull('user_id')->where('key', self::DEVICES_KEY)->delete();
        self::flush();
    }

    // ───────────────────────── e-mail rules ─────────────────────────

    /**
     * Turns what the admin typed into a canonical rule, or null when it is not a usable rule.
     * @return array{0:string,1:string}|null [value, type]
     */
    public static function normalize($raw): ?array
    {
        $v = mb_strtolower(trim((string) $raw));
        $v = trim($v, " \t\n\r\0\x0B,;");
        if ($v === '' || mb_strlen($v) > 190 || preg_match('/[\s,;<>"\'\\\\\/:]/u', $v)) {
            return null;
        }
        if ($v[0] === '@') {
            $v = substr($v, 1);
        }
        if (substr_count($v, '@') > 1) {
            return null;
        }
        $hasWildcard = str_contains($v, '*');
        if (!str_contains($v, '@')) {
            // bare domain (optionally with wildcards such as *.xyz)
            if ($hasWildcard) {
                return self::meaningful($v) ? ['*@' . $v, 'pattern'] : null;
            }

            return preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z0-9-]{2,}$/', $v) === 1 ? [$v, 'domain'] : null;
        }
        [$local, $domain] = explode('@', $v, 2);
        if ($local === '' || $domain === '') {
            return null;
        }
        if ($hasWildcard) {
            return self::meaningful($v) ? [$v, 'pattern'] : null;
        }
        if (!preg_match('/^[a-z0-9.!#$%&\'*+\/=?^_`{|}~-]+$/', $local)
            || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z0-9-]{2,}$/', $domain) !== 1) {
            return null;
        }

        return [$v, 'email'];
    }

    /** A wildcard rule must keep at least 2 literal characters (so `*@*.tk` is fine), so `*`, `*@*` or `*.*` can never block everybody. */
    private static function meaningful(string $v): bool
    {
        return mb_strlen(str_replace(['*', '@', '.'], '', $v)) >= 2;
    }

    /** @param array{value:string,type:string} $rule */
    public static function ruleMatches(array $rule, string $email): bool
    {
        $email = mb_strtolower(trim($email));
        $value = (string) ($rule['value'] ?? '');
        if ($email === '' || $value === '') {
            return false;
        }
        switch ($rule['type'] ?? '') {
            case 'email':
                return $email === $value;
            case 'domain':
                $domain = DisposableEmail::domainOf($email);

                return $domain !== '' && ($domain === $value || str_ends_with($domain, '.' . $value));
            case 'pattern':
                $re = '/^' . str_replace('\*', '.*', preg_quote($value, '/')) . '$/u';

                return preg_match($re, $email) === 1;
        }

        return false;
    }

    /** @return array<int,array{value:string,type:string,note:?string,added_by:?int,added_at:?string}> */
    public static function rules(string $list): array
    {
        $key = self::RULES_KEY[$list] ?? null;
        if ($key === null) {
            return [];
        }
        $decoded = json_decode((string) (self::all()[$key] ?? ''), true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $r) {
            if (is_array($r) && isset($r['value'], $r['type'])) {
                $out[] = [
                    'value'    => (string) $r['value'],
                    'type'     => (string) $r['type'],
                    'note'     => isset($r['note']) ? (string) $r['note'] : null,
                    'added_by' => isset($r['added_by']) ? (int) $r['added_by'] : null,
                    'added_at' => isset($r['added_at']) ? (string) $r['added_at'] : null,
                ];
            }
        }

        return $out;
    }

    /** First rule of the list that matches the address, or null. */
    public static function matchIn(string $list, $email): ?array
    {
        foreach (self::rules($list) as $rule) {
            if (self::ruleMatches($rule, (string) $email)) {
                return $rule;
            }
        }

        return null;
    }

    /** Allow list always wins over every other e-mail check. */
    public static function emailAllowed($email): bool
    {
        return self::matchIn(self::LIST_ALLOW, $email) !== null;
    }

    /** The custom block rule that refuses this address (null when the block list is off or nothing matches). */
    public static function emailBlockedBy($email): ?array
    {
        return self::flag('email_block_enabled') ? self::matchIn(self::LIST_BLOCK, $email) : null;
    }

    /**
     * Adds many rules at once. Invalid entries and duplicates are reported, not fatal.
     * @param iterable<string> $values
     * @return array{added:array<int,array>,invalid:array<int,string>,duplicates:array<int,string>,full:bool}
     */
    public static function addRules(string $list, iterable $values, ?string $note, ?int $adminId, array $protectEmails = []): array
    {
        $key = self::RULES_KEY[$list] ?? throw new \InvalidArgumentException('Unknown list');
        $current = self::rules($list);
        $have = array_column($current, 'value');
        $res = ['added' => [], 'invalid' => [], 'duplicates' => [], 'protected' => [], 'full' => false];
        $note = $note !== null ? mb_substr(trim($note), 0, 190) : null;

        foreach ($values as $raw) {
            if (trim((string) $raw) === '') {
                continue;
            }
            $norm = self::normalize($raw);
            if ($norm === null) {
                $res['invalid'][] = (string) $raw;
                continue;
            }
            [$value, $type] = $norm;
            if (in_array($value, $have, true)) {
                $res['duplicates'][] = $value;
                continue;
            }
            // Never let the admin block their own address (or the address they are protecting) by accident.
            if ($list === self::LIST_BLOCK) {
                foreach ($protectEmails as $mine) {
                    if (self::ruleMatches(['value' => $value, 'type' => $type], (string) $mine)) {
                        $res['protected'][] = $value;
                        continue 2;
                    }
                }
            }
            if (count($current) >= self::MAX_RULES) {
                $res['full'] = true;
                break;
            }
            $rule = ['value' => $value, 'type' => $type, 'note' => $note ?: null, 'added_by' => $adminId, 'added_at' => gmdate('c')];
            $current[] = $rule;
            $have[] = $value;
            $res['added'][] = $rule;
        }
        if ($res['added']) {
            self::put($key, json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return $res;
    }

    /** @return bool true when something was removed */
    public static function removeRule(string $list, string $value): bool
    {
        $key = self::RULES_KEY[$list] ?? throw new \InvalidArgumentException('Unknown list');
        $value = mb_strtolower(trim($value));
        $current = self::rules($list);
        $kept = array_values(array_filter($current, fn ($r) => $r['value'] !== $value));
        if (count($kept) === count($current)) {
            return false;
        }
        self::put($key, json_encode($kept, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return true;
    }
}
