<?php

namespace App\Services\Tenancy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Embed SDK (الخطوة 2):
 *  - النطاقات المسموحة لكل tenant (بتتحول لـ CSP frame-ancestors) — محدش يضمّن الواجهة في موقع تاني.
 *  - جلسات الـ embed: سيرفر العميل بمفتاحه بيطلب توكن دخول قصير العمر يُستخدم مرة واحدة (ejt_…) لمشارك،
 *    والمتصفح (جوه الـ iframe) بيبدّله بتوكن جلسة (ems_…) بـ POST. الاتنين بيتخزنوا hash بس.
 */
final class TenantEmbedService
{
    public const JOIN_PREFIX = 'ejt_';
    public const ACCESS_PREFIX = 'ems_';

    public function __construct(private TenantParticipantService $participants)
    {
    }

    private function fail(string $msg, int $status = 422, string $code = 'invalid_request'): never
    {
        throw new EmbedException($msg, $status, $code);
    }

    /* ------------------------------------------------------------------ origins */

    /** يطبّع origin: scheme://host[:port] أو https://*.host. يرمي 422 لو شكله غلط. */
    public static function normalizeOrigin(string $raw): string
    {
        $o = strtolower(trim($raw));
        $o = rtrim($o, '/');
        if ($o === '' || strlen($o) > 190
            || !preg_match('~^(https?)://(\*\.)?((?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*)(?::(\d{1,5}))?$~', $o, $m)) {
            throw new EmbedException('Origin must look like https://app.example.com (no path) or https://*.example.com.', 422, 'invalid_origin');
        }
        [, $scheme, $wild, $host] = $m;
        $port = $m[4] ?? '';
        if ($port !== '' && ((int) $port < 1 || (int) $port > 65535)) {
            throw new EmbedException('Invalid port.', 422, 'invalid_origin');
        }
        $local = in_array($host, ['localhost', '127.0.0.1'], true);
        if ($scheme === 'http' && (!$local || $wild !== '')) {
            throw new EmbedException('Only https origins are allowed (http is accepted for localhost and 127.0.0.1 during development).', 422, 'invalid_origin');
        }
        if ($wild !== '' && substr_count($host, '.') < 1) {
            throw new EmbedException('A wildcard origin needs a real domain, e.g. https://*.example.com.', 422, 'invalid_origin');
        }
        if ($wild === '' && !$local && substr_count($host, '.') < 1) {
            throw new EmbedException('Origin host must be a full domain name.', 422, 'invalid_origin');
        }
        if (($scheme === 'https' && $port === '443') || ($scheme === 'http' && $port === '80')) {
            $port = '';
        }
        return $scheme . '://' . $wild . $host . ($port !== '' ? ':' . $port : '');
    }

    /** @param string[] $patterns origins مسموحة (ممكن wildcard) */
    public static function originMatches(array $patterns, string $origin): bool
    {
        try {
            $o = self::normalizeOrigin($origin);
        } catch (EmbedException) {
            return false;
        }
        if (str_contains($o, '*')) {
            return false;                                  // الـ origin الفعلي مفيهوش wildcard
        }
        foreach ($patterns as $p) {
            if ($p === $o) {
                return true;
            }
            if (str_contains($p, '*.')) {
                [$ps, $rest] = explode('://', $p, 2);
                [$os, $orest] = explode('://', $o, 2);
                $suffix = substr($rest, 1);                // ".example.com[:port]"
                if ($ps === $os && strlen($orest) > strlen($suffix) && str_ends_with($orest, $suffix)) {
                    return true;
                }
            }
        }
        return false;
    }

    public function listOrigins(int $tenantId): array
    {
        return DB::table('tenant_embed_origins')->where('tenant_id', $tenantId)->orderBy('id')->get(['id', 'origin', 'created_at'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'origin' => $r->origin, 'created_at' => $r->created_at])->all();
    }

    public function addOrigin(int $tenantId, string $raw, int $limit): array
    {
        $o = self::normalizeOrigin($raw);
        if (DB::table('tenant_embed_origins')->where('tenant_id', $tenantId)->where('origin', $o)->exists()) {
            throw new EmbedException('This origin is already allowed.', 409, 'origin_exists');
        }
        if (DB::table('tenant_embed_origins')->where('tenant_id', $tenantId)->count() >= $limit) {
            throw new EmbedException('This workspace reached its allowed-origins limit.', 403, 'limit_reached');
        }
        $id = DB::table('tenant_embed_origins')->insertGetId(['tenant_id' => $tenantId, 'origin' => $o, 'created_at' => now(), 'updated_at' => now()]);
        return ['id' => (int) $id, 'origin' => $o];
    }

    public function removeOrigin(int $tenantId, int $id): void
    {
        if (!DB::table('tenant_embed_origins')->where('tenant_id', $tenantId)->where('id', $id)->delete()) {
            throw new EmbedException('Origin not found.', 404, 'not_found');
        }
    }

    /** كل ما يتسمح له يضمّن: النطاقات المضافة + دومينات الـ tenant النشطة نفسه. */
    public function allowedOrigins(int $tenantId): array
    {
        $list = DB::table('tenant_embed_origins')->where('tenant_id', $tenantId)->pluck('origin')->all();
        foreach (DB::table('tenant_domains')->where('tenant_id', $tenantId)->where('status', 'active')->pluck('domain') as $d) {
            $list[] = 'https://' . $d;
        }
        return array_values(array_unique($list));
    }

    /** قيمة CSP frame-ancestors (أو 'none' لو مفيش نطاقات). */
    public function frameAncestors(int $tenantId): string
    {
        $list = $this->allowedOrigins($tenantId);
        return $list ? implode(' ', $list) : "'none'";
    }

    /* ------------------------------------------------------------------ sessions */

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private static function newToken(string $prefix): string
    {
        return $prefix . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** أساس رابط الـ iframe: الدومين المخصص النشط الأساسي لو موجود (white label) وإلا APP_URL. */
    public function baseUrl(int $tenantId): string
    {
        $d = DB::table('tenant_domains')->where('tenant_id', $tenantId)->where('status', 'active')->orderByDesc('is_primary')->orderBy('id')->value('domain');
        return $d ? 'https://' . $d : rtrim((string) config('app.url'), '/');
    }

    /**
     * سيرفر العميل بيطلب توكن دخول لمشارك. بيعمل upsert للمشارك في نفس الطلب (name مطلوب لو جديد).
     * @return array{token:string,expires_at:string,embed_url:string,participant:array,room:?string,origin:?string}
     */
    public function createSession(object $tenant, array $in, int $participantLimit): array
    {
        if ($tenant->status !== 'active') {
            $this->fail('This workspace is not available.', 403, 'tenant_suspended');
        }
        $room = isset($in['room']) && $in['room'] !== '' ? (string) $in['room'] : null;
        if ($room !== null && !preg_match('/^[A-Za-z0-9._:\-]{1,128}$/', $room)) {
            $this->fail('room must be 1 to 128 characters: letters, digits and . _ : -');
        }
        $origin = null;
        if (isset($in['origin']) && $in['origin'] !== '') {
            $origin = self::normalizeOrigin((string) $in['origin']);
            if (str_contains($origin, '*') || !self::originMatches($this->allowedOrigins((int) $tenant->id), $origin)) {
                $this->fail('This origin is not in the workspace allowed origins.', 403, 'origin_not_allowed');
            }
        }
        $max = (int) config('tenancy.embed.join_ttl_max', 900);
        $ttl = (int) ($in['ttl'] ?? config('tenancy.embed.join_ttl', 120));
        $ttl = max(15, min($max, $ttl));

        try {
            $p = $this->participants->upsert((int) $tenant->id, array_intersect_key($in, array_flip(['external_id', 'name', 'email', 'role', 'meta'])), $participantLimit);
        } catch (\DomainException $e) {
            if ($e instanceof EmbedException) {
                throw $e;
            }
            throw new EmbedException($e->getMessage(), $e->getCode() ?: 422, $e->getCode() === 403 ? 'limit_reached' : ($e->getCode() === 404 ? 'not_found' : 'invalid_request'));
        }

        if (random_int(1, 50) === 1) {
            $cut = now()->subDay();
            DB::table('tenant_embed_sessions')->where('expires_at', '<', $cut)->where(fn ($q) => $q->whereNull('access_expires_at')->orWhere('access_expires_at', '<', $cut))->delete();
        }

        $token = self::newToken(self::JOIN_PREFIX);
        $exp = now()->addSeconds($ttl);
        DB::table('tenant_embed_sessions')->insert([
            'tenant_id' => $tenant->id, 'participant_id' => $p['row']->id, 'room' => $room, 'origin' => $origin, 'token_hash' => self::hash($token),
            'expires_at' => $exp, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return [
            'token' => $token, 'expires_at' => $exp->toIso8601String(), 'embed_url' => $this->baseUrl((int) $tenant->id) . '/api/dev/v1/embed/frame?token=' . $token,
            'participant' => $p['participant'], 'room' => $room, 'origin' => $origin,
        ];
    }

    /** يدوّر على الجلسة بتوكن الدخول من غير ما يستهلكه (لصفحة الـ iframe). @return array{s:object,t:object,p:object}|null */
    public function inspect(string $token): ?array
    {
        if (!str_starts_with($token, self::JOIN_PREFIX) || strlen($token) > 100) {
            return null;
        }
        $s = DB::table('tenant_embed_sessions')->where('token_hash', self::hash($token))->first();
        if (!$s) {
            return null;
        }
        $t = DB::table('tenants')->where('id', $s->tenant_id)->first();
        $p = DB::table('tenant_participants')->where('id', $s->participant_id)->first();
        return $t && $p ? ['s' => $s, 't' => $t, 'p' => $p] : null;
    }

    /** حالة التوكن للعرض: ok | used | expired | tenant_suspended. */
    public function joinState(array $x): string
    {
        if ($x['t']->status !== 'active') {
            return 'tenant_suspended';
        }
        if ($x['s']->revoked_at !== null || $x['s']->used_at !== null) {
            return 'used';
        }
        return strtotime((string) $x['s']->expires_at) < time() ? 'expired' : 'ok';
    }

    /** يتأكد إن الـ parent مسموح للـ tenant (ولتوكن اتحدد له origin). يرمي 403. */
    public function assertParent(array $x, ?string $parent): ?string
    {
        if ($parent === null || $parent === '') {
            if ($x['s']->origin !== null) {
                $this->fail('This link may only be opened inside the embedding site.', 403, 'origin_mismatch');
            }
            return null;                                   // فتح مباشر (top-level)
        }
        $o = self::normalizeOrigin($parent);
        if (str_contains($o, '*') || !self::originMatches($this->allowedOrigins((int) $x['t']->id), $o)) {
            $this->fail('This site is not allowed to embed the meeting.', 403, 'origin_not_allowed');
        }
        if ($x['s']->origin !== null && $x['s']->origin !== $o) {
            $this->fail('This link was issued for a different site.', 403, 'origin_mismatch');
        }
        return $o;
    }

    /**
     * يبدّل توكن الدخول (مرة واحدة) بتوكن جلسة. الاستهلاك ذرّي: UPDATE … WHERE used_at IS NULL.
     * @return array{session:array,participant:array,room:?string,branding:array}
     */
    public function exchange(string $token, ?string $parent): array
    {
        $x = $this->inspect($token);
        if (!$x) {
            $this->fail('This join link is not valid.', 404, 'invalid_token');
        }
        $state = $this->joinState($x);
        if ($state === 'tenant_suspended') {
            $this->fail('This workspace is not available.', 403, 'tenant_suspended');
        }
        if ($state === 'used') {
            $this->fail('This join link was already used. Ask for a new one.', 409, 'token_used');
        }
        if ($state === 'expired') {
            $this->fail('This join link expired. Ask for a new one.', 410, 'token_expired');
        }
        $bound = $this->assertParent($x, $parent);

        $access = self::newToken(self::ACCESS_PREFIX);
        $exp = now()->addSeconds((int) config('tenancy.embed.session_ttl', 7200));
        $won = DB::table('tenant_embed_sessions')->where('id', $x['s']->id)->whereNull('used_at')->whereNull('revoked_at')->where('expires_at', '>', now())
            ->update(['used_at' => now(), 'bound_origin' => $bound, 'access_hash' => self::hash($access), 'access_expires_at' => $exp, 'updated_at' => now()]);
        if ($won !== 1) {
            $this->fail('This join link was already used. Ask for a new one.', 409, 'token_used');
        }
        DB::table('tenant_participants')->where('id', $x['p']->id)->update(['last_seen_at' => now()]);

        return [
            'session' => ['token' => $access, 'expires_at' => $exp->toIso8601String()],
            'participant' => TenantParticipantService::payload($x['p']), 'room' => $x['s']->room,
            'branding' => TenantBranding::publicPayload($x['t']),
        ];
    }

    /** يقرا جلسة من توكن الجلسة (ems_…). null لو مش صالحة/منتهية/اتلغت أو الـ tenant موقوف. */
    public function resolveAccess(string $access): ?array
    {
        if (!str_starts_with($access, self::ACCESS_PREFIX) || strlen($access) > 100) {
            return null;
        }
        $s = DB::table('tenant_embed_sessions')->where('access_hash', self::hash($access))->whereNull('revoked_at')->where('access_expires_at', '>', now())->first();
        if (!$s) {
            return null;
        }
        $t = DB::table('tenants')->where('id', $s->tenant_id)->where('status', 'active')->first();
        $p = DB::table('tenant_participants')->where('id', $s->participant_id)->first();
        if (!$t || !$p) {
            return null;
        }
        return [
            'tenant' => $t->public_id, 'participant' => TenantParticipantService::payload($p), 'room' => $s->room, 'origin' => $s->bound_origin,
            'expires_at' => date('c', strtotime((string) $s->access_expires_at)), 'session_id' => (int) $s->id,
        ];
    }

    public function revokeAccess(string $access): bool
    {
        return DB::table('tenant_embed_sessions')->where('access_hash', self::hash($access))->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]) === 1;
    }

    /** يلغي كل جلسات tenant (لما يتوقف) أو مشارك. */
    public function revokeForParticipant(int $participantId): void
    {
        DB::table('tenant_embed_sessions')->where('participant_id', $participantId)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
    }
}
