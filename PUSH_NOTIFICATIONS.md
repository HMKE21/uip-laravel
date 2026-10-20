# Push للموبايل (Firebase Cloud Messaging)

كل إشعار بيتسجّل في المنصة (`NotificationService::notify()`) بيتبعت كمان Push لأجهزة صاحبه، حتى لو التطبيق مقفول.
بيشتغل بعد ما الرد يتبعت (`dispatchAfterResponse`) فمش محتاج queue worker، وأي فشل في Firebase بيتسجّل في اللوج بس.
بيحترم كتم الفئات وساعات الهدوء من إعدادات الإشعارات؛ الأولوية `high`/`urgent` بتتخطاهم.

## الإعداد (مرة واحدة)

**الطريقة الأسهل (من غير .env):** Admin ← Platform Policy ← **Push notifications (Firebase)** ← الصق محتوى ملف الـ service account كله ← Save. بيتخزّن مشفّر في الداتابيز ويشتغل فورًا من غير redeploy، وبياخد الأولوية على الـ `.env`. (الـ Project ID اختياري.)

**أو من الـ .env:**
1. Firebase Console → مشروع `uip-v1` → ⚙️ Project settings → **Service accounts** → **Generate new private key**.
2. ارفع ملف الـ JSON على السيرفر **برّه** مجلد `public` (مثلًا `storage/app/firebase-service-account.json`) وماترفعوش على GitHub.
3. في `.env`:
   ```
   FCM_CREDENTIALS_PATH=/المسار/الكامل/firebase-service-account.json
   FCM_PROJECT_ID=uip-v1
   ```
   (لو الاستضافة مفيهاش ملفات: حط محتوى الملف أو base64 بتاعه في `FCM_CREDENTIALS_JSON`.)
4. تأكد إن **Firebase Cloud Messaging API (V1)** مفعّلة في Google Cloud للمشروع (بتبقى مفعّلة افتراضيًا).
5. `php artisan migrate` (بيعمل جدول `push_devices`) ثم `php artisan config:clear`.

## التجربة
1. ابني APK جديد من الـ React (`npm install` ثم `npm run android:sync`).
2. افتح التطبيق، سجّل دخول، ووافق على صلاحية الإشعارات.
3. `php artisan push:test <user_id>` — بيقولك الإعداد سليم ولا لأ وبيبعت إشعار تجريبي.
4. اقفل التطبيق خالص وجرّب تاني: لازم الإشعار يظهر في شريط الإشعارات، والضغط عليه يفتح لينكه.

## ملاحظات
- التوكن بيتسجّل عند تسجيل الدخول وبيتمسح عند تسجيل الخروج؛ لو الجهاز اتنقل لحساب تاني الصف بيتنقل لليوزر الجديد.
- توكنات الأجهزة اللي اتمسح منها التطبيق بتتشال تلقائيًا أول ما Firebase يرفضها.
- بعض موبايلات (Xiaomi/Oppo/Vivo/Huawei) بتأخّر أو بتمنع إشعارات التطبيقات المقفولة: من إعدادات الموبايل شغّل Autostart وشيل قيود البطارية عن التطبيق.
