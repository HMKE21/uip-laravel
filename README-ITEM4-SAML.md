# بند 4 — إضافة SAML 2.0 (الباك اند)

الـ zip ده تراكمي: فيه كل ملفات بند 4 (26 ملف من قبل) + SAML (ملفين جداد + تعديلات على 6). مفيش migration.

## جديد
- `app/Services/Tenancy/TenantSaml.php` — SP كامل بـ DOM + OpenSSL من غير مكتبات: AuthnRequest، التحقق من الرد، metadata الـ SP، استيراد metadata الـ IdP.
- `app/Http/Controllers/Api/Auth/TenantSamlController.php`

## معدّل
`TenantAuthSettings` (حقل `saml` + تحقق) · `TenantSecuritySettings` (payload + `testSaml`) · `TenantController` (rules + `testSaml`) · `TenantSignInController` (`methods.saml`, `methods.saml_label`) · `routes/api.php` · `routes/developer.php`

## الـ endpoints
| | |
|---|---|
| `GET /auth/tenant-saml/config` | `{enabled,label}` |
| `POST /auth/tenant-saml/start` | → `{redirect_url,state}` (الفرونت يحفظ state في sessionStorage ويحوّل المتصفح) |
| `POST /auth/tenant-saml/acs/{tenantId}` | الـ IdP بيعمل POST هنا. بعد التحقق يحوّل المتصفح لـ `{front}/sso/saml?code=…` أو `?error=invalid\|expired\|idp_denied\|no_email\|time\|sso_disabled` |
| `POST /auth/tenant-saml/exchange` `{code,state}` | نفس رد دخول جوجل/OIDC (كل الحراسات) |
| `GET /auth/tenant-saml/metadata/{tenantId}` | SP metadata XML |
| `POST /developer/tenants/{id}/security/test-saml` `{metadata_url\|metadata_xml}` | بيرجّع `entity_id, sso_url, certificate, certificates[]` عشان تملا الخانات |
| `GET/PUT /developer/tenants/{id}/security` | `security.saml = {enabled,idp_entity_id,sso_url,certificate,label}` + في الرد `saml_sp {entity_id,acs_url,metadata_url}` و`saml_certificates[]` (بصمة + انتهاء) |

## الأمان
- التوقيع إجباري (Response أو Assertion) بشهادة المالك بس (لحد 3 للتدوير، RSA ≥ 2048). RSA-SHA256/384/512 بس.
- حماية من XML Signature Wrapping، XXE/DOCTYPE، EncryptedAssertion مرفوض، Assertion واحدة بالظبط.
- Issuer / Audience / Recipient / Destination / InResponseTo / NotBefore / NotOnOrAfter (سماح 90 ثانية).
- SP-initiated بس: الـ request ID بيتستهلك مرة واحدة، والـ Assertion ID مبيتقبلش مرتين، والكود لمرة واحدة 120 ثانية ومربوط بـ state المتصفح.

## لازم تعرفه
- `APP_URL` لازم يكون رابط الـ API العام (منه بيتكوّن ACS و Entity ID).
- الكاش لازم يبقى مشترك بين السيرفرات (Redis/DB) زي Magic link.
- الفرونت محتاج صفحة `/sso/saml` تقرا `code` أو `error` وتنادي `exchange`.
