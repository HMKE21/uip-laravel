# بند 2 — مركز التحقيق في حساب مستخدم (Account Investigation Center)

انسخ `laravel/` و`react/` فوق المشروعين (نفس المسارات). **مفيش migration جديدة** — بيقرأ من الجداول الموجودة (login_events, user_sessions, security_alerts, security_incidents, trusted_devices, passkeys, …) ومن جداول بند 1 لو موجودة (بيتجاهلها بأمان لو مش موجودة).

الملفات المشتركة مع بند 1 (routes/api.php, BlockedIpRepository.php, App.jsx, navConfig.js, tenantFeatureMap.js, index.css, SecurityDetection.jsx) جاية بنسختها الكاملة المحدّثة — اكتب فوق القديمة.

## الصفحة
`/security/investigate` (بحث) ثم `/security/investigate/:id`. ظاهرة في القايمة باسم "Investigate Account"، وفيه روابط لها من اسم المستخدم في صفحة Sessions وصفحة Alerts.
- الهيدر: الأدوار، الحالة، قفل؟، MFA؟، **مستوى الخطورة (0–100) بعوامل مفسّرة** (تنبيهات مفتوحة، فشل دخول 24 س، تفعيلات قواعد الكشف، حوادث مفتوحة، حساب إداري بدون MFA، جلسات من IPs/أماكن متعددة، جهاز جديد).
- تبويبات: نظرة عامة · الجلسات (مع إنهاء جلسة واحدة) · عمليات الدخول · الأجهزة والـ IPs (مع "حسابات أخرى دخلت من نفس الـ IP" وزر حظر) · التنبيهات والحوادث وتفعيلات القواعد وسجل الأمان · MFA والأجهزة الموثوقة.

## الإجراءات السريعة (كلها بتتسجّل في audit + security log)
| الإجراء | مين يقدر |
|---|---|
| إنهاء كل الجلسات (يلغي كمان الأجهزة الموثوقة والـ refresh tokens) | security_officer / security_admin / admin |
| قفل حساب (سبب إلزامي + مدة، وبيقفل الجلسات اختياريًا) / فتحه | security_admin / admin |
| إعادة ضبط MFA (TOTP + recovery + passkeys + أجهزة موثوقة) | security_admin / admin |
| طلب تغيير كلمة المرور | security_admin / admin |
| حظر IP (من تاريخ الحساب بس، مؤقت أو دائم) | security_admin / admin + صلاحية security.ip.manage |

حمايات: محدش يقفل/يصفّر MFA لحسابه هو · حسابات admin و security_admin: admin بس هو اللي يقدر يقفلها أو يصفّر MFA بتاعها · حظر IP بيرفض العناوين الخاصة وعنوانك أنت وأي IP مش في تاريخ الحساب.

## ملاحظات
- مفيش session_token ولا device_hash كامل ولا two_factor_secret بيطلعوا للفرونت (أول 8 حروف من الـ hash للمقارنة بين الأجهزة بس).
- ما اتعملش اختبار تشغيل — اتفحص syntax فقط (php -l و esbuild).
