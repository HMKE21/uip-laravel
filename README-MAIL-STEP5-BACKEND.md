# UIPE Mail — المرحلة 5 (Backend) — الدفعة 1: سجل الدخول + إلغاء التوكنز + Provisioning تلقائي

> الدفعة 2 (Passkeys + CSV + SCIM + تعليق الصندوق عند انتهاء العضوية) جاية في zip منفصل.

## التشغيل
```
php artisan migrate
php artisan test --filter=IdentityBatch1Test
```
والـ scheduler (`schedule:run` كل دقيقة) هيشغّل `mail:identity-sync` لوحده. ينفع يدويًا: `php artisan mail:identity-sync [--provision] [--prune]`.

## الجديد
**الـ migration** `2027_11_22_100000_create_identity_provisioning.php` (بتجهّز كل جداول المرحلة 5، حتى اللي هتتستخدم في الدفعة 2):
`login_events`, `passkeys`, `scim_tokens`, `scim_identities`, `provisioning_runs`، وعمود `users.tokens_valid_after`،
وأعمدة `mail_tenants` (`auto_provision, suspend_on_expiry, graduate_grace_days`) و`mail_accounts` (`membership_expires_at, suspended_by`).

**1) Login History** — كل دخول ناجح/فاشل بيتسجّل (الطريقة، IP، الجهاز، الموقع، `is_new_device`). الفشل بلا GeoIP عشان هجمات التخمين.
أماكن التسجيل: `LoginController` (كل أسباب الرفض + النجاح، وبيشمل جوجل/مايكروسوفت/Keycloak/GitHub لأنهم بيعدّوا على `finishLogin`) و`TwoFactorChallengeController`.
مبيكسرش الدخول لو التسجيل فشل. السجل بيتمسح بعد `LOGIN_EVENTS_DAYS` (180 يوم افتراضيًا).

**2) إلغاء التوكنز** — بنينا على الجلسات الموجودة (كل access token ليه `sid`). الجديد:
- "خروج من باقي الأجهزة": بيقفل كل الجلسات + الـ refresh tokens ما عدا الحالية.
- "خروج من كل الأجهزة": كل ده + إلغاء الأجهزة الموثوقة + `users.tokens_valid_after` (أي access token أقدم منه بيبطل في `UipAuthMiddleware`، حتى لو من غير `sid`). المستخدم نفسه بيتطلّع كمان.
- الأدمن/الجامعة يلغي كل جلسات عضو بعينه.

**3) إنشاء البريد تلقائيًا** (`MailProvisioningService`) — لما الجامعة يكون ليها نطاق primary مفعّل و`auto_provision=true`:
- عند دعوة طالب / دعوة عضو هيئة تدريس / الموافقة على طلب انضمام طالب.
- كسولًا عند فتح `GET /mail/account` (مرة كل 5 دقايق للمستخدم).
- مجدولًا كل 10 دقايق للي فاته (حد 2000 لكل جامعة في الدورة).
- من غير نطاق مفعّل أو لو الجامعة موقوفة: بيتخطى بهدوء ويحاول بعدين.

## API
| Method | Path | مين | الوصف |
|---|---|---|---|
| GET | /api/v1/auth/login-history?page=&per_page= | المستخدم | سجل دخوله (`meta.last_success_at`) |
| POST | /api/v1/auth/sessions/revoke-others | المستخدم | خروج من باقي الأجهزة |
| POST | /api/v1/auth/sessions/revoke-all | المستخدم | خروج من كل الأجهزة (بيطلّعه هو كمان) |
| GET | /api/v1/identity/login-history?user_id=&success=&method=&q=&from=&to= | أدمن المنصة / الجامعة (أعضاؤها بس) | بحث في السجل |
| POST | /api/v1/identity/users/{id}/revoke-tokens | أدمن المنصة / الجامعة (أعضاؤها بس) | إلغاء كل جلسات وتوكنز عضو |
| PUT | /api/v1/mail-admin/tenant/policies | الجامعة | بقى بيقبل كمان `auto_provision, suspend_on_expiry, graduate_grace_days` |

كل المسارات الجديدة في `routes/identity.php` (متحمّلة من آخر `routes/api.php`).

## الملفات المتغيّرة في ملفات موجودة (راجعها لو عندك تعديلات محلية)
`LoginController`, `TwoFactorChallengeController`, `UipAuthMiddleware`, `MailApiController::account`, `MailTenantService` (provisionMembers + policies), `MailAccount`,
`StudentManagementService::invite`, `AcademicStaffManagementService::invite`, `StudentJoinRequestService::approve`, `routes/api.php`, `routes/console.php`.
`LoginController::finishLogin` بقى بياخد باراميتر اختياري رابع `$mfaSatisfied` (هيتستخدم للـ Passkeys في الدفعة 2).

## ملاحظات
- لم أقدر أشغّل اختبارات Laravel في بيئتي (مفيش vendor)؛ الكود اتعمل له `php -l` والاختبارات مكتوبة بنفس أسلوب `MailTenantTest`. شغّلها عندك وقولي لو ظهر أي فشل.
- لو نشرت الكود قبل الـ migration: الدخول يفضل شغال (الـ middleware مكتوب بحيث العمود الجديد اختياري)، بس سجل الدخول مش هيتسجّل لحد ما تعمل migrate.
