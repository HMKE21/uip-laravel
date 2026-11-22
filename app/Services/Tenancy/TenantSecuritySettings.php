<?php

namespace App\Services\Tenancy;

use Illuminate\Support\Facades\Cache;

/**
 * إعدادات "الدخول والأمان" لكل مساحة (للمالك والمدير): قيود دومين الإيميل، IP allowlist، مدة الجلسة، سياسة الباسورد،
 * Magic link / Passkeys / SSO (OIDC)، وسيندر إيميلات المنصة. التخزين في TenantAuthSettings، والفرض في TenantSecurity وأماكن الدخول.
 * المساحة المشتركة (حسابات UIPE): دومين الإيميل + IP allowlist بس. باقي السياسات للمنصات المعزولة (ليها حسابات ودخول خاص بيها).
 */
final class TenantSecuritySettings
{
    private const ISOLATED_ONLY = ['session_hours', 'password', 'magic_link', 'passkeys', 'oidc', 'saml', 'mail'];

    public function __construct(private TenantService $tenants)
    {
    }

    private function fail(string $msg, int $code = 422): never
    {
        throw new \DomainException($msg, $code);
    }

    private function passkeysReady(int $tenantId): bool
    {
        // سيرفر الـ passkeys متظبط (PASSKEY_ORIGINS) أو المنصة ليها دومين مخصص شغّال تتربط بيه.
        return trim((string) config('identity.passkeys.origins', '')) !== '' || TenantSecurity::activeDomains($tenantId) !== [];
    }

    private function payload(object $t): array
    {
        $tid = (int) $t->id;
        $isolated = ($t->mode ?? 'shared') === 'isolated';
        $base = TenantMailBrand::frontendUrl($tid);
        $slugBase = TenantAddress::urlFor($tid);
        $domains = TenantSecurity::activeDomains($tid);
        $redirects = array_values(array_unique(array_filter(array_merge(
            array_map(static fn ($d) => 'https://' . $d . '/sso/callback', $domains),
            [$slugBase ? $slugBase . '/sso/callback' : null]
        ))));
        $sec = TenantSecurity::forTenant($tid);
        return [
            'security' => $sec,
            'isolated' => $isolated,
            'request_ip' => request()->ip(),
            'redirect_uris' => $redirects,
            'redirect_uri' => $redirects[0] ?? ($base ? $base . '/sso/callback' : null),
            'active_domains' => $domains,
            'passkeys_ready' => $this->passkeysReady($tid),
            'sso' => ['protocols' => ['oidc', 'saml'], 'saml' => true],
            // بيانات الـ SP اللي المالك بيدّيها لمسؤول الـ IdP (المنصات المعزولة بس).
            'saml_sp' => $isolated ? [
                'entity_id' => TenantSaml::entityId($tid),
                'acs_url' => TenantSaml::acsUrl($tid),
                'metadata_url' => TenantSaml::entityId($tid),
                'nameid_format' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
            ] : null,
            'saml_certificates' => TenantSaml::certInfo((string) ($sec['saml']['certificate'] ?? '')),
        ];
    }

    public function get(int $userId, int $tenantId): array
    {
        [$t] = $this->tenants->authorizeRead($userId, $tenantId, 'site');
        return $this->payload($t);
    }

    public function save(int $userId, int $tenantId, array $in): array
    {
        [$t] = $this->tenants->authorize($userId, $tenantId, 'site');
        $tid = (int) $t->id;
        $isolated = ($t->mode ?? 'shared') === 'isolated';

        // نبدأ من المحفوظ ونطبّق بس المفاتيح اللي اتبعتت (PUT جزئي ميصفّرش الباقي).
        $cur = TenantSecurity::forTenant($tid);
        $next = $cur;
        foreach (['allowed_domains', 'session_hours', 'ip_allowlist', 'magic_link', 'passkeys'] as $k) {
            if (array_key_exists($k, $in)) {
                $next[$k] = $in[$k];
            }
        }
        foreach (['password', 'oidc', 'saml', 'mail'] as $k) {
            if (array_key_exists($k, $in) && is_array($in[$k])) {
                $next[$k] = array_merge((array) ($cur[$k] ?? []), $in[$k]);
            }
        }

        if (!$isolated) {
            $d = TenantAuthSettings::securityDefaults();
            foreach (self::ISOLATED_ONLY as $k) {
                $next[$k] = $d[$k];
            }
        }

        try {
            TenantAuthSettings::validateSecurity($next);
        } catch (\InvalidArgumentException $e) {
            $this->fail($e->getMessage());
        }

        if ($isolated) {
            $o = (array) ($next['oidc'] ?? []);
            if (!empty($o['enabled'])) {
                $same = rtrim((string) ($cur['oidc']['issuer'] ?? ''), '/') === rtrim((string) ($o['issuer'] ?? ''), '/') && !empty($cur['oidc']['enabled']);
                if (!$same && TenantOidc::discover((string) $o['issuer'], true) === null) {
                    $this->fail('We could not read the SSO provider settings from that issuer. Check the issuer URL (it must expose /.well-known/openid-configuration over https).');
                }
            }
            $m = (array) ($next['mail'] ?? []);
            if (!empty($m['from_enabled'])) {
                $addr = strtolower(trim((string) ($m['from_address'] ?? '')));
                $dom = substr($addr, (int) strrpos($addr, '@') + 1);
                if (!in_array($dom, TenantSecurity::activeDomains($tid), true)) {
                    $this->fail('The sender address must be on one of this platform\'s active domains. Add and activate the domain first (Domains tab).');
                }
            }
            if (!empty($next['passkeys']) && empty($cur['passkeys']) && !$this->passkeysReady($tid)) {
                $this->fail('Passkeys need an active custom domain for this platform (or server passkey settings). Activate a domain first.');
            }
        }

        try {
            TenantAuthSettings::saveSecurity($tid, $next);
        } catch (\InvalidArgumentException $e) {
            $this->fail($e->getMessage());
        }
        TenantMailBrand::flush();
        try {
            Cache::forget('ws:sec:' . $tid); // المساحة المشتركة بتقرا الإعدادات من كاش المنصة الأم (30 ثانية)
        } catch (\Throwable $e) {
            // مش حرج
        }
        $this->tenants->audit($userId, $tid, 'security');
        return $this->payload($t);
    }

    /**
     * استيراد إعدادات SAML من metadata الـ IdP (رابط https أو XML ملصوق) من غير ما يحفظ حاجة:
     * بيرجّع entity_id و sso_url و certificate عشان الفرونت يملا بيهم الخانات.
     */
    public function testSaml(int $userId, int $tenantId, ?string $url, ?string $xml): array
    {
        $this->tenants->authorize($userId, $tenantId, 'site');
        $url = trim((string) $url);
        $xml = trim((string) $xml);
        if ($url === '' && $xml === '') {
            $this->fail('Provide the metadata URL or paste the metadata XML.');
        }
        if ($xml === '') {
            $xml = (string) TenantSaml::fetchMetadata($url);
            if ($xml === '') {
                $this->fail('We could not download the metadata from that address. It must be a public https:// URL that returns the SAML metadata XML.');
            }
        }
        $m = TenantSaml::parseIdpMetadata($xml);
        if ($m === null) {
            $this->fail('We could not read a SAML identity provider (entity ID, HTTP-Redirect sign-in URL and RSA signing certificate) from that metadata.');
        }
        return ['ok' => true] + $m + ['certificates' => TenantSaml::certInfo($m['certificate'])];
    }

    /** اختبار اتصال SSO: بيقرا الـ discovery من الـ issuer ويرجّع النتيجة من غير ما يحفظ حاجة. */
    public function testOidc(int $userId, int $tenantId, string $issuer): array
    {
        $this->tenants->authorize($userId, $tenantId, 'site');
        $issuer = rtrim(trim($issuer), '/');
        if (!TenantOidc::issuerSafe($issuer, false)) {
            $this->fail('The SSO issuer must be a public https:// address.');
        }
        $d = TenantOidc::discover($issuer, true);
        if ($d === null) {
            $this->fail('We could not read the SSO provider settings from that issuer.');
        }
        return ['ok' => true, 'authorization_endpoint' => $d['authorization_endpoint'], 'supports_code' => $d['token_endpoint'] !== null, 'response_types' => $d['response_types_supported']];
    }
}
