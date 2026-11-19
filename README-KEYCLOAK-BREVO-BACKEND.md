# UIPE — ربط الباك اند بسيرفر الهوية (Keycloak) + إرسال البريد الخارجي (Brevo)

انسخ الملفات فوق `uip-laravel-main` (نفس المسارات). مفيش migration جديدة.

## متغيرات .env (مفيهاش أي secret للـ Keycloak)
```
KEYCLOAK_BASE_URL=https://uipe-identity.duckdns.org
KEYCLOAK_REALM=uipe
KEYCLOAK_CLIENT_ID=uipe-web

UIPE_MAIL_EXTERNAL_DRIVER=brevo
UIPE_MAIL_EXTERNAL_FROM=no-reply@<دومين/إيميل متأكَّد في Brevo>
# اختياري: ردود الخارج هتروح هنا (عنوان UIPE مفيهوش MX لسه)
UIPE_MAIL_EXTERNAL_REPLY_TO=
BREVO_API_KEY=xkeysib-...      # أو من Admin → Settings → Mail (الأفضل)
```
بعد التعديل: `php artisan config:clear`.

## 1) Keycloak
- `GET /api/v1/auth/keycloak/config` → `{enabled, client_id, issuer}`
- `POST /api/v1/auth/keycloak {id_token}` → نفس رد /auth/google (دخول، أو `needs_registration`، أو 2FA).
- السيرفر بيتحقق: توقيع RS256 بـ JWKS الـ realm، iss، aud، azp، exp/iat/nbf، email + email_verified=true.
- الربط بالحسابات: `sub` بيتخزن في `user_social_accounts` (provider=keycloak) وبيتلاقي المستخدم بالإيميل، فمفيش حسابات مكررة.
- حسابات الستاف (admin/security...) ممنوعة تدخل بالمزوّد ده زي جوجل. الأدوار بتفضل في قاعدة UIP، ومبنقراش roles من Keycloak.
- ⚠ في Keycloak: فعّل Verify Email في الـ realm، وافتح `uipe-web` Valid redirect URIs = `https://<الفرونت>/keycloak-auth.html` و Web origins = دومين الفرونت. ممنوع تستخدم `uipe-console-master-admin` هنا.

## 2) إرسال خارجي (Brevo)
- لما `UIPE_MAIL_EXTERNAL_DRIVER=brevo` + مفتاح + FROM، العناوين الخارجية بتتقبل في الإرسال (سياسة الجامعة `allow_external` لسه بتتطبق).
- بيتبعت عند التسليم الفعلي (فبيشتغل مع Undo Send والجدولة). الفشل مبيلغيش التسليم الداخلي: بيتسجل في اللوج و`schedule_error` على الرسالة.
- إرسال فقط. استقبال من Gmail/Outlook محتاج MX + Stalwart (مؤجّل). حد Brevo للمرفقات حوالي 4MB.
- الـ FROM الفعلي هو `UIPE_MAIL_EXTERNAL_FROM` (Brevo بيرفض مُرسِل مش متأكَّد)؛ اسم الظاهر = اسم صاحب الصندوق.

## الملفات
| الملف | الحالة |
|---|---|
| app/Services/KeycloakIdTokenVerifier.php | جديد |
| app/Http/Controllers/Api/Auth/KeycloakAuthController.php | جديد |
| app/Http/Controllers/Api/Auth/GoogleAuthController.php | معدّل (provider keycloak في whitelist) |
| app/Services/Mail/BrevoExternalMailGateway.php | جديد |
| app/Services/Mail/ExternalMailGateway.php | معدّل (doc) |
| app/Services/Mail/MailboxService.php | معدّل (إرسال خارجي في deliver) |
| app/Providers/AppServiceProvider.php | معدّل (binding حسب الـ driver) |
| config/mailbox.php, config/services.php | معدّل |
| routes/api.php | معدّل (route الـ keycloak) |
| tests/Feature/KeycloakAuthTest.php | جديد |

## تنبيه
مفيش PHP 8.4 في بيئتي ولا اتصال بالنت، فالكود **ما اتشغّلش ولا اتعمله php -l**؛ اتراجع بالعين. شغّل:
`php artisan test --filter=KeycloakAuthTest` و `--filter=MailTenantTest`، وجرّب إرسال لإيميل خارجي بإيدك.
