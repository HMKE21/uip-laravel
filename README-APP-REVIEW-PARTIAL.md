# App Review — الباك اند (جزء مؤقت، مش جاهز للنشر لوحده)

## اللي في الـ zip
- `database/migrations/2027_11_26_100000_dev_app_review.php` (أعمدة المراجعة + review_mode + جدول سجل التدقيق + grandfather للتطبيقات الحالية)
- `app/Services/Developer/DevAppReview.php` (منطق القرار النقي)
- `app/Services/Developer/DevAccessService.php` (review_mode في السياسة + reviewModeFor)
- `app/Services/Developer/DeveloperAppService.php` (إرسال/سحب/قرار/إبطال تلقائي/أثر تغيير الوضع)

## لسه ناقص (مش موجود في الـ zip ده)
- الـ enforcement في OAuthService وDeveloperAuthService (من غيره التطبيقات مش بتتمنع فعليًا)
- الـ controllers والـ routes
- الاختبارات

## بعد الفك
php artisan migrate
