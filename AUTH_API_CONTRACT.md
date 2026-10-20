# عقد API — موديول Auth (المرجع الرسمي للهجرة إلى Laravel)

> استُخرج مباشرة من `routes/api.php` و`app/Controllers/Auth/*` و`core/Controller.php` في المشروع الحالي.
> **القاعدة الذهبية:** أي endpoint بتتحول للارافيل لازم يرجّع نفس الـ status code ونفس الـ JSON shape اللي هنا، حرف بحرف، وإلا الفرونت (React) هيكسر.

جميع المسارات تحت prefix: `/api/v1`

---

## 🐛 Bug فاضل اتصلح — Password Hashing (22 أغسطس 2026)

أول تجربة فعلية لـ `/auth/login` طلعت خطأ 500: **"This password does not
use the Bcrypt algorithm."** السبب: القديم بيستخدم دوال PHP الأصلية
(`password_hash($x, PASSWORD_BCRYPT)` / `password_verify($x, $y)`) بدون
أي فحص إضافي على شكل الهاش. لكن الكود اللي اتكتب هنا في اللارافيل كان
مستخدم `Hash::check()`/`Hash::make()` بتاعة لارافيل بالغلط، واللي بتعمل
فحص زيادة (`password_get_info()`) بيرفض أي هاش مش بادئ بـ `$2y$` تحديدًا
— وهاشات القديم بادئة بـ `$2b$` (الاتنين bcrypt سليم 100%، بس PHP نفسه
مش بيتعرف على `$2b$` كـ "bcrypt" في الفحص ده تحديدًا).

**الإصلاح:** استبدال `Hash::check()`/`Hash::make()` بـ
`password_verify()`/`password_hash($x, PASSWORD_BCRYPT)` الأصليين في
الثلاث أماكن اللي كانت بتستخدمهم:
- `LoginController.php` — التحقق من كلمة السر عند الدخول.
- `RegisterController.php` — توليد الهاش عند التسجيل.
- `ResetPasswordController.php` — توليد الهاش عند إعادة التعيين.

ده مش "فرق تصميم متعمّد" زي باقي الملاحظات في الملف ده — ده كان باگ،
اتصلح ليطابق القديم 100% (`AuthService::attemptLogin()`/`register()`/
`resetPassword()`). لو عندك مستخدمين اتسجّلوا بالفعل من خلال نسخة
اللارافيل **قبل** الإصلاح ده، هاشاتهم اتولدت بـ `Hash::make()` (بادئة
`$2y$`) — لسه هيشتغلوا عادي مع `password_verify()` الجديدة (بتقبل
`$2y$`/`$2a$`/`$2b$` كلهم من غير مشاكل)، فمفيش داعي لأي إعادة تسجيل.

---

## شكل الرد العام (Response Envelope)

كل الـ endpoints اللي بتستخدم `apiSuccess()` / `apiError()` بترجع نفس الشكل ده دايمًا:

**نجاح:**
```json
{
  "success": true,
  "message": "...",
  "data": { },
  "errors": null,
  "meta": {}
}
```

**فشل:**
```json
{
  "success": false,
  "message": "...",
  "data": null,
  "errors": { "field": ["..."] },
  "meta": {}
}
```

ملاحظة: بعض الـ endpoints القديمة (Login/Logout/ForgotPassword) بترجع شكل أبسط `{"success": bool, "message": "...", ...}` من غير الـ envelope الكامل (data/errors/meta) — موضّح تحت كل واحدة.

---

## 1) `POST /api/v1/auth/login`
**Controller:** `LoginController::submit` — بدون Middleware (بره AuthMiddleware)

**Request body:**
```json
{ "email": "string, required, email", "password": "string, required" }
```

**رد النجاح العادي (200):**
```json
{
  "success": true,
  "message": "Login successful.",
  "data": {
    "redirect": "/student/dashboard",
    "access_token": "eyJ...",
    "refresh_token": "hex64",
    "token_type": "Bearer",
    "expires_in": 900
  },
  "errors": null,
  "meta": {}
}
```

**لو الحساب عليه 2FA (200):**
```json
{
  "success": true,
  "message": "Two-factor verification required.",
  "data": { "requires_2fa": true, "redirect": "/auth/two-factor", "csrf_token": "..." },
  "errors": null, "meta": {}
}
```
⚠️ **لارافيل (Stateless):** `csrf_token` بقى `challenge_token` — JWT قصير العمر (5 دقايق)
لازم يترجع في `/auth/two-factor/verify` و`/cancel`. شوف "⚠️ فرق عن العقد الأصلي" تحت الجدول.

**فشل (422):**
```json
{ "success": false, "message": "Invalid email or password." }
```
⚠️ ملحوظة: شكل الفشل هنا **بسيط** (مفيهوش `data/errors/meta`) — بيستخدم `$this->json()` مباشرة مش `apiError()`.

> **تحديث: الـ access_token بقى مشفّر.** `UipJwtService::issueTokenPair()` بيشفّر الـ JWT الموقّع بـ AES-256-GCM
> ويرجّعه بصيغة `uipe1.<base64url>` (المفتاح من `JWT_ENC_KEY` أو بيتشتق من `JWT_SECRET`). مفيش claims ظاهرة للعميل،
> فالرد بقى فيه `data.user = { id, role, name, email }` للعرض. `decode()` بيقبل الشكلين (المشفّر والـ JWT العادي).
> الـ refresh_token كان ولسه string عشوائي معتم (بيتخزن hash بس).

**access_token (JWT) — الشكل بالظبط:**
- Header: `{"typ":"JWT","alg":"HS256"}`
- Payload: `{"sub": <user_id:int>, "role": "<role>", "iat": <unix>, "exp": <unix>}`
- التوقيع: `HMAC-SHA256(header.payload, JWT_SECRET)` — base64url بدون padding، 3 أجزاء مفصولة بـ `.`
- `JWT_SECRET` و`JWT_ACCESS_TTL` (افتراضي 900 ثانية) من الـ `.env`

**refresh_token:** string عشوائي `bin2hex(random_bytes(32))` (64 حرف hex) — بيتخزن في جدول `refresh_tokens` كـ SHA-256 hash بس (مش plaintext).

---

## 2) `POST /api/v1/auth/register`
**Controller:** `RegisterController::submit`

**Request body:**
```json
{
  "name_ar": "string, required, 2-150 char, حروف عربية",
  "name_en": "string, required, 2-150 char, حروف إنجليزية",
  "email": "string, required, email",
  "password": "string, required, min 8, confirmed (يعني لازم password_confirmation)",
  "role": "string, required — لازم يكون من roleLabels() المتاحة للتسجيل العام فقط",
  "university_id": "int, optional (لو role=student)",
  "faculty_id": "int, optional", "department_id": "int, optional"
}
```
> كل حساب (طالب/جامعة/دكتور/مشرف/أدمن…) لازم يكون له اسم بالعربي `name_ar` وبالإنجليزي `name_en`. عمود `users.full_name` بيتحسب تلقائيًا (حسب لغة الطلب `X-Locale`) للتوافق مع الكود القديم ومبقاش يتبعت من العميل. نفس الحقلين مطلوبين في: `POST /admin/users`، `POST /students`، `POST /academic-staff`، `POST /supervisors`، وتعديل البروفايل (`/students/me`، `/*/settings/profile`). الاستيراد الجماعي بيستخدم عمودي `name_ar` و`name_en`. فشل التحقق بيرجع 422 مع `errors.name_ar` / `errors.name_en`.

**فشل بسبب role غير صالح (422):** `{"success": false, "message": "Please choose a valid account type."}`

---

## 3) `POST /api/v1/auth/logout`
**Controller:** `LogoutController::handle` — Middleware: `CSRFMiddleware` (متجاوَز تلقائيًا لو الطلب فيه `Authorization: Bearer`)

**Request body (اختياري):** `{ "refresh_token": "string, optional" }` — لو موجود بيتم إلغاؤه فورًا من جدول refresh_tokens.

**رد (200):**
```json
{ "success": true, "redirect": "/auth/login" }
```

---

## 4) `POST /api/v1/auth/forgot-password`
**Controller:** `ForgotPasswordController::submit`

**Request:** `{ "email": "string, required, email" }`

**رد (دايمًا 200 — حتى لو الإيميل مش موجود، عشان مايبقاش user enumeration):**
```json
{ "success": true, "message": "If an account with that email exists, a reset link has been sent." }
```

---

## 5) `POST /api/v1/auth/reset-password`
**Controller:** `ResetPasswordController::submit`

**Request:**
```json
{ "token": "string, required", "password": "string, required, min 8, confirmed" }
```

**نجاح (200):** `{ "success": true, "redirect": "/auth/login" }`
**فشل — token غلط/منتهي (422):** `{ "success": false, "message": "This reset link is invalid or has expired." }`
**فشل — password policy (422):** `{ "success": false, "message": "<رسالة السياسة>" }`

---

## 6) `POST /api/v1/auth/refresh-token`
**Controller:** `RefreshTokenController::submit` — بدون Middleware

**Request:** `{ "refresh_token": "string, required" }`

**نجاح (200) — نفس الـ envelope الكامل:**
```json
{
  "success": true, "message": "Token refreshed successfully.",
  "data": { "access_token": "...", "refresh_token": "...", "token_type": "Bearer", "expires_in": 900 },
  "errors": null, "meta": {}
}
```
**فشل (401):**
```json
{ "success": false, "message": "This refresh token is invalid, expired, or has already been used.", "data": null, "errors": null, "meta": {} }
```
⚠️ **Rotation**: كل refresh بيلغي التوكن القديم ويصدر واحد جديد (single-use). لو حد استخدم refresh token اتلغى قبل كده → 401.

---

## 7) `GET /api/v1/auth/verify-email?token=...`
**Controller:** `VerifyEmailController::handle` — بدون Middleware

**نجاح (200):** `{ "success": true, "message": "Email verified successfully.", "data": null, "errors": null, "meta": {} }`
**فشل (422):** `{ "success": false, "message": "This verification link is invalid or has expired.", "data": null, "errors": null, "meta": {} }`

---

## 8) `GET /api/v1/auth/confirm-email-change?token=...`
**Controller:** `ConfirmEmailChangeController::handle` — بدون Middleware

**نجاح (200):**
```json
{ "success": true, "message": "Email address changed successfully.", "data": { "new_email": "..." }, "errors": null, "meta": {} }
```
**فشل (422):** نفس شكل رقم 7 برسالة مختلفة.

---

## 9) `GET /api/v1/auth/roles`
**Controller:** `RoleSelectionController::index` — بدون Middleware، بيانات عامة (reference data)

**رد (200):**
```json
{
  "success": true, "message": "Available roles retrieved successfully.",
  "data": [ { "slug": "student", "label": { "ar": "طالب", "en": "Student" } }, ... ],
  "errors": null, "meta": {}
}
```

---

## 10) `GET /api/v1/auth/universities`
**Controller:** `RegisterController::universities` — بدون Middleware

**رد (200):** `data` = array من `{ "id": int, "name": "string (باللغة الحالية)" }`

---

## 11) `GET /api/v1/auth/university-hierarchy/{id}`
**Controller:** `RegisterController::universityHierarchy` — بدون Middleware

**رد (200) — شكل خام (مش envelope):**
```json
{
  "faculties":   [ { "id": 1, "name": "..." } ],
  "departments": [ { "id": 1, "faculty_id": 1, "name": "..." } ]
}
```

---

## 12) `POST /api/v1/auth/two-factor/verify`
**Controller:** `TwoFactorChallengeController::submit` — Middleware: `CSRFMiddleware`
يعتمد على state في الـ session (`_2fa_pending_user_id`) مش على user id من الـ body.

**Request:** `{ "code": "string", "remember_device": "bool, optional" }`

**نجاح (200):** نفس شكل رد اللوجن الناجح بالظبط (redirect + 4 مفاتيح التوكن) لكن الرسالة `"Two-factor verification successful."`
**فشل — لا يوجد تحدي معلّق (409):** `{"success": false, "message": "No two-factor challenge is pending. Please log in again.", ...}`
**فشل — كود غلط (422):** `errors: {"code": ["Invalid or expired code."]}`
**فشل — الجلسة انتهت بعد التحقق (401)**

---

## 13) `POST /api/v1/auth/two-factor/cancel`
**Controller:** `TwoFactorChallengeController::cancel` — Middleware: `CSRFMiddleware`
**رد (200):** `{ "success": true, "message": "Two-factor challenge cancelled.", "data": null, "errors": null, "meta": {} }`

---

## 14) `GET /api/v1/auth/sessions`
**Controller:** `SessionsController::index` — Middleware: **`AuthMiddleware`** (Bearer token مطلوب)

**رد (200):**
```json
{
  "success": true, "message": "Active sessions retrieved successfully.",
  "data": [
    { "id": 1, "device_label": "Chrome on Windows", "location_label": "...", "ip_address": "...",
      "is_current": true, "last_activity_at": "...", "created_at": "..." }
  ],
  "errors": null, "meta": { "total": 1 }
}
```

## 15) `DELETE /api/v1/auth/sessions/{id}`
**Controller:** `SessionsController::revoke` — Middleware: `AuthMiddleware`
**نجاح (200):** `{ "success": true, "message": "Session revoked successfully.", ... }`
**فشل (404):** `{ "success": false, "message": "Session not found.", ... }` (نفس الرسالة سواء الـ id مش موجود أو ملك حد تاني — عشان الأمان)

---

## الدخول الدائم + حد الأجهزة لكل دور (Admin → Platform Policy)

- **الدخول الدائم** (`persistent_login`، شغّال افتراضيًا): مفيش قفل بسبب الخمول، وقفل المتصفح/التبويب مبيسجّلش خروج (`POST /session/release` بقى بلا أثر). الـ refresh token والكوكي عمرهم سنة (`PERSISTENT_REFRESH_TTL`) وبيتجددوا مع كل استخدام. الخروج بيحصل بس بـ logout صريح، أو إلغاء الجهاز (`DELETE /auth/sessions/{id}` أو بورتال الأمان)، أو لما حد الأجهزة يطرد أقدم جهاز، أو لو الحساب اتوقف/اتحذف. لو الأدمن قفل الخيار بيرجع `JWT_REFRESH_TTL` (14 يوم) ومهلة الخمول.
- تسامح مع ضياع رد الـ refresh: التوكن القديم بيتقبّل 60 ثانية بعد تبديله (migration `prev_token_hash`/`rotated_at`) بدل ما يتعامل كسرقة ويقفل كل الجلسات.
- **حد الأجهزة**: `GET /admin/platform-policy` بيرجّع `data.devices` = `{ default, mode, roles:[{slug,name_ar,name_en,limit,overridden}], max, overridden }`. `PUT /admin/platform-policy/devices` بـ `{ default?, mode?: replace_oldest|block, roles?: { slug: int } }` (الرقم `0` = بلا حد، الحد الأقصى 1,000,000). `DELETE` على نفس المسار بيرجّع الافتراضي (جهاز واحد لكل حساب، أو بلا حد لو `SINGLE_SESSION_ENFORCED=false`). الجهاز = بصمة المتصفح (`uip_did`)، ونفس الجهاز مبيتحسبش مرتين. الحد بيتطبّق على أول تسجيل دخول جديد.
  - `replace_oldest` (الافتراضي): الجهاز الأقدم نشاطًا بيتسجّل منه خروج والجديد بيدخل.
  - `block`: الدخول بيرجّع `409` بـ `data.code = account_in_use` + `data.reason = device_limit` + `data.devices[]` + `takeover_token`؛ صاحب الحساب يقدر يسجّل خروج جهاز عن طريق `POST /auth/session-takeover/release { takeover_token, session_id }` وبعدها يجرّب تاني.

## قاعدة البيانات ذات الصلة

**جدول `users`** (أهم الأعمدة): `id, uuid, full_name, email, phone, password_hash, avatar_path, preferred_language(ar/en), theme_preference(light/dark), status(active/pending/suspended/banned), email_verified_at, last_login_at, last_login_ip, two_factor_enabled, two_factor_secret, two_factor_confirmed_at, two_factor_recovery_codes(JSON), failed_login_attempts, locked_until, lock_permanent, lock_reason, locked_at, mfa_grace_started_at, remember_token, created_at, updated_at, deleted_at`

**جدول `trusted_devices`** (migration 070): `id, user_id, selector(unique), token_hash(sha256), device_label, user_agent, ip_address, created_at, last_used_at, expires_at, revoked_at`

**جدول `security_policies`**: `id, policy_key(unique), category, name_ar, name_en, value, is_active, updated_by, updated_at, created_at` — نفس الجدول اللي `PasswordPolicyService` بيستخدمه، بس بـ `policy_key = 'login.lockout_policy'`.

**جدول `refresh_tokens`**: `id, user_id (FK), token_hash (SHA-256, unique), device_label, ip_address, expires_at, revoked_at, replaced_by_id (FK self), created_at`

---

## ✅ حالة الهجرة (يتحدّث مع كل دفعة)

| Endpoint | الحالة | ملاحظات |
|---|---|---|
| `POST /auth/login` | 🟢 منقول | 2FA + Account Lockout شغالين بالكامل. ناقص: سياسات IP/Country/Device restriction + Concurrent Session Limits + MFA mandatory-policy (بند 25 — Security Portal، مؤجل عن قصد) |
| `POST /auth/refresh-token` | 🟢 منقول | |
| `POST /auth/logout` | 🟢 منقول | |
| `POST /auth/register` | 🟢 منقول | تعقيد كلمة السر شغال بالكامل. ناقص: إرسال إيميل التحقق + StudentJoinRequestService |
| `POST /auth/forgot-password` | 🟡 منقول جزئيًا | ناقص: إرسال إيميل حقيقي (دلوقتي بيسجل في log بس) |
| `POST /auth/reset-password` | 🟢 منقول | تعقيد كلمة السر + منع إعادة استخدام كلمة سر قديمة شغالين بالكامل |
| `GET /auth/verify-email` | 🟢 منقول | |
| `GET /auth/confirm-email-change` | 🟢 منقول | |
| `GET /auth/roles` | 🟢 منقول | |
| `GET /auth/universities` | 🟢 منقول | |
| `GET /auth/university-hierarchy/{id}` | 🟢 منقول | |
| `GET /auth/sessions` | 🟡 منقول جزئيًا | `is_current` دايمًا false — يحتاج تصميم إضافي (شوف README) |
| `DELETE /auth/sessions/{id}` | 🟢 منقول | |
| `POST /auth/two-factor/verify` | 🟢 منقول | Stateless (`challenge_token` بدل session) — شوف الملحوظة تحت |
| `POST /auth/two-factor/cancel` | 🟢 منقول | نفس السبب |

**قاعدة مهمة:** أي endpoint حالته 🟡 أو 🔴 لازم يفضل يوجّه للـ API القديم في طبقة الـ reverse
proxy لحد ما نكمله. **كل الـ 15 endpoint شغالين بالكامل أو شبه كامل دلوقتي.**

### ⚠️ فرق عن العقد الأصلي — `two-factor/verify` و`/cancel`

القديم بيعتمد على PHP session (`_2fa_pending_user_id`) عشان يعرف مين بيكمّل
الـ 2FA. الـ API الجديد **Stateless بالكامل** (JWT بس، من غير session cookie)،
فمفيش حاجة تتخزن على السيرفر بين خطوة اللوجن وخطوة التحقق. البديل:

- `/auth/login` (لما `requires_2fa: true`) بيرجّع `challenge_token` بدل
  `csrf_token` — JWT صالح **5 دقايق بس**، فيه claim خاص (`typ=2fa_challenge`)
  عشان محدش يقدر يستخدمه كـ access token عادي.
- `/auth/two-factor/verify` بقى الـ body بتاعه: `{ "challenge_token", "code", "remember_device" }`
  بدل `{ "code", "remember_device" }` بس.
- `/auth/two-factor/cancel` بقى بيقبل ويرجّع نجاح دايمًا (مفيش حاجة تتلغى
  فعليًا على السيرفر — الـ token هيخلص لوحده).

**ليه القرار ده وليه هو أأمن:** إرسال الـ user_id في الـ body مباشرة كان
هيبقى ثغرة (أي حد يقدر يجرب أكواد 2FA لأي user_id يعرفه). الـ JWT الموقّع
بيمنع كده تمامًا، وهو نفس المبدأ اللي `access_token`/`refresh_token` أصلاً
مبنيين عليه في نفس النظام.

**Account Lockout** شغال بالكامل (checkStatus/registerFailedAttempt/
registerSuccessfulLogin ضد `users.failed_login_attempts` و`locked_until`
و`lock_permanent`، وبيقرا سياسة `security_policies.login.lockout_policy`
بنفس الـ defaults). **الناقص عن قصد:** الآثار الجانبية الإدارية فقط
(audit log، security alert، in-app notification، إيميل تنبيه بالقفل) —
دول جزء من واجهة الـ Security Portal (بند 25)، والقفل نفسه (منع الدخول)
شغال من غيرهم.

---

## متغيرات البيئة المطلوبة (لازم تتطابق بالظبط بين القديم والجديد)
```
JWT_SECRET=<نفس القيمة بالظبط>
JWT_ACCESS_TTL=900
JWT_REFRESH_TTL=1209600
DB_CONNECTION=mysql
DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME / DB_PASSWORD
```
⚠️ **لازم يكون نفس `JWT_SECRET`** لو عايز تسمح بمرحلة انتقالية يفكّ فيها لارافيل توكنات صادرة من النظام القديم (والعكس).

---

## Google Sign-In (ويب + أندرويد)

الفرونت بيجيب Google **ID token** (زرار Google Identity Services في المتصفح، أو Credential Manager في الـ APK) ويبعته للسيرفر، والسيرفر هو اللي بيتحقق منه (التوقيع RS256 بشهادات جوجل، `iss`، `aud` ∈ `GOOGLE_CLIENT_IDS`، `exp`، `email_verified`).

### `GET /api/v1/auth/google/config`
عام. `data: { enabled: bool, client_id: string|null }` — `enabled=false` لو `GOOGLE_CLIENT_IDS` فاضي (الفرونت يخبّي الزرار).

### `POST /api/v1/auth/google`  (throttle 20/min)
Body: `{ "id_token": "..." }`
- **الإيميل (أو حساب جوجل) عنده حساب** → نفس رد `/auth/login` بالظبط (توكنز، أو `requires_2fa` + `challenge_token`)، ومن نفس حراسات اللوجين (`LoginController::finishLogin`): قفل الحساب/الإيقاف، قيود الجهاز، الصيانة، الجلسة الواحدة، 2FA، MFA. أول دخول بيربط الحساب (`user_social_accounts`) ويأكّد الإيميل ويجيب صورة جوجل لو الحساب معندوش صورة. أدوار الستاف (`admin`, `data_analyst`, `security_*`) مبتدخلش بجوجل (422).
- **مفيش حساب** → 200: `data: { needs_registration: true, registration_token, profile: { email, name, picture, name_ar, name_en }, missing: ["name_ar"|"name_en"] }`. `registration_token` JWT قصير (15 دقيقة، `typ=google_signup`) — مفيش أي حاجة اتسجلت لسه.
- توكن غلط/منتهي/aud غلط → 401.

### `POST /api/v1/auth/google/complete`  (throttle 10/min)
Body: `{ registration_token, name_ar, name_en, university_id?, faculty_id?, department_id?, preferred_language? }`
بينشئ حساب **طالب** (`status=active`, `email_verified_at=now`) بإيميل جوجل والاسمين اللي اتأكدوا (`BilingualName`)، وبيحمّل صورة جوجل لـ `avatar_path` (best-effort)، وبيبعت طلب الانضمام للجامعة لو اتحددت، وبعدها نفس رد اللوجين. `registration_token` منتهي → 422 `errors.code = signup_expired`. الحساب بيتعمل له باسورد عشوائي مجهول؛ لو الطالب عايز يدخل بالإيميل كمان يستخدم "نسيت كلمة السر".
