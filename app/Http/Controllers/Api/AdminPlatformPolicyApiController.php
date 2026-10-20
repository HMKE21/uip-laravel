<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\ResponseCryptoService;
use App\Support\DisposableEmail;
use App\Support\PlatformPolicy;
use App\Support\StaffGate;
use Illuminate\Http\Request;

/**
 * Admin → Platform Policy. One JSON surface for the switches an admin wants without a deploy:
 *
 *   GET    /api/v1/admin/platform-policy            current switches, block / allow lists, server facts
 *   PATCH  /api/v1/admin/platform-policy/flags      { flags: { <key>: bool, ... } }
 *   POST   /api/v1/admin/platform-policy/rules      { list: block|allow, values: "a, b\nc" | [..], note? }
 *   DELETE /api/v1/admin/platform-policy/rules      { list, value }
 *   POST   /api/v1/admin/platform-policy/test       { email }  -> would this address be refused, and why
 *   GET    /api/v1/platform/status                  (public) { registration_enabled, response_encryption, animations_enabled }
 *
 * Everything lives in the `settings` table (see App\Support\PlatformPolicy). Every change is audit-logged.
 * RBAC: uip.auth + uip.admin on the route group, plus the same uip_role === 'admin' guard as the other admin controllers.
 */
class AdminPlatformPolicyApiController extends Controller
{
    private const MAX_VALUES_PER_REQUEST = 500;

    public function __construct(private AuditLogService $auditLog)
    {
    }

    private function requireAdmin(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'admin') {
            return $this->apiError('Only admin accounts can manage the platform policy.', null, 403);
        }

        return null;
    }

    private function adminId(Request $request): int
    {
        return (int) $request->attributes->get('uip_user_id');
    }

    private function locale(Request $request): string
    {
        return $request->header('X-Locale', 'en') === 'ar' ? 'ar' : 'en';
    }

    /** @return array<string,mixed> */
    private function snapshot(): array
    {
        $flags = PlatformPolicy::flags();
        $defaults = [];
        $overridden = [];
        foreach (array_keys(PlatformPolicy::FLAGS) as $k) {
            $defaults[$k] = PlatformPolicy::defaultFor($k);
            $overridden[$k] = PlatformPolicy::isOverridden($k);
        }

        $selfTest = null;
        try {
            $selfTest = app(ResponseCryptoService::class)->selfTest();
        } catch (\Throwable $e) {
            // leaves null: the page shows "unknown" instead of failing to load
        }

        return [
            'flags'      => $flags,
            'defaults'   => $defaults,
            'overridden' => $overridden,
            'rules'      => [
                PlatformPolicy::LIST_BLOCK => PlatformPolicy::rules(PlatformPolicy::LIST_BLOCK),
                PlatformPolicy::LIST_ALLOW => PlatformPolicy::rules(PlatformPolicy::LIST_ALLOW),
            ],
            'values'     => $this->valuesPayload(),
            'devices'    => $this->devicesPayload(),
            'limits'     => ['max_rules' => PlatformPolicy::MAX_RULES, 'max_values_per_request' => self::MAX_VALUES_PER_REQUEST],
            // Hard switches in .env that sit above the dashboard: when false the dashboard cannot turn the feature on.
            'server'     => [
                'email_checks_master'  => DisposableEmail::enabled(),            // MAIL_BLOCK_DISPOSABLE
                'encryption_master'    => (bool) config('secure_transport.enabled', true), // RESPONSE_ENC_ENABLED
                'encryption_enforced'  => PlatformPolicy::responseEncEnforced(), // RESPONSE_ENC_ENFORCE (or the dashboard value)
                'encryption_selftest'  => $selfTest,
                'encryption_effective' => PlatformPolicy::encryptionOn(),
            ],
        ];
    }

    /**
     * Devices-per-account settings + every role the admin can configure (system and custom), each with the limit that
     * actually applies to it right now (its own number, or the default when none was set). 0 = unlimited.
     * @return array<string,mixed>
     */
    private function devicesPayload(): array
    {
        $cfg = PlatformPolicy::deviceConfig();
        $roles = [];
        try {
            $roles = \Illuminate\Support\Facades\DB::table('roles')->orderBy('is_system', 'desc')->orderBy('id')->get(['slug', 'name_ar', 'name_en'])
                ->map(fn ($r) => [
                    'slug'       => $r->slug,
                    'name_ar'    => $r->name_ar,
                    'name_en'    => $r->name_en,
                    'limit'      => PlatformPolicy::deviceLimitFor($r->slug),
                    'overridden' => array_key_exists($r->slug, $cfg['roles']),
                ])->all();
        } catch (\Throwable $e) {
            // roles table unavailable: the page still shows the default + mode
        }

        return [
            'default'     => $cfg['default'],
            'mode'        => $cfg['mode'],
            'modes'       => PlatformPolicy::DEVICE_MODES,
            'roles'       => $roles,
            'overridden'  => PlatformPolicy::devicesOverridden(),
            'server_default' => PlatformPolicy::defaultDeviceLimit(),
            'max'         => PlatformPolicy::DEVICE_LIMIT_MAX,
        ];
    }

    /**
     * PUT /api/v1/admin/platform-policy/devices — { default?: int, mode?: replace_oldest|block, roles?: { slug: int } }
     * 0 means unlimited. Missing keys keep their current value; "roles" replaces the whole per-role map.
     * Devices already signed in are not touched — the new limit applies from the next sign-in on.
     */
    public function updateDevices(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        [$cfg, $error] = PlatformPolicy::normalizeDevices($request->all());
        if ($error !== null) {
            return $this->apiError($error, ['devices' => 'invalid'], 422);
        }
        // Only roles that exist can get a limit (a typo must not silently create a dead rule).
        try {
            $known = \Illuminate\Support\Facades\DB::table('roles')->pluck('slug')->all();
            $unknown = array_values(array_diff(array_keys($cfg['roles']), $known));
            if ($known && $unknown) {
                return $this->apiError('Unknown role: ' . implode(', ', $unknown), ['roles' => $unknown], 422);
            }
        } catch (\Throwable $e) {
            // can't verify: accept
        }

        $before = PlatformPolicy::deviceConfig();
        PlatformPolicy::setDevices($cfg);
        $after = PlatformPolicy::deviceConfig();
        if ($before !== $after) {
            $this->auditLog->record($this->adminId($request), 'platform.policy_devices_update', 'Setting', null, $before, $after, $request->ip());
        }

        return $this->apiSuccess($this->snapshot(), $before !== $after ? 'Device limits saved.' : 'Nothing changed.');
    }

    /** DELETE /api/v1/admin/platform-policy/devices — back to the server default (one device per account). */
    public function resetDevices(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }
        $before = PlatformPolicy::deviceConfig();
        PlatformPolicy::resetDevices();
        $this->auditLog->record($this->adminId($request), 'platform.policy_devices_reset', 'Setting', null, $before, PlatformPolicy::deviceConfig(), $request->ip());

        return $this->apiSuccess($this->snapshot(), 'Device limits reset to the server default.');
    }

    /** @return array<string,array{value:string|int,default:string|int,overridden:bool,type:string,min:int,max:int}> */
    private function valuesPayload(): array
    {
        $out = [];
        foreach (PlatformPolicy::VALUES as $k => $def) {
            $out[$k] = [
                // secrets are masked: the dashboard only learns that one is set
                'value'      => !empty($def['secret']) ? PlatformPolicy::maskedFor($k, (string) PlatformPolicy::value($k)) : PlatformPolicy::value($k),
                'secret'     => !empty($def['secret']),
                'default'    => !empty($def['secret']) ? '' : PlatformPolicy::defaultValue($k),
                'overridden' => PlatformPolicy::isValueOverridden($k),
                'type'       => $def['type'],
                'min'        => $def['min'],
                'max'        => $def['max'],
            ];
        }

        return $out;
    }

    /** GET /api/v1/admin/platform-policy */
    public function show(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        return $this->apiSuccess($this->snapshot(), 'Platform policy retrieved successfully.');
    }

    /** PATCH /api/v1/admin/platform-policy/flags — { flags: { key: bool } } (a flat { key: bool } body works too). */
    public function updateFlags(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $input = $request->input('flags');
        if (!is_array($input)) {
            $input = $request->only(array_keys(PlatformPolicy::FLAGS));
        }
        if ($input === []) {
            return $this->apiError('No switches were sent.', null, 422);
        }
        $unknown = array_diff(array_keys($input), array_keys(PlatformPolicy::FLAGS));
        if ($unknown) {
            return $this->apiError('Unknown switch: ' . implode(', ', array_map('strval', $unknown)), ['flags' => array_values($unknown)], 422);
        }

        $before = PlatformPolicy::flags();
        $changed = [];
        foreach ($input as $key => $raw) {
            $value = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($value === null) {
                return $this->apiError("The value of {$key} must be true or false.", ['flags' => [$key]], 422);
            }
            if ($before[$key] !== $value || !PlatformPolicy::isOverridden($key)) {
                PlatformPolicy::setFlag($key, $value);
            }
            if ($before[$key] !== $value) {
                $changed[$key] = $value;
            }
        }

        if ($changed) {
            $this->auditLog->record(
                $this->adminId($request),
                'platform.policy_flags_update',
                'Setting',
                null,
                array_intersect_key($before, $changed),
                $changed,
                $request->ip()
            );
        }

        return $this->apiSuccess($this->snapshot(), $changed ? 'Platform policy saved.' : 'Nothing changed.');
    }

    /**
     * PUT /api/v1/admin/platform-policy/values — { values: { platform_name: "UIP", session_live_seconds: 90, google_client_ids: null } }
     * A null value resets that setting to its .env default.
     */
    public function updateValues(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $input = $request->input('values');
        if (!is_array($input) || $input === []) {
            return $this->apiError('No settings were sent.', null, 422);
        }
        $unknown = array_diff(array_keys($input), array_keys(PlatformPolicy::VALUES));
        if ($unknown) {
            return $this->apiError('Unknown setting: ' . implode(', ', array_map('strval', $unknown)), ['values' => array_values($unknown)], 422);
        }

        // Validate everything first so a bad field never leaves a half-saved form.
        $clean = [];
        foreach ($input as $key => $raw) {
            if ($raw === null) {
                $clean[$key] = null;
                continue;
            }
            [$value, $error] = PlatformPolicy::normalizeValue($key, $raw);
            if ($error !== null) {
                return $this->apiError($error, ['values' => [$key]], 422);
            }
            $clean[$key] = $value;
        }

        $before = PlatformPolicy::values();
        foreach ($clean as $key => $value) {
            $value === null ? PlatformPolicy::resetValue($key) : PlatformPolicy::setValue($key, $value);
        }
        $after = PlatformPolicy::values();
        $changed = array_filter($after, fn ($v, $k) => array_key_exists($k, $clean) && $before[$k] !== $v, ARRAY_FILTER_USE_BOTH);

        if ($changed) {
            $this->auditLog->record(
                $this->adminId($request),
                'platform.policy_values_update',
                'Setting',
                null,
                array_intersect_key($before, $changed),
                $changed,
                $request->ip()
            );
        }

        return $this->apiSuccess($this->snapshot(), $changed ? 'Platform settings saved.' : 'Nothing changed.');
    }

    /** POST /api/v1/admin/platform-policy/rules */
    public function addRules(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $list = (string) $request->input('list', '');
        if (!in_array($list, [PlatformPolicy::LIST_BLOCK, PlatformPolicy::LIST_ALLOW], true)) {
            return $this->apiError('The list must be "block" or "allow".', ['list' => 'invalid'], 422);
        }

        $values = $request->input('values', $request->input('value'));
        if (is_string($values)) {
            $values = preg_split('/[\s,;]+/u', trim($values), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (!is_array($values) || $values === []) {
            return $this->apiError('Enter at least one email address or domain.', ['values' => 'required'], 422);
        }
        $values = array_values(array_filter($values, 'is_scalar'));
        if (count($values) > self::MAX_VALUES_PER_REQUEST) {
            return $this->apiError('Too many entries in one request (max ' . self::MAX_VALUES_PER_REQUEST . ').', ['values' => 'too_many'], 422);
        }

        $adminId = $this->adminId($request);
        $me = User::find($adminId);
        $res = PlatformPolicy::addRules(
            $list,
            $values,
            $request->filled('note') ? (string) $request->input('note') : null,
            $adminId,
            $me && $me->email ? [$me->email] : []
        );

        if ($res['added']) {
            $this->auditLog->record(
                $adminId,
                'platform.policy_rules_add',
                'Setting',
                null,
                null,
                ['list' => $list, 'values' => array_column($res['added'], 'value')],
                $request->ip()
            );
        }

        $message = $res['added']
            ? count($res['added']) . ' rule(s) added.'
            : 'No new rules were added.';

        return $this->apiSuccess($this->snapshot() + ['result' => $res], $message);
    }

    /** DELETE /api/v1/admin/platform-policy/rules — { list, value } */
    public function removeRule(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $list = (string) $request->input('list', '');
        $value = (string) $request->input('value', '');
        if (!in_array($list, [PlatformPolicy::LIST_BLOCK, PlatformPolicy::LIST_ALLOW], true) || trim($value) === '') {
            return $this->apiError('The list and the rule are required.', null, 422);
        }

        if (!PlatformPolicy::removeRule($list, $value)) {
            return $this->apiError('That rule was not found.', null, 404);
        }

        $this->auditLog->record(
            $this->adminId($request),
            'platform.policy_rule_remove',
            'Setting',
            null,
            ['list' => $list, 'value' => mb_strtolower(trim($value))],
            null,
            $request->ip()
        );

        return $this->apiSuccess($this->snapshot(), 'Rule removed.');
    }

    /** POST /api/v1/admin/platform-policy/test — { email } → what the sign-up form would say for it (DNS included). */
    public function test(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $email = trim((string) $request->input('email', ''));
        if ($email === '' || mb_strlen($email) > 254 || !str_contains($email, '@')) {
            return $this->apiError('Enter a full email address to test.', ['email' => 'invalid'], 422);
        }

        $verdict = DisposableEmail::diagnose($email, $this->locale($request), true);

        return $this->apiSuccess([
            'email'   => mb_strtolower($email),
            'allowed' => $verdict['ok'],
            'code'    => $verdict['code'],
            'rule'    => $verdict['rule'],
            'message' => $verdict['message'],
        ], $verdict['ok'] ? 'This address would be accepted.' : 'This address would be refused.');
    }

    /**
     * POST /api/v1/admin/platform-policy/decrypt — { envelope: '{"enc":"v1","kid":"…","d":"…"}' | "<d>", kid?, status? }
     * Decrypts a captured API response using the session key held in cache (what `Cache::get('resp_enc:<kid>')` was for),
     * without ever returning the key. Works only while the key is alive (secure_transport.key_ttl, default 2 h).
     */
    public function decrypt(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $input = trim((string) $request->input('envelope', ''));
        if ($input === '' || strlen($input) > 2_000_000) {
            return $this->apiError('Paste the encrypted response (the {"enc":"v1",…} text from the Network tab).', ['envelope' => 'required'], 422);
        }
        $kid = strtolower(trim((string) $request->input('kid', '')));
        $d = $input;
        if (str_starts_with($input, '{')) {
            $env = json_decode($input, true);
            if (!is_array($env) || !isset($env['d'])) {
                return $this->apiError('That is not a valid encrypted response.', ['envelope' => 'invalid'], 422);
            }
            $d = (string) $env['d'];
            $kid = $kid !== '' ? $kid : strtolower((string) ($env['kid'] ?? ''));
        }
        if (!preg_match('/^[a-f0-9]{32}$/', $kid)) {
            return $this->apiError('Key ID (kid) is missing or malformed.', ['kid' => 'invalid'], 422);
        }
        $status = $request->filled('status') ? (int) $request->input('status') : null;

        $crypto = app(ResponseCryptoService::class);
        if ($crypto->keysFor($kid) === null) {
            return $this->apiError('That key is gone — response keys live only for a couple of hours after the page was opened.', ['kid' => 'expired'], 404);
        }
        $res = $crypto->decryptResponse($d, $kid, $status);
        if ($res === null) {
            return $this->apiError('The key exists but this data does not decrypt with it (wrong kid, truncated text, or not from that session).', ['envelope' => 'mismatch'], 422);
        }

        // content is deliberately NOT logged — only who looked and which key
        $this->auditLog->record($this->adminId($request), 'platform.response_decrypt', 'Setting', null, null, ['kid' => $kid, 'bytes' => strlen($res['plain'])], $request->ip());

        $json = json_decode($res['plain'], true);

        return $this->apiSuccess([
            'status'  => $res['status'],
            'json'    => $json !== null ? $json : null,
            'text'    => $json !== null ? json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $res['plain'],
        ], 'Decrypted.')->header('Cache-Control', 'no-store');
    }

    // ───────────── staff access codes ─────────────

    private function gatePayload(): array
    {
        return ['enabled' => StaffGate::enabled(), 'groups' => StaffGate::overview(), 'code_min' => StaffGate::CODE_MIN, 'code_max' => StaffGate::CODE_MAX];
    }

    /** The acting admin must type their own password again before a staff code changes. */
    private function passwordOk(Request $request): bool
    {
        $me = User::find($this->adminId($request));
        $pw = (string) $request->input('password', '');

        return $me && $pw !== '' && password_verify($pw, (string) $me->password_hash);
    }

    /** GET /api/v1/admin/platform-policy/staff-gate — which groups use a dashboard code (the codes themselves are never readable). */
    public function staffGate(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        return $this->apiSuccess($this->gatePayload(), 'Staff access codes retrieved successfully.');
    }

    /** PUT /api/v1/admin/platform-policy/staff-gate/{group} — { code, password } */
    public function setStaffGateCode(Request $request, string $group)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }
        if (!$this->passwordOk($request)) {
            return $this->apiError('Your password is incorrect.', ['password' => 'invalid'], 422);
        }

        $error = StaffGate::setCode($group, trim((string) $request->input('code', '')), $this->adminId($request));
        if ($error === 'invalid_group') {
            return $this->apiError('Unknown group.', null, 404);
        }
        if ($error === 'invalid_code') {
            return $this->apiError('The code must be ' . StaffGate::CODE_MIN . '–' . StaffGate::CODE_MAX . ' letters or digits.', ['code' => 'invalid'], 422);
        }
        if ($error === 'code_in_use') {
            return $this->apiError('That code is already used by another group. Pick a different one.', ['code' => 'in_use'], 422);
        }

        // Never log the code itself.
        $this->auditLog->record($this->adminId($request), 'platform.staff_gate_code_change', 'Setting', null, null, ['group' => $group], $request->ip());

        return $this->apiSuccess($this->gatePayload(), 'Access code saved. Open sessions of that group must enter the new code.');
    }

    /** DELETE /api/v1/admin/platform-policy/staff-gate/{group} — { password }: back to the server (.env) code */
    public function resetStaffGateCode(Request $request, string $group)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }
        if (!$this->passwordOk($request)) {
            return $this->apiError('Your password is incorrect.', ['password' => 'invalid'], 422);
        }
        if (!isset(config('staff_gate.groups', [])[$group])) {
            return $this->apiError('Unknown group.', null, 404);
        }
        if (!StaffGate::resetCode($group, $this->adminId($request))) {
            return $this->apiError('This group already uses the server code.', null, 422);
        }

        $this->auditLog->record($this->adminId($request), 'platform.staff_gate_code_reset', 'Setting', null, null, ['group' => $group], $request->ip());

        return $this->apiSuccess($this->gatePayload(), 'Access code reset to the server default.');
    }

    /**
     * GET /api/v1/app-config — hands the official Android app its API token at launch, so the APK does not
     * need it baked in at build time (VITE_APP_TOKEN). Only answers requests marked X-UIP-Client: android.
     * Trade-off: anyone who sends that header can read it — same exposure as unpacking an APK, and it still
     * only blocks builds that never call this (old / unofficial ones); revoke the token in /admin/mobile to rotate.
     */
    public function appConfig(Request $request)
    {
        $token = $request->headers->has('X-UIP-Client') ? PlatformPolicy::appToken() : '';

        return $this->apiSuccess(['app_token' => $token], 'OK')->header('Cache-Control', 'no-store');
    }

    /** GET /api/v1/platform/status — public, tiny, no secrets: lets the SPA hide the sign-up form and know the channel mode. */
    public function publicStatus()
    {
        return $this->apiSuccess([
            'registration_enabled' => PlatformPolicy::registrationOpen(),
            'response_encryption'  => PlatformPolicy::encryptionOn(),
            'animations_enabled'   => PlatformPolicy::flag('animations_enabled'),
            'app_download_url'     => (string) PlatformPolicy::value('app_download_url'),
            'app_download_version' => (string) PlatformPolicy::value('app_download_version'),
        ], 'OK')->header('Cache-Control', 'no-store');
    }
}
