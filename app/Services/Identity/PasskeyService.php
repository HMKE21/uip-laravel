<?php

namespace App\Services\Identity;

use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantSecurity;
use App\Support\WebAuthn\WebAuthn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * UIPE Mail — المرحلة 5: Passkeys (WebAuthn). الإعداد من config/identity.php (PASSKEY_ORIGINS / PASSKEY_RP_ID).
 * بيرمي \DomainException(message, httpCode). الـ challenges في الكاش 5 دقايق ومرة واحدة (Cache::pull).
 */
class PasskeyService
{
    private const TTL = 300;

    private function fail(string $m, int $code = 422): never
    {
        throw new \DomainException($m, $code);
    }

    /** @return string[] origins المسموحة (جوه منصة معزولة: origins السيرفر + دومينات المنصة الشغّالة). */
    public function origins(): array
    {
        $o = array_values(array_filter(array_map(fn ($o) => rtrim(trim($o), '/'), explode(',', (string) config('identity.passkeys.origins', '')))));
        if (TenantContext::active() && TenantContext::id()) {
            foreach (TenantSecurity::activeDomains((int) TenantContext::id()) as $d) {
                $o[] = 'https://' . $d;
            }
        }
        return array_values(array_unique($o));
    }

    /** جوه منصة معزولة وطلب جاي من دومينها المخصص: الـ passkey بتتربط بالدومين ده (rp id = الدومين). */
    private function tenantRpId(): ?string
    {
        if (!TenantContext::active() || !TenantContext::id()) {
            return null;
        }
        $host = strtolower(trim((string) request()->headers->get('X-Tenant-Domain', '')));
        if ($host !== '' && in_array($host, TenantSecurity::activeDomains((int) TenantContext::id()), true)) {
            return $host;
        }
        return null;
    }

    public function rpId(): string
    {
        if (($t = $this->tenantRpId()) !== null) {
            return $t;
        }
        $id = trim((string) config('identity.passkeys.rp_id', ''));
        if ($id !== '') {
            return strtolower($id);
        }
        $first = $this->origins()[0] ?? '';
        return strtolower((string) (parse_url($first, PHP_URL_HOST) ?: ''));
    }

    public function enabled(): bool
    {
        // منصة معزولة: Passkeys لازم المالك يفعّلها من "الدخول والأمان".
        if (TenantContext::active()) {
            $s = TenantSecurity::current();
            if (empty($s['passkeys'])) {
                return false;
            }
        }
        return $this->origins() !== [] && $this->rpId() !== '';
    }

    private function requireEnabled(): void
    {
        if (!$this->enabled()) {
            $this->fail('Passkeys are not configured on this server.', 503);
        }
    }

    /** معرّف المستخدم عند الـ authenticator: 8 بايت من الـ id (مفيش بيانات شخصية). */
    public function userHandle(int $userId): string
    {
        return pack('J', $userId);
    }

    private function challenge(): string
    {
        return WebAuthn::b64uEncode(random_bytes(32));
    }

    private function iso($v): ?string
    {
        return $v ? Carbon::parse($v, 'UTC')->toIso8601String() : null;
    }

    public function row(object $p): array
    {
        return ['id' => (int) $p->id, 'name' => $p->name, 'backed_up' => (bool) $p->backed_up, 'created_at' => $this->iso($p->created_at), 'last_used_at' => $this->iso($p->last_used_at)];
    }

    public function list(int $userId): array
    {
        return DB::table('passkeys')->where('user_id', $userId)->whereNull('revoked_at')->orderByDesc('id')->get()->map(fn ($p) => $this->row($p))->all();
    }

    /* ------------------------------------------------------------------ تسجيل */

    public function registrationOptions(int $userId): array
    {
        $this->requireEnabled();
        $u = DB::table('users')->where('id', $userId)->first();
        if (!$u) {
            $this->fail('User not found.', 404);
        }
        $active = DB::table('passkeys')->where('user_id', $userId)->whereNull('revoked_at')->pluck('credential_id')->all();
        if (count($active) >= max(1, (int) config('identity.passkeys.max_per_user', 10))) {
            $this->fail('You reached the maximum number of passkeys. Remove one first.', 409);
        }
        $ch = $this->challenge();
        Cache::put('passkey:reg:' . $userId, $ch, self::TTL);
        return ['publicKey' => [
            'challenge' => $ch,
            'rp' => ['id' => $this->rpId(), 'name' => (string) ((TenantContext::active() ? \App\Services\Tenancy\TenantMailBrand::current()['name'] ?? null : null) ?: config('identity.passkeys.rp_name', 'UIPE'))],
            'user' => ['id' => WebAuthn::b64uEncode($this->userHandle($userId)), 'name' => (string) ($u->email ?? ('user' . $userId)), 'displayName' => (string) ($u->full_name ?? $u->email ?? 'User')],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => WebAuthn::ALG_ES256], ['type' => 'public-key', 'alg' => WebAuthn::ALG_RS256]],
            'timeout' => self::TTL * 1000,
            'attestation' => 'none',
            'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
            'excludeCredentials' => array_map(fn ($id) => ['type' => 'public-key', 'id' => $id], $active),
        ]];
    }

    /** @param array<string,mixed> $cred JSON الفرونت (كله base64url) */
    public function finishRegistration(int $userId, array $cred, ?string $name): array
    {
        $this->requireEnabled();
        $ch = Cache::pull('passkey:reg:' . $userId);
        if (!$ch) {
            $this->fail('This passkey request expired. Try again.', 410);
        }
        $resp = is_array($cred['response'] ?? null) ? $cred['response'] : [];
        try {
            $res = WebAuthn::verifyRegistration(
                WebAuthn::b64uDecode((string) ($resp['clientDataJSON'] ?? '')),
                WebAuthn::b64uDecode((string) ($resp['attestationObject'] ?? '')),
                $ch, $this->origins(), $this->rpId()
            );
        } catch (\InvalidArgumentException) {
            $this->fail('The passkey could not be verified.', 422);
        }
        if (DB::table('passkeys')->where('credential_id', $res['credential_id'])->exists()) {
            $this->fail('This passkey is already registered.', 409);
        }
        $tr = is_array($resp['transports'] ?? null) ? implode(',', array_slice(array_filter($resp['transports'], 'is_string'), 0, 6)) : '';
        $id = DB::table('passkeys')->insertGetId(['user_id' => $userId, 'credential_id' => $res['credential_id'], 'public_key' => $res['public_key_pem'], 'alg' => $res['alg'],
            'sign_count' => $res['sign_count'], 'transports' => $tr !== '' ? mb_substr($tr, 0, 120) : null,
            'name' => mb_substr(trim((string) $name) ?: 'Passkey', 0, 80), 'backed_up' => $res['backed_up'] ? 1 : 0, 'created_at' => now()]);
        return $this->row(DB::table('passkeys')->where('id', $id)->first());
    }

    /* ------------------------------------------------------------------ دخول */

    public function loginOptions(): array
    {
        $this->requireEnabled();
        $cid = bin2hex(random_bytes(16));
        $ch = $this->challenge();
        Cache::put('passkey:login:' . $cid, $ch, self::TTL);
        // allowCredentials فاضية عمدًا (discoverable): مفيش تسريب لإيميلات مسجّلة.
        return ['ceremony_id' => $cid, 'publicKey' => ['challenge' => $ch, 'rpId' => $this->rpId(), 'timeout' => self::TTL * 1000, 'userVerification' => 'required', 'allowCredentials' => []]];
    }

    /** @param array<string,mixed> $cred @return int user id بعد التحقق الكامل (التوقيع + العدّاد) */
    public function finishLogin(string $ceremonyId, array $cred): int
    {
        $this->requireEnabled();
        $ch = preg_match('/^[a-f0-9]{32}$/', $ceremonyId) ? Cache::pull('passkey:login:' . $ceremonyId) : null;
        if (!$ch) {
            $this->fail('This sign-in request expired. Try again.', 410);
        }
        $resp = is_array($cred['response'] ?? null) ? $cred['response'] : [];
        $credId = (string) ($cred['id'] ?? '');
        $p = $credId !== '' && strlen($credId) <= 400 ? DB::table('passkeys')->where('credential_id', $credId)->whereNull('revoked_at')->first() : null;
        if (!$p) {
            $this->fail('Passkey sign-in failed.', 422);
        }
        try {
            $handle = (string) ($resp['userHandle'] ?? '');
            if ($handle !== '' && !hash_equals($this->userHandle((int) $p->user_id), WebAuthn::b64uDecode($handle))) {
                throw new \InvalidArgumentException('User handle mismatch');
            }
            $r = WebAuthn::verifyAssertion(
                WebAuthn::b64uDecode((string) ($resp['clientDataJSON'] ?? '')),
                WebAuthn::b64uDecode((string) ($resp['authenticatorData'] ?? '')),
                WebAuthn::b64uDecode((string) ($resp['signature'] ?? '')),
                $ch, $this->origins(), $this->rpId(), (string) $p->public_key, (int) $p->sign_count
            );
        } catch (\InvalidArgumentException) {
            $this->fail('Passkey sign-in failed.', 422);
        }
        DB::table('passkeys')->where('id', $p->id)->update(['sign_count' => $r['sign_count'], 'backed_up' => $r['backed_up'] ? 1 : 0, 'last_used_at' => now()]);
        return (int) $p->user_id;
    }

    /* ------------------------------------------------------------------ إدارة */

    public function rename(int $userId, int $id, string $name): array
    {
        $p = DB::table('passkeys')->where('id', $id)->where('user_id', $userId)->whereNull('revoked_at')->first();
        if (!$p) {
            $this->fail('Passkey not found.', 404);
        }
        $name = mb_substr(trim($name), 0, 80);
        if ($name === '') {
            $this->fail('Name is required.');
        }
        DB::table('passkeys')->where('id', $id)->update(['name' => $name]);
        return $this->row(DB::table('passkeys')->where('id', $id)->first());
    }

    public function revoke(int $userId, int $id): void
    {
        if (!DB::table('passkeys')->where('id', $id)->where('user_id', $userId)->whereNull('revoked_at')->update(['revoked_at' => now()])) {
            $this->fail('Passkey not found.', 404);
        }
    }
}
