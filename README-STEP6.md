# الخطوة 6 — Failover التلقائي (نسخة جزئية، متكتبة من غير تشغيل PHP)

## اللي اتعمل
- `SfuHealth` (جديد): circuit breaker لكل مزوّد. 3 فشل متتالي من السيرفر (مفيش اتصال/502/503/504/401) = المزوّد واقع 30 ثانية (بيتضاعف لحد 5 دقايق). رد 500 مابيتحسبش فشل.
- `SfuManager`: `sfu_provider` بقى "المفضّل"، والاختيار الفعلي per-room. `choose()` دالة نقية.
- `MeetingTransportService::providerFor()`: كل غرفة متثبّتة على مزوّد؛ لو وقع الغرفة بتتحوّل للتاني، الـ registry بيتمسح، epoch بيزيد، وبيتبث `meeting.transport.changed` فيه `provider` و`reason=sfu_failover`. الاتنين واقعين = mesh.
- فشل عند عملاء الغرفة (sfu_failed): بيجرّب المزوّد التاني مرة، لو فشل كمان → mesh زي الأول.
- الاجتماعات الجديدة بترجع للمفضّل لما يتعافى؛ الشغّالة مابتتحركش.
- `php artisan sfu:health` + schedule كل دقيقة (يفحص كل 10 ثواني). `--once` للتجربة.
- أدمن: `GET /admin/meetings/control/sfu/health` و`POST .../sfu/health/reset {provider}`؛ إعداد جديد `sfu_failover`.
- ملفات: config/webrtc.php، Services (SfuHealth, SfuManager, SelfHosted, Cloudflare, MeetingTransport, MeetingAdminSettings)، Event، 3 Controllers، routes/api.php، routes/console.php، Command، tests/Unit/SfuManagerChooseTest.php، .env.example.

## لسه / ملاحظات
- ما اتجرّبش على سيرفر ولا اتعمل `php -l` (مفيش PHP عندي). شغّل `php artisan test` و`php artisan sfu:health --once` الأول.
- لازم الـ scheduler شغّال (`schedule:work` أو cron) عشان الفحص الدوري.
- Failover لـ Cloudflare محتاج `sfu_enabled` + CF App ID/Secret متظبطين.
- ملاحظة: عميل الاجتماع نفسه (mediasoup-client) لسه مش مكتوب؛ لما يتكتب لازم يسمع `provider` في الحدث ويفتح session جديدة لما `reason=sfu_failover`.
- اتضاف label الفرونت (`src/components/admin/meetingsControl/shared.jsx`) لـ `sfu_provider` و`sfu_failover`.
- إصلاح: زرار "اختبار الاتصال" كان بيكتب "الاتصال بـ Cloudflare شغّال" حتى لو المفضّل mediasoup (النص كان ثابت في SettingsTab.jsx). دلوقتي الباك إند بيرجّع `provider` والفرونت بيعرض رسالة سيرفرنا. ملف: src/components/admin/meetingsControl/SettingsTab.jsx.
