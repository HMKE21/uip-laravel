<?php

namespace App\Support;

use App\Repositories\SettingRepository;
use App\Services\UipJwtService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Server-side access-code gate for the hidden staff sign-in (see config/staff_gate.php).
 * The code is checked here only; the browser just holds a short-lived signed ticket.
 */
final class StaffGate
{
    private const TYP = 'staff_gate';

    public static function enabled(): bool
    {
        return (bool) config('staff_gate.enabled', true);
    }

    /** @return array<string,string> code => group (invalid pairs and unknown groups ignored) */
    private static function codes(): array
    {
        $groups = (array) config('staff_gate.groups', []);
        $out = [];
        foreach (explode(',', (string) config('staff_gate.codes', '')) as $pair) {
            [$code, $group] = array_pad(explode(':', trim($pair), 2), 2, '');
            $code = trim($code);
            $group = trim($group);
            if ($code !== '' && isset($groups[$group])) {
                $out[$code] = $group;
            }
        }

        return $out;
    }

    private const KEY = 'policy_staff_gate_';
    public const CODE_MIN = 4;
    public const CODE_MAX = 32;

    /**
     * Codes the admin set from the dashboard (Admin → Platform Policy), stored only as bcrypt hashes.
     * A group with a dashboard code ignores the .env codes of that group.
     * @return array<string,array{hash:string,ver:int,at:?string,by:?int}>
     */
    public static function stored(): array
    {
        $out = [];
        try {
            $rows = DB::table('settings')->where('scope', 'global')->whereNull('user_id')
                ->where('key', 'like', self::KEY . '%')->get(['key', 'value']);
            foreach ($rows as $r) {
                $group = substr((string) $r->key, strlen(self::KEY));
                $v = json_decode((string) $r->value, true);
                if (isset(config('staff_gate.groups', [])[$group]) && is_array($v)) {
                    $out[$group] = ['hash' => (string) ($v['hash'] ?? ''), 'ver' => (int) ($v['ver'] ?? 1), 'at' => $v['at'] ?? null, 'by' => isset($v['by']) ? (int) $v['by'] : null];
                }
            }
        } catch (\Throwable $e) {
            // DB unreachable: fall back to the .env codes rather than locking staff out
        }

        return $out;
    }

    /** Version of a group's code; a ticket issued before the code was changed stops working. */
    private static function version(string $group, array $stored): int
    {
        return $stored[$group]['ver'] ?? 0;
    }

    /** Group of the code, or null. Checks every group so timing does not reveal a near-miss. */
    public static function groupFor(string $code): ?string
    {
        $stored = self::stored();
        $found = null;
        foreach (array_keys((array) config('staff_gate.groups', [])) as $group) {
            if (!empty($stored[$group]['hash']) && password_verify($code, $stored[$group]['hash'])) {
                $found = $group;
            }
        }
        foreach (self::codes() as $known => $group) {
            if (empty($stored[$group]['hash']) && hash_equals((string) $known, $code)) {
                $found = $group;
            }
        }

        return $found;
    }

    /** @return array<int,array{group:string,roles:array,source:string,changed_at:?string,changed_by:?int}> */
    public static function overview(): array
    {
        $stored = self::stored();
        $envGroups = array_flip(array_values(self::codes()));
        $out = [];
        foreach ((array) config('staff_gate.groups', []) as $group => $roles) {
            $out[] = [
                'group'      => $group,
                'roles'      => array_values($roles),
                'source'     => !empty($stored[$group]['hash']) ? 'dashboard' : (isset($envGroups[$group]) ? 'server' : 'none'),
                'changed_at' => !empty($stored[$group]['hash']) ? ($stored[$group]['at'] ?? null) : null,
                'changed_by' => !empty($stored[$group]['hash']) ? ($stored[$group]['by'] ?? null) : null,
            ];
        }

        return $out;
    }

    /** @return string|null error code: invalid_group | invalid_code | code_in_use */
    public static function setCode(string $group, string $code, ?int $adminId): ?string
    {
        if (!isset(config('staff_gate.groups', [])[$group])) {
            return 'invalid_group';
        }
        if (strlen($code) < self::CODE_MIN || strlen($code) > self::CODE_MAX || !preg_match('/^[A-Za-z0-9]+$/', $code)) {
            return 'invalid_code';
        }
        $owner = self::groupFor($code);
        if ($owner !== null && $owner !== $group) {
            return 'code_in_use'; // one code must open exactly one kind of account
        }
        $ver = self::version($group, self::stored()) + 1;
        app(SettingRepository::class)->set(self::KEY . $group, json_encode([
            'hash' => password_hash($code, PASSWORD_BCRYPT), 'ver' => $ver, 'at' => gmdate('c'), 'by' => $adminId,
        ]), 'global', null);

        return null;
    }

    /** Back to the .env code(s) of the group. Old tickets stop working. */
    public static function resetCode(string $group, ?int $adminId): bool
    {
        $stored = self::stored();
        if (empty($stored[$group]['hash'])) {
            return false;
        }
        // keep the row with an empty hash and a higher version, so tickets issued before the reset stay dead
        app(SettingRepository::class)->set(self::KEY . $group, json_encode([
            'hash' => '', 'ver' => $stored[$group]['ver'] + 1, 'at' => gmdate('c'), 'by' => $adminId,
        ]), 'global', null);

        return true;
    }

    // ───────── brute-force protection ─────────

    /** Seconds the caller must still wait (0 = free to try). */
    public static function retryAfter(string $ip): int
    {
        $until = (int) Cache::get('staff_gate:lock:' . sha1($ip), 0);
        $wait = max(0, $until - time());
        if ($wait > 0) {
            return $wait;
        }
        $globalUntil = (int) Cache::get('staff_gate:global_lock', 0);

        return max(0, $globalUntil - time());
    }

    /** Records a wrong code; returns attempts left before this IP is locked (0 when it just got locked). */
    public static function registerFailure(string $ip): int
    {
        $max = max(1, (int) config('staff_gate.max_attempts', 5));
        $key = 'staff_gate:fail:' . sha1($ip);
        $n = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $n, 3600);

        if ($n >= $max) {
            $strikesKey = 'staff_gate:strikes:' . sha1($ip);
            $strikes = (int) Cache::get($strikesKey, 0) + 1;
            Cache::put($strikesKey, $strikes, 86400);
            $minutes = min(1440, max(1, (int) config('staff_gate.lock_minutes', 15)) * (2 ** ($strikes - 1)));
            Cache::put('staff_gate:lock:' . sha1($ip), time() + $minutes * 60, $minutes * 60);
            Cache::forget($key);
        }

        // Distributed guessing: too many wrong codes overall in a short window -> everybody waits a bit.
        $gKey = 'staff_gate:global_fail';
        $g = (int) Cache::get($gKey, 0) + 1;
        Cache::put($gKey, $g, 600);
        if ($g >= max(10, (int) config('staff_gate.global_limit', 100))) {
            Cache::put('staff_gate:global_lock', time() + 600, 600);
            Cache::forget($gKey);
        }

        return max(0, $max - $n);
    }

    public static function clearFailures(string $ip): void
    {
        Cache::forget('staff_gate:fail:' . sha1($ip));
    }

    // ───────── ticket ─────────

    public static function issue(string $group): string
    {
        return UipJwtService::encode(['typ' => self::TYP, 'grp' => $group, 'v' => self::version($group, self::stored())], (int) config('staff_gate.ttl', 1800));
    }

    /** @return string[]|null staff roles the ticket opens, null when the ticket is missing / forged / expired */
    public static function rolesFromTicket($ticket): ?array
    {
        if (!is_string($ticket) || $ticket === '' || strlen($ticket) > 4096) {
            return null;
        }
        $claims = UipJwtService::decode($ticket);
        if (!$claims || ($claims['typ'] ?? null) !== self::TYP || !isset($claims['grp'])) {
            return null;
        }
        // The code of that group was changed or reset after this ticket was issued: it no longer counts.
        if ((int) ($claims['v'] ?? 0) !== self::version((string) $claims['grp'], self::stored())) {
            return null;
        }
        $roles = config('staff_gate.groups.' . $claims['grp']);

        return is_array($roles) ? array_values($roles) : null;
    }
}
