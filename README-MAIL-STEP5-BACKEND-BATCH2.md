# UIPE Mail — المرحلة 5 (Backend) — الدفعة 2: Passkeys + استيراد CSV + SCIM + تعليق الصندوق عند انتهاء العضوية

> الدفعة دي بتتفرد **فوق الدفعة 1**. مفيش migration جديدة (كل الجداول اتعملت في migration الدفعة 1).

## التشغيل
```
php artisan test --filter=WebAuthnTest
php artisan test --filter=IdentityBatch2Test
php artisan test --filter=IdentityBatch1Test
```
**مهم:** الاختبارات دي مقدرتش أشغّلها كـ Laravel عندي (مفيش vendor). اللي اتجرّب فعلًا: `php -l` على كل الملفات، وتجربة `WebAuthnTest` بـ runner مستقل
(7/7 نجحوا: ES256 وRS256 وتسجيل ودخول حقيقيين ومحاولات تلاعب). `IdentityBatch2Test` لسه متجرّبش على الـ framework — لو فشل أي اختبار ابعتلي رسالة الفشل وأصلحه.

## إعدادات `.env` للـ Passkeys
```
PASSKEY_ORIGINS=https://uipe.example.com      # origins الفرونت (مفصولة بفاصلة). من غيرها الـ Passkeys بتتقفل (503)
PASSKEY_RP_ID=uipe.example.com               # اختياري: لو فاضي بيتاخد من أول origin
PASSKEY_RP_NAME=UIPE
```
بعد التعديل: `php artisan config:clear`. ملاحظة: الـ passkey مربوطة بالـ RP ID، فلو غيّرته المفاتيح القديمة هتبطل.

## 1) Passkeys (WebAuthn بدون مكتبات خارجية)
- `app/Support/WebAuthn/{Cbor,WebAuthn}.php`: مفكّك CBOR + تحقق من التسجيل والتوقيع (ES256 / RS256) بـ openssl. attestation = none.
- التحقق من المستخدم (UV) إجباري، ومفاتيح discoverable، والـ challenge مرة واحدة وبينتهي بعد 5 دقايق، وعدّاد التوقيع لازم يزيد (كشف النسخ).
- الدخول بـ passkey بيعدّي على `LoginController::finishLogin` (نفس حراسات الباسورد: نوع الجهاز، الصيانة، الجلسة الواحدة، القفل، سجل الدخول بطريقة `passkey`) وبيغني عن كود الـ 2FA.
- أدوار الستاف (`admin, data_analyst, security_admin, security_officer`) مبتدخلش ولا بتسجّل passkeys (زي جوجل).
- أقصى عدد للمستخدم: `PASSKEY_MAX_PER_USER` (10).

| Method | Path | مين |
|---|---|---|
| GET | /api/v1/auth/passkeys/config | عام → `{enabled}` |
| POST | /api/v1/auth/passkeys/login/options | عام → `{ceremony_id, publicKey}` |
| POST | /api/v1/auth/passkeys/login | عام: `{ceremony_id, credential}` → نفس رد اللوجين |
| GET | /api/v1/auth/passkeys | المستخدم: قائمة مفاتيحه |
| POST | /api/v1/auth/passkeys/register/options | المستخدم |
| POST | /api/v1/auth/passkeys/register | المستخدم: `{credential, name?}` |
| PATCH / DELETE | /api/v1/auth/passkeys/{id} | المستخدم: إعادة تسمية / حذف |

شكل `credential` (كله base64url): `{id, response:{clientDataJSON, attestationObject, transports?}}` للتسجيل،
و`{id, response:{clientDataJSON, authenticatorData, signature, userHandle}}` للدخول. الفرونت بيحوّل `ArrayBuffer`→base64url.

## 2) تعليق الصندوق عند انتهاء العضوية (`MailMembershipService`)
بيعلّق الصندوق المؤسسي (`suspended_by='membership'`) لو: انتهى `membership_expires_at` · حساب المنصة موقوف/محظور/محذوف · الطالب مبقاش تابع للجامعة ·
الطالب اتخرّج وعدّت `graduate_grace_days` · عضو الهيئة غير نشط. وبيستأنف تلقائيًا لما السبب يزول.
- **اللي الأدمن وقّفه يدويًا مبيتلمسش** (بيتصفّر `suspended_by` لما الأدمن يغيّر الحالة من لوحة الجامعة).
- لو `suspend_on_expiry=false`: مفيش تعليق جديد، واللي اتعلّق تلقائيًا بيتستأنف.
- بيشتغل كل ساعة بالـ scheduler، أو يدويًا: `php artisan mail:identity-sync --membership`.
- لو الأدمن فتح صندوق منتهي عضويته يدويًا هيتقفل تاني في الدورة الجاية، إلا لو اتمسح `membership_expires_at` أو السبب اتصلح.

## 3) استيراد CSV / XLSX (`ProvisioningImportService`)
بيبني على `importRows()` الموجودة للطلاب وهيئة التدريس (نفس الأعمدة والتحققات)، والصندوق بيتعمل تلقائيًا لكل صف ناجح. حد أقصى 2000 صف و5MB.
معاينة (`dry_run=true`) بتتحقق من الإيميل والاسم والتكرار داخل الملف ووجود حساب بنفس الإيميل من غير ما تكتب حاجة.

| Method | Path | مين |
|---|---|---|
| GET | /api/v1/mail-admin/provisioning/template?type=student\|staff | قالب CSV |
| POST | /api/v1/mail-admin/provisioning/import | multipart: `file, type, dry_run, send_email, locale` — الجامعة (كلها) / الكلية (كليتها بس) |
| GET | /api/v1/mail-admin/provisioning/runs | سجل العمليات (CSV / SCIM / المزامنة) |

## 4) SCIM 2.0 لكل جامعة
الجامعة بتعمل توكن من لوحتها، وتديه للـ IdP (Entra / Okta / Keycloak) مع الـ Base URL: `https://<domain>/api/scim/v2`. التوكن بيظهر **مرة واحدة** وبيتخزن hash بس.

| Method | Path | الوصف |
|---|---|---|
| GET / POST | /api/v1/mail-admin/scim/tokens | قائمة التوكنز / إنشاء (`name`, `expires_in_days?`) — الجامعة فقط |
| DELETE | /api/v1/mail-admin/scim/tokens/{id} | إلغاء توكن |
| GET | /api/scim/v2/ServiceProviderConfig · /ResourceTypes · /Schemas | Discovery |
| GET / POST | /api/scim/v2/Users | قائمة (`filter`, `startIndex`, `count`) / إنشاء |
| GET / PUT / PATCH / DELETE | /api/scim/v2/Users/{id} | قراءة / تعديل / تعديل جزئي / تعطيل |

- المستخدمين = طلاب (`userType=student`) وهيئة تدريس (`staff`) تابعين لجامعة التوكن بس. الفلتر المدعوم: `userName | externalId | emails.value | id eq "..."`.
- الإنشاء بيعمل دعوة + صندوق تلقائي. `active=false` بيعطّل الحساب والصندوق ويقطع كل الجلسات والتوكنز. `DELETE` = تعطيل (مش حذف).
- سمات الـ extension (`urn:uipe:scim:schemas:extension:1.0:User`): `nameAr, nameEn, studentNumber, staffNumber, facultyId, departmentId, membershipExpiresAt` (+ `mailAddress` للقراءة فقط).
- مقصود: تعديل الأسماء/الإيميل من الـ IdP بعد الإنشاء مش بيتطبّق.
- `SCIM_ENABLED=false` بيقفل الـ endpoints كلها.

## 5) لوحة الجامعة: العضوية
| Method | Path | الوصف |
|---|---|---|
| POST | /api/v1/mail-admin/membership/sweep | `{dry_run}` (الافتراضي معاينة) — الجامعة فقط |
| PUT | /api/v1/mail-admin/accounts/{id}/expiry | `{membership_expires_at: ISO8601 \| null}` — الجامعة فقط |

وقائمة الأعضاء (`GET /mail-admin/members`) بقت بترجّع كمان `suspended_by` و`membership_expires_at`.

## إصلاح في الدفعة 1
`PUT /mail-admin/tenant/policies` كان بيعمل validate من غير `auto_provision / suspend_on_expiry / graduate_grace_days` فالقيم دي كانت بتتشال ومبتتحفظش. اتصلح في `MailTenantApiController`.

## الملفات المتغيّرة في ملفات موجودة (راجعها لو عندك تعديلات محلية)
`MailTenantApiController` (سطر واحد في الـ validate) · `MailTenantService` (تصفير `suspended_by` + حقلين في قائمة الأعضاء) ·
`routes/console.php` (جدولة `--membership`) · `routes/identity.php` · `MailIdentitySync` (دول من الدفعة 1).

## الجديد
`app/Support/WebAuthn/{Cbor,WebAuthn}.php` · `Services/Identity/{PasskeyService,ProvisioningImportService,ScimService,ScimException}.php` · `Services/Mail/MailMembershipService.php` ·
`Controllers/Api/{ScimController,ProvisioningApiController}.php` · `Controllers/Api/Auth/PasskeyController.php` · `tests/Unit/WebAuthnTest.php` · `tests/Feature/IdentityBatch2Test.php`
