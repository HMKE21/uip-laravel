<?php

namespace App\Services\Tenancy;

use Illuminate\Support\Facades\Http;

/**
 * SSO بـ SAML 2.0 لكل منصة (Service Provider فقط، SP-initiated، Web Browser SSO).
 * مكتوب بـ PHP نفسه (DOM + OpenSSL) من غير أي مكتبة خارجية، وبقواعد صارمة عن قصد:
 *  - التوقيع إجباري (على الـ Response أو الـ Assertion) ومتحقق منه بشهادة الـ IdP المحفوظة عند المالك فقط — مبنثقش في KeyInfo اللي جوه الرسالة.
 *  - التوقيع لازم يكون "enveloped" على العنصر اللي هو ابنه المباشر والـ ID بتاعه فريد في الوثيقة (يقفل XML Signature Wrapping).
 *  - exclusive-c14n + RSA-SHA256/384/512 بس (مفيش SHA-1)، وReference واحدة، وTransforms معروفة بس.
 *  - مفيش DOCTYPE/ENTITY (XXE)، ومفيش EncryptedAssertion، وAssertion واحدة بالظبط.
 *  - Issuer / Audience / Recipient / Destination / InResponseTo / NotBefore / NotOnOrAfter كلها بتتفحص. الـ InResponseTo والـ Assertion ID
 *    (منع إعادة الاستخدام) بيتفحصوا في الكنترولر عن طريق الكاش.
 * التحقق نفسه (verify) نقي: مبيلمسش DB ولا كاش.
 */
final class TenantSaml
{
    public const NS_P = 'urn:oasis:names:tc:SAML:2.0:protocol';
    public const NS_A = 'urn:oasis:names:tc:SAML:2.0:assertion';
    public const NS_DS = 'http://www.w3.org/2000/09/xmldsig#';
    public const NS_MD = 'urn:oasis:names:tc:SAML:2.0:metadata';
    private const C14N_EXC = 'http://www.w3.org/2001/10/xml-exc-c14n#';
    private const ENVELOPED = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';
    private const SKEW = 90;
    private const MAX_BYTES = 600000;

    private const SIG_ALGS = [
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256' => OPENSSL_ALGO_SHA256,
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha384' => OPENSSL_ALGO_SHA384,
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha512' => OPENSSL_ALGO_SHA512,
    ];
    private const DIGEST_ALGS = [
        'http://www.w3.org/2001/04/xmlenc#sha256' => 'sha256',
        'http://www.w3.org/2001/04/xmldsig-more#sha384' => 'sha384',
        'http://www.w3.org/2001/04/xmlenc#sha512' => 'sha512',
    ];
    private const EMAIL_ATTRS = [
        'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress', 'email', 'emailaddress', 'mail',
        'urn:oid:0.9.2342.19200300.100.1.3', 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/upn', 'upn',
        'urn:oid:1.3.6.1.4.1.5923.1.1.1.6', 'http://schemas.microsoft.com/identity/claims/emailaddress',
    ];
    private const NAME_ATTRS = [
        'http://schemas.microsoft.com/identity/claims/displayname', 'displayname', 'name', 'cn', 'urn:oid:2.16.840.1.113730.3.1.241',
        'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name',
    ];
    private const GIVEN_ATTRS = ['http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname', 'givenname', 'firstname', 'first_name', 'urn:oid:2.5.4.42'];
    private const SUR_ATTRS = ['http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname', 'surname', 'lastname', 'last_name', 'urn:oid:2.5.4.4'];

    /** سبب آخر رفض: invalid | idp_denied | signature | time | audience | destination | no_email | encrypted */
    public ?string $lastError = null;
    /** تفاصيل للّوج بس (متتبعتش للمستخدم). */
    public ?string $lastDetail = null;

    /* ------------------------------------------------------------------ عناوين الـ SP */

    private static function base(): string
    {
        return rtrim((string) config('app.url'), '/') . '/api/auth/tenant-saml';
    }

    public static function entityId(int $tenantId): string
    {
        return self::base() . '/metadata/' . $tenantId;
    }

    public static function acsUrl(int $tenantId): string
    {
        return self::base() . '/acs/' . $tenantId;
    }

    /* ------------------------------------------------------------------ تحقق من إعدادات المالك */

    /** رابط الـ SSO عند الـ IdP: https وبس (المتصفح هو اللي بيروحله، السيرفر مبيفتحوش). الـ query مسموح (جوجل بيستخدمه). */
    public static function ssoUrlSafe(string $u): bool
    {
        if ($u === '' || strlen($u) > 500 || preg_match('/[\s<>"\'\\\\\x00-\x1f]/', $u) === 1) {
            return false;
        }
        $p = parse_url($u);
        if (!$p || strtolower((string) ($p['scheme'] ?? '')) !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])) {
            return false;
        }
        if (isset($p['port']) && (int) $p['port'] !== 443) {
            return false;
        }
        $h = strtolower((string) $p['host']);
        return $h !== 'localhost' && str_contains($h, '.') && filter_var($h, FILTER_VALIDATE_IP) === false && !str_starts_with($h, '[');
    }

    /**
     * شهادات توقيع الـ IdP: PEM (واحدة أو أكتر — لحد 3 للتدوير) أو base64 خام. بترجّع PEMs منظّفة، أو [] لو أي واحدة مش سليمة.
     * @return string[]
     */
    public static function certificates(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '' || strlen($raw) > 30000) {
            return [];
        }
        preg_match_all('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $raw, $m);
        $blobs = $m[1] !== [] ? $m[1] : [$raw];
        $out = [];
        foreach ($blobs as $b) {
            $b = (string) preg_replace('/\s+/', '', $b);
            if ($b === '' || preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $b) !== 1) {
                return [];
            }
            $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split($b, 64, "\n") . "-----END CERTIFICATE-----\n";
            $x = @openssl_x509_read($pem);
            $pub = $x ? @openssl_pkey_get_public($x) : false;
            $d = $pub ? openssl_pkey_get_details($pub) : null;
            if (!$d || ($d['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || (int) ($d['bits'] ?? 0) < 2048) {
                return [];
            }
            $out[$pem] = $pem;
        }
        return array_slice(array_values($out), 0, 3);
    }

    /** @return array<int,array{fingerprint_sha256:string,expires_at:?string}> للعرض في لوحة المالك. */
    public static function certInfo(string $pems): array
    {
        $out = [];
        foreach (self::certificates($pems) as $pem) {
            $der = base64_decode((string) preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pem), true) ?: '';
            $p = @openssl_x509_parse($pem);
            $out[] = [
                'fingerprint_sha256' => strtoupper(implode(':', str_split(hash('sha256', $der), 2))),
                'expires_at' => isset($p['validTo_time_t']) ? gmdate('c', (int) $p['validTo_time_t']) : null,
            ];
        }
        return $out;
    }

    /* ------------------------------------------------------------------ طلب الدخول (AuthnRequest، HTTP-Redirect) */

    private static function x(string $v): string
    {
        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** @return array{0:string,1:string} [request id, رابط التحويل للـ IdP] */
    public static function authnRequest(string $ssoUrl, string $acsUrl, string $spEntityId): array
    {
        $id = '_' . bin2hex(random_bytes(16));
        $xml = '<samlp:AuthnRequest xmlns:samlp="' . self::NS_P . '" xmlns:saml="' . self::NS_A . '" ID="' . $id . '" Version="2.0"'
            . ' IssueInstant="' . gmdate('Y-m-d\TH:i:s\Z') . '" Destination="' . self::x($ssoUrl) . '"'
            . ' ProtocolBinding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST" AssertionConsumerServiceURL="' . self::x($acsUrl) . '">'
            . '<saml:Issuer>' . self::x($spEntityId) . '</saml:Issuer><samlp:NameIDPolicy AllowCreate="true"/></samlp:AuthnRequest>';
        $url = $ssoUrl . (str_contains($ssoUrl, '?') ? '&' : '?') . 'SAMLRequest=' . rawurlencode(base64_encode((string) gzdeflate($xml)));
        return [$id, $url];
    }

    /* ------------------------------------------------------------------ Metadata الـ SP (المالك بيدّيها لمسؤول الـ IdP) */

    public static function spMetadata(string $entityId, string $acsUrl): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<md:EntityDescriptor xmlns:md="' . self::NS_MD . '" entityID="' . self::x($entityId) . '">'
            . '<md:SPSSODescriptor AuthnRequestsSigned="false" WantAssertionsSigned="true" protocolSupportEnumeration="' . self::NS_P . '">'
            . '<md:NameIDFormat>urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress</md:NameIDFormat>'
            . '<md:AssertionConsumerService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST" Location="' . self::x($acsUrl) . '" index="0" isDefault="true"/>'
            . '</md:SPSSODescriptor></md:EntityDescriptor>';
    }

    /* ------------------------------------------------------------------ قراءة Metadata الـ IdP */

    private static function loadXml(string $xml): ?\DOMDocument
    {
        if ($xml === '' || strlen($xml) > self::MAX_BYTES || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            return null;
        }
        $prev = libxml_use_internal_errors(true);
        try {
            $doc = new \DOMDocument();
            $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
        if (!$ok || !$doc->documentElement) {
            return null;
        }
        foreach ($doc->childNodes as $n) {
            if ($n->nodeType === XML_DOCUMENT_TYPE_NODE) {
                return null;
            }
        }
        return $doc;
    }

    /** @return array{entity_id:string,sso_url:string,certificate:string}|null من IDPSSODescriptor الأول. */
    public static function parseIdpMetadata(string $xml): ?array
    {
        $doc = self::loadXml($xml);
        if (!$doc) {
            return null;
        }
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('md', self::NS_MD);
        $xp->registerNamespace('ds', self::NS_DS);
        $idp = $xp->query('//md:IDPSSODescriptor')->item(0);
        if (!$idp instanceof \DOMElement) {
            return null;
        }
        $ed = $idp->parentNode;
        $entity = $ed instanceof \DOMElement ? trim($ed->getAttribute('entityID')) : '';
        $sso = '';
        foreach (['urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect'] as $binding) {
            foreach ($xp->query('./md:SingleSignOnService', $idp) as $s) {
                if ($s instanceof \DOMElement && $s->getAttribute('Binding') === $binding && self::ssoUrlSafe(trim($s->getAttribute('Location')))) {
                    $sso = trim($s->getAttribute('Location'));
                    break 2;
                }
            }
        }
        $certs = '';
        foreach ($xp->query('./md:KeyDescriptor[not(@use) or @use="signing"]/ds:KeyInfo/ds:X509Data/ds:X509Certificate', $idp) as $c) {
            $one = self::certificates((string) $c->textContent);
            if ($one !== []) {
                $certs .= $one[0];
            }
        }
        $certs = implode('', self::certificates($certs));
        if ($entity === '' || $sso === '' || $certs === '') {
            return null;
        }
        return ['entity_id' => $entity, 'sso_url' => $sso, 'certificate' => $certs];
    }

    /** يجيب metadata من رابط https عام (حماية SSRF زي OIDC: https + عنوان عام + من غير redirect). */
    public static function fetchMetadata(string $url): ?string
    {
        $p = parse_url($url);
        if (!$p || strtolower((string) ($p['scheme'] ?? '')) !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])
            || (isset($p['port']) && (int) $p['port'] !== 443) || strlen($url) > 500 || preg_match('/[\s\x00-\x1f]/', $url) === 1) {
            return null;
        }
        if (!TenantOidc::issuerSafe('https://' . strtolower((string) $p['host']), true)) {
            return null;
        }
        try {
            $res = Http::timeout(8)->withOptions(['allow_redirects' => false])->get($url);
        } catch (\Throwable $e) {
            return null;
        }
        $body = $res->successful() ? (string) $res->body() : '';
        return ($body !== '' && strlen($body) <= self::MAX_BYTES) ? $body : null;
    }

    /* ------------------------------------------------------------------ التحقق من رد الـ IdP */

    private function no(string $why, string $detail = ""): ?array
    {
        $this->lastError = $why;
        $this->lastDetail = $detail !== '' ? $detail : $why;
        return null;
    }

    private static function ts(string $v): ?int
    {
        if (preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(\.\d+)?(Z|[+-]\d\d:\d\d)$/', $v) !== 1) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($v))->getTimestamp();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed> $saml  إعدادات المنصة (idp_entity_id, certificate)
     * @return array{sub:string,email:string,name:string,picture:null,in_response_to:string,assertion_id:string,expires:int}|null
     */
    public function verify(string $b64, array $saml, string $acsUrl, string $spEntityId, ?int $now = null): ?array
    {
        $this->lastError = 'invalid';
        $this->lastDetail = null;
        $now = $now ?? time();
        $idp = trim((string) ($saml['idp_entity_id'] ?? ''));
        $certs = self::certificates((string) ($saml['certificate'] ?? ''));
        if ($idp === '' || $certs === []) {
            return $this->no('invalid', 'not configured');
        }
        $b64 = (string) preg_replace('/\s+/', '', $b64);
        if ($b64 === '' || strlen($b64) > self::MAX_BYTES) {
            return $this->no('invalid', 'size');
        }
        $xml = base64_decode($b64, true);
        $doc = $xml === false ? null : self::loadXml($xml);
        if (!$doc) {
            return $this->no('invalid', 'xml');
        }
        $root = $doc->documentElement;
        if ($root->namespaceURI !== self::NS_P || $root->localName !== 'Response' || $root->getAttribute('Version') !== '2.0') {
            return $this->no('invalid', 'root');
        }
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('p', self::NS_P);
        $xp->registerNamespace('a', self::NS_A);
        $xp->registerNamespace('ds', self::NS_DS);

        $status = (string) ($xp->query('./p:Status/p:StatusCode/@Value', $root)->item(0)?->nodeValue ?? '');
        if ($status !== 'urn:oasis:names:tc:SAML:2.0:status:Success') {
            return $this->no('idp_denied', 'status ' . $status);
        }
        if ($xp->query('./a:EncryptedAssertion', $root)->length > 0) {
            return $this->no('encrypted', 'encrypted assertion');
        }
        $assertions = $xp->query('./a:Assertion', $root);
        if ($assertions->length !== 1 || !($assertions->item(0) instanceof \DOMElement)) {
            return $this->no('invalid', 'assertion count');
        }
        /** @var \DOMElement $as */
        $as = $assertions->item(0);
        if ($as->getAttribute('Version') !== '2.0' || trim($as->getAttribute('ID')) === '') {
            return $this->no('invalid', 'assertion header');
        }

        // ---- التوقيع: كل Signature في الوثيقة لازم تكون ابن مباشر للـ Response أو الـ Assertion وتتحقق. وواحد منهم على الأقل لازم يوجد.
        $respSigned = false;
        $asSigned = false;
        foreach ($xp->query('//ds:Signature') as $sig) {
            if (!$sig instanceof \DOMElement) {
                continue;
            }
            $parent = $sig->parentNode;
            if ($parent !== $root && $parent !== $as) {
                return $this->no('signature', 'signature in unexpected place');
            }
            if ($parent === $root ? $respSigned : $asSigned) {
                return $this->no('signature', 'duplicate signature');
            }
            if (!$this->verifySignature($doc, $xp, $sig, $certs)) {
                return $this->no('signature', $this->lastDetail ?? 'bad signature');
            }
            if ($parent === $root) {
                $respSigned = true;
            } else {
                $asSigned = true;
            }
        }
        if (!$respSigned && !$asSigned) {
            return $this->no('signature', 'unsigned');
        }

        // ---- Response
        $dest = trim($root->getAttribute('Destination'));
        if ($dest !== '' ? $dest !== $acsUrl : $respSigned) {
            return $this->no('destination', 'destination ' . $dest);
        }
        foreach ($xp->query('./a:Issuer', $root) as $i) {
            if (trim((string) $i->textContent) !== $idp) {
                return $this->no('invalid', 'response issuer');
            }
        }
        $respIrt = trim($root->getAttribute('InResponseTo'));

        // ---- Assertion
        $issuers = $xp->query('./a:Issuer', $as);
        if ($issuers->length !== 1 || trim((string) $issuers->item(0)->textContent) !== $idp) {
            return $this->no('invalid', 'assertion issuer');
        }

        $irt = '';
        $confExp = 0;
        foreach ($xp->query('./a:Subject/a:SubjectConfirmation[@Method="urn:oasis:names:tc:SAML:2.0:cm:bearer"]/a:SubjectConfirmationData', $as) as $d) {
            if (!$d instanceof \DOMElement) {
                continue;
            }
            $exp = self::ts($d->getAttribute('NotOnOrAfter'));
            $nb = $d->getAttribute('NotBefore') !== '' ? self::ts($d->getAttribute('NotBefore')) : 0;
            if ($d->getAttribute('Recipient') !== $acsUrl || $exp === null || $nb === null || trim($d->getAttribute('InResponseTo')) === '') {
                continue;
            }
            if ($exp + self::SKEW <= $now || $nb - self::SKEW > $now) {
                $this->lastDetail = 'confirmation window';
                continue;
            }
            $irt = trim($d->getAttribute('InResponseTo'));
            $confExp = $exp;
            break;
        }
        if ($irt === '') {
            return $this->no($this->lastDetail === 'confirmation window' ? 'time' : 'invalid', 'bearer confirmation');
        }
        if ($respIrt !== '' && $respIrt !== $irt) {
            return $this->no('invalid', 'inresponseto mismatch');
        }

        $cond = $xp->query('./a:Conditions', $as);
        if ($cond->length !== 1 || !($cond->item(0) instanceof \DOMElement)) {
            return $this->no('invalid', 'conditions');
        }
        /** @var \DOMElement $c */
        $c = $cond->item(0);
        if ($c->getAttribute('NotBefore') !== '') {
            $nb = self::ts($c->getAttribute('NotBefore'));
            if ($nb === null || $nb - self::SKEW > $now) {
                return $this->no('time', 'conditions notbefore');
            }
        }
        $noa = self::ts($c->getAttribute('NotOnOrAfter'));
        if ($noa === null || $noa + self::SKEW <= $now) {
            return $this->no('time', 'conditions notonorafter');
        }
        $restrictions = $xp->query('./a:AudienceRestriction', $c);
        if ($restrictions->length < 1) {
            return $this->no('audience', 'no audience restriction');
        }
        foreach ($restrictions as $r) {
            $ok = false;
            foreach ($xp->query('./a:Audience', $r) as $a) {
                if (trim((string) $a->textContent) === $spEntityId) {
                    $ok = true;
                }
            }
            if (!$ok) {
                return $this->no('audience', 'audience mismatch');
            }
        }
        if ($xp->query('./a:AuthnStatement', $as)->length < 1) {
            return $this->no('invalid', 'no authn statement');
        }

        // ---- الهوية
        $attrs = [];
        foreach ($xp->query('./a:AttributeStatement/a:Attribute', $as) as $at) {
            if (!$at instanceof \DOMElement) {
                continue;
            }
            $k = strtolower(trim($at->getAttribute('Name')));
            $v = $xp->query('./a:AttributeValue', $at)->item(0);
            if ($k !== '' && $v && !isset($attrs[$k]) && trim((string) $v->textContent) !== '') {
                $attrs[$k] = trim((string) $v->textContent);
            }
        }
        $nid = $xp->query('./a:Subject/a:NameID', $as)->item(0);
        $nameId = $nid ? trim((string) $nid->textContent) : '';
        $nidFmt = $nid instanceof \DOMElement ? $nid->getAttribute('Format') : '';

        $email = '';
        foreach (array_merge(self::EMAIL_ATTRS, ['__nameid']) as $k) {
            $cand = strtolower($k === '__nameid' ? $nameId : ($attrs[$k] ?? ''));
            if ($cand !== '' && strlen($cand) <= 190 && filter_var($cand, FILTER_VALIDATE_EMAIL) !== false) {
                $email = $cand;
                break;
            }
        }
        if ($email === '') {
            return $this->no('no_email', 'no email claim');
        }
        $name = '';
        foreach (self::NAME_ATTRS as $k) {
            if (!empty($attrs[$k])) {
                $name = $attrs[$k];
                break;
            }
        }
        if ($name === '') {
            $g = '';
            $s = '';
            foreach (self::GIVEN_ATTRS as $k) {
                if (!empty($attrs[$k])) {
                    $g = $attrs[$k];
                    break;
                }
            }
            foreach (self::SUR_ATTRS as $k) {
                if (!empty($attrs[$k])) {
                    $s = $attrs[$k];
                    break;
                }
            }
            $name = trim($g . ' ' . $s);
        }
        $sub = ($nameId !== '' && $nidFmt !== 'urn:oasis:names:tc:SAML:2.0:nameid-format:transient') ? $nameId : $email;
        if (strlen($sub) > 150) {
            $sub = 'h:' . hash('sha256', $sub);
        }

        $this->lastError = null;
        $this->lastDetail = null;
        return [
            'sub' => $sub, 'email' => $email, 'name' => mb_substr(strip_tags($name), 0, 150), 'picture' => null,
            'in_response_to' => $irt, 'assertion_id' => trim($as->getAttribute('ID')), 'expires' => $confExp,
        ];
    }

    /** @param string[] $certs PEMs */
    private function verifySignature(\DOMDocument $doc, \DOMXPath $xp, \DOMElement $sig, array $certs): bool
    {
        $fail = function (string $d): bool {
            $this->lastDetail = $d;
            return false;
        };
        $target = $sig->parentNode;
        if (!$target instanceof \DOMElement) {
            return $fail('no parent');
        }
        $sis = $xp->query('./ds:SignedInfo', $sig);
        if ($sis->length !== 1 || !($sis->item(0) instanceof \DOMElement)) {
            return $fail('signedinfo');
        }
        /** @var \DOMElement $si */
        $si = $sis->item(0);
        if ((string) $xp->query('./ds:CanonicalizationMethod/@Algorithm', $si)->item(0)?->nodeValue !== self::C14N_EXC) {
            return $fail('c14n method');
        }
        $sigAlg = self::SIG_ALGS[(string) $xp->query('./ds:SignatureMethod/@Algorithm', $si)->item(0)?->nodeValue] ?? null;
        if ($sigAlg === null) {
            return $fail('signature method');
        }
        $refs = $xp->query('./ds:Reference', $si);
        if ($refs->length !== 1 || !($refs->item(0) instanceof \DOMElement)) {
            return $fail('reference count');
        }
        /** @var \DOMElement $ref */
        $ref = $refs->item(0);
        $uri = $ref->getAttribute('URI');
        if (preg_match('/^#([A-Za-z_][A-Za-z0-9_.\-]*)$/', $uri, $m) !== 1) {
            return $fail('reference uri');
        }
        $id = $m[1];
        // الـ ID لازم يكون فريد في الوثيقة كلها وللعنصر اللي التوقيع جواه بالظبط (wrapping).
        if ($target->getAttribute('ID') !== $id || $xp->query('//*[@ID="' . $id . '"]')->length !== 1) {
            return $fail('reference target');
        }
        $digestAlg = self::DIGEST_ALGS[(string) $xp->query('./ds:DigestMethod/@Algorithm', $ref)->item(0)?->nodeValue] ?? null;
        if ($digestAlg === null) {
            return $fail('digest method');
        }
        $enveloped = false;
        $prefixes = [];
        foreach ($xp->query('./ds:Transforms/ds:Transform', $ref) as $t) {
            if (!$t instanceof \DOMElement) {
                continue;
            }
            $alg = $t->getAttribute('Algorithm');
            if ($alg === self::ENVELOPED) {
                $enveloped = true;
            } elseif ($alg === self::C14N_EXC) {
                foreach ($t->childNodes as $ch) {
                    if ($ch instanceof \DOMElement && $ch->localName === 'InclusiveNamespaces') {
                        $prefixes = array_values(array_filter(preg_split('/\s+/', trim($ch->getAttribute('PrefixList'))) ?: [], static fn ($x) => $x !== ''));
                    }
                }
            } else {
                return $fail('transform ' . $alg);
            }
        }
        if (!$enveloped) {
            return $fail('no enveloped transform');
        }
        $digest = base64_decode((string) preg_replace('/\s+/', '', (string) $xp->query('./ds:DigestValue', $ref)->item(0)?->textContent), true);
        $sigBytes = base64_decode((string) preg_replace('/\s+/', '', (string) $xp->query('./ds:SignatureValue', $sig)->item(0)?->textContent), true);
        if ($digest === false || $digest === '' || $sigBytes === false || $sigBytes === '') {
            return $fail('base64');
        }

        // digest: العنصر من غير الـ Signature بتاعه (enveloped) ثم exclusive c14n.
        $next = $sig->nextSibling;
        $target->removeChild($sig);
        try {
            $c14n = $target->C14N(true, false, null, $prefixes ?: null);
        } finally {
            $target->insertBefore($sig, $next);
        }
        if (!is_string($c14n) || !hash_equals($digest, hash($digestAlg, $c14n, true))) {
            return $fail('digest mismatch');
        }

        $signed = $si->C14N(true, false);
        if (!is_string($signed)) {
            return $fail('c14n signedinfo');
        }
        foreach ($certs as $pem) {
            $key = openssl_pkey_get_public($pem);
            if ($key && openssl_verify($signed, $sigBytes, $key, $sigAlg) === 1) {
                return true;
            }
        }
        return $fail('signature value mismatch');
    }
}
