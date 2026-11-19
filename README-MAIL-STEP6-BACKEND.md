# UIPE Mail — المرحلة 6 (Backend): Developer Center والربط

## بعد فك الـ zip فوق المشروع
```
php artisan migrate        # 2027_11_23_100000_create_developer_center (12 جدول)
php artisan route:clear && php artisan config:clear
php artisan test --filter=DeveloperCenterTest
```
الـ scheduler لازم شغال (`schedule:run` كل دقيقة) لإعادة محاولات الـ Webhooks: `php artisan dev:maintenance`.
متغيرات اختيارية في `.env`: `DEV_CENTER_ENABLED, DEV_MAX_APPS, DEV_MAX_KEYS, DEV_RATE_PER_MIN, DEV_WEBHOOK_ALLOW_HTTP, DEV_WEBHOOK_ALLOW_PRIVATE (للتطوير المحلي بس), DEV_LOG_DAYS`.

## الـ endpoints
**Developer Center (JWT المنصة)** — `/api/v1/developer/*`: `overview`، `apps` (CRUD + rotate-secret + status)، `keys`، `grants`، `webhooks` (+ test / deliveries / redeliver / rotate-secret)، `sandbox/*`، `logs`، و`oauth/authorize` (GET = بيانات شاشة الموافقة، POST `{allow, mail_account_id}` = القرار → `redirect_to`).
**OAuth العام** — `POST /api/oauth/token` و`/api/oauth/revoke` (Authorization Code + PKCE S256 / Refresh بتدوير / Client Credentials).
**Developer API** — `/api/dev/v1/*` بـ `Authorization: Bearer uipk_… | uipo_…`: `me`، `mail/*` (messages/threads/drafts/labels/folders)، `mail/messages/{id}/share-chat | meeting | save-drive`، `mail/ai/{action}`، `notifications`، `webhooks`، `sandbox/*`. مفتاح `uipk_test_…` (mode=sandbox) بيشتغل على صندوق وهمي.
**إدارة** — `/api/v1/dev-admin/*` (جامعة/كلية للقراءة/أدمن المنصة): `policy`، `apps` (+ suspend/resume)، `logs`.
**ربط البريد** — `/api/v1/mail/messages/{id}/links | share-chat | meeting | save-drive`، `mail/ai/{action}`، `mail/ai/status`، `mail/notification-prefs`.

## الأمان
أسرار/مفاتيح/توكنز hash بس · الـ secret بيظهر مرة واحدة · Refresh بتدوير مع كشف إعادة الاستخدام · SSRF guard للـ Webhooks · توقيع HMAC (`ts.body`) · rate limit لكل مفتاح · الرسائل السرّية ممنوعة من الـ API والربط · سياسة الجامعة تقدر تقفل الـ apps/webhooks وتقصر الـ scopes · `/api/dev/*` و`/api/oauth/*` مستثناة من تشفير الرد ومن CORS allowlist (Bearer فقط).
