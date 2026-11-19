<?php

namespace App\Services\Identity;

use App\Support\AuthCookies;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * UIPE Mail — المرحلة 5: سجل الدخول. بيتسجّل عند نقطة واحدة (LoginController / 2FA) ومبيكسرش الدخول أبدًا:
 * أي خطأ في التسجيل بيتبلع (بيتكتب في اللوج بس).
 */
class LoginHistoryService
{
    public const METHODS = ['password', 'google', 'microsoft', 'keycloak', 'github', '2fa', 'passkey'];

    /** الطريقة من مسار الريكوست (جوجل/مايكروسوفت/... بيعدّوا على نفس finishLogin). */
    public static function methodFromRequest(Request $r): string
    {
        $p = $r->path();
        foreach (['google', 'microsoft', 'keycloak', 'github', 'passkey'] as $m) {
            if (str_contains($p, "auth/{$m}")) {
                return $m;
            }
        }
        return str_contains($p, 'two-factor') ? '2fa' : 'password';
    }

    private function deviceHash(Request $r): ?string
    {
        try {
            $id = AuthCookies::deviceId($r, false);
        } catch (\Throwable) {
            $id = '';
        }
        $src = $id !== '' ? $id : (string) $r->userAgent();
        return $src !== '' ? hash('sha256', $src) : null;
    }

    private function deviceLabel(string $ua): ?string
    {
        if ($ua === '') {
            return null;
        }
        $browser = 'Browser';
        foreach (['Edg/' => 'Edge', 'OPR/' => 'Opera', 'Firefox/' => 'Firefox', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari', 'curl/' => 'curl'] as $n => $v) {
            if (stripos($ua, $n) !== false) {
                $browser = $v;
                break;
            }
        }
        $os = 'Unknown OS';
        foreach (['Windows' => 'Windows', 'Android' => 'Android', 'iPhone' => 'iOS', 'iPad' => 'iPadOS', 'Mac OS X' => 'macOS', 'Linux' => 'Linux'] as $n => $v) {
            if (stripos($ua, $n) !== false) {
                $os = $v;
                break;
            }
        }
        return "{$browser} on {$os}";
    }

    private function location(?string $ip): ?string
    {
        if (!$ip) {
            return null;
        }
        try {
            $r = app(\App\Services\GeoIpService::class)->resolve($ip);
        } catch (\Throwable) {
            return null;
        }
        if ($r['status'] === 'ok') {
            return $r['name'] ?: $r['code'];
        }
        return $r['status'] === 'local' ? 'Local network' : null;
    }

    public function record(Request $r, ?int $userId, bool $success, ?string $method = null, ?string $reason = null, ?string $identifier = null): void
    {
        try {
            $method = in_array($method, self::METHODS, true) ? $method : self::methodFromRequest($r);
            $ua = (string) $r->userAgent();
            $hash = $this->deviceHash($r);
            $isNew = false;
            if ($success && $userId && $hash) {
                $hadAny = DB::table('login_events')->where('user_id', $userId)->where('success', 1)->exists();
                $seen = $hadAny && DB::table('login_events')->where('user_id', $userId)->where('success', 1)->where('device_hash', $hash)->exists();
                $isNew = $hadAny && !$seen;   // أول دخول في حياة الحساب مش "جهاز جديد"
            }
            DB::table('login_events')->insert([
                'user_id'        => $userId,
                'identifier'     => $identifier !== null ? mb_substr(strtolower(trim($identifier)), 0, 190) : null,
                'success'        => $success ? 1 : 0,
                'method'         => $method,
                'failure_reason' => $success ? null : mb_substr((string) $reason, 0, 40),
                'ip_address'     => $r->ip(),
                'user_agent'     => $ua !== '' ? mb_substr($ua, 0, 255) : null,
                'device_label'   => $this->deviceLabel($ua),
                'device_hash'    => $hash,
                // فشل الدخول مبنعملوش GeoIP (عشان هجوم تخمين ميعملش طلبات خارجية كتير).
                'location_label' => $success ? $this->location($r->ip()) : null,
                'is_new_device'  => $isNew ? 1 : 0,
                'created_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Login event not recorded: ' . $e->getMessage());
        }
    }

    private static function iso($v): ?string
    {
        return $v ? Carbon::parse($v, 'UTC')->toIso8601String() : null;
    }

    private function row(object $e, bool $admin = false): array
    {
        $out = ['id' => (int) $e->id, 'success' => (bool) $e->success, 'method' => $e->method, 'failure_reason' => $e->failure_reason,
            'ip_address' => $e->ip_address, 'device_label' => $e->device_label, 'location_label' => $e->location_label,
            'is_new_device' => (bool) $e->is_new_device, 'created_at' => self::iso($e->created_at)];
        if ($admin) {
            $out['user_id'] = $e->user_id ? (int) $e->user_id : null;
            $out['identifier'] = $e->identifier;
            $out['user_agent'] = $e->user_agent;
        }
        return $out;
    }

    /** سجل المستخدم نفسه (الأحدث أولًا). */
    public function forUser(int $userId, int $page = 1, int $perPage = 20): array
    {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $q = DB::table('login_events')->where('user_id', $userId);
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('id')->forPage($page, $perPage)->get();
        $last = DB::table('login_events')->where('user_id', $userId)->where('success', 1)->orderByDesc('id')->first();
        return ['items' => $rows->map(fn ($e) => $this->row($e))->all(),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_success_at' => self::iso($last->created_at ?? null)]];
    }

    /**
     * لوحة الأدمن/الجامعة. $universityId != null → مقيّد بأعضاء الجامعة دي (الجامعة متشوفش غير ناسها).
     * @param array{user_id?:mixed,success?:mixed,q?:string,from?:string,to?:string,method?:string} $f
     */
    public function search(array $f, ?int $universityId, int $page = 1, int $perPage = 25): array
    {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $q = DB::table('login_events');
        if ($universityId !== null) {
            $q->where(function ($w) use ($universityId) {
                $w->whereIn('user_id', DB::table('students')->where('university_id', $universityId)->select('user_id'))
                    ->orWhereIn('user_id', DB::table('academic_staff')->where('university_id', $universityId)->select('user_id'))
                    ->orWhereIn('user_id', DB::table('faculties')->where('university_id', $universityId)->whereNotNull('user_id')->select('user_id'))
                    ->orWhereIn('user_id', DB::table('universities')->where('id', $universityId)->select('user_id'));
            });
        }
        if (!empty($f['user_id'])) {
            $q->where('user_id', (int) $f['user_id']);
        }
        if (isset($f['success']) && $f['success'] !== '' && $f['success'] !== null) {
            $q->where('success', filter_var($f['success'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
        }
        if (!empty($f['method']) && in_array($f['method'], self::METHODS, true)) {
            $q->where('method', $f['method']);
        }
        if (!empty($f['q'])) {
            $s = '%' . str_replace(['%', '_'], ['\%', '\_'], trim((string) $f['q'])) . '%';
            $q->where(fn ($w) => $w->where('identifier', 'like', $s)->orWhere('ip_address', 'like', $s));
        }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            if (!empty($f[$k]) && strtotime((string) $f[$k])) {
                $q->where('created_at', $op, Carbon::parse($f[$k])->toDateTimeString());
            }
        }
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('id')->forPage($page, $perPage)->get();
        return ['items' => $rows->map(fn ($e) => $this->row($e, true))->all(), 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total]];
    }

    public function prune(?int $days = null): int
    {
        $days = max(1, $days ?? (int) config('identity.login_events_days', 180));
        return DB::table('login_events')->where('created_at', '<', now()->subDays($days))->delete();
    }
}
