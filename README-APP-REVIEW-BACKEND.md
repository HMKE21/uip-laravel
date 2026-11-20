# App Review — الباك اند (كامل)

## بعد الفك
php artisan migrate   # بيعلّم التطبيقات الحالية approved (grandfather) فمفيش تكامل شغال هيقف

## اللي اتضاف
- Enforcement: OAuthService (authorize + code/refresh) و DeveloperAuthService (التوكنز → 403 app_not_approved). صاحب التطبيق مستثنى دايمًا.
- مطوّر: GET/POST/DELETE /api/v1/developer/apps/{id}/review (حالة / إرسال بمبرر 10-500 حرف / سحب)
- إدارة: POST /api/v1/dev-admin/apps/{id}/review {approve, note, fingerprint} — الموافقة لازم fingerprint من تفاصيل التطبيق (review.fingerprint)، والرفض لازم note
- إدارة: GET /api/v1/dev-admin/review/impact?review_mode=off|sensitive|all|inherit (معاينة الأثر)
- السياسة: review_mode في PUT /dev-admin/policy (+ effective_review_mode في GET)، وفلتر ?review= في قائمة التطبيقات، وreviews_pending في summary
- authorizeInfo بيرجّع app.verified
- tests/Feature/DevAppReviewTest.php (منطق نقي، بدون DB)

## ملاحظة
الاختبارات والـ routes ماتشغّلتش عندي (لسه مفيش vendor)، اتعمل php -l بس. شغّل: php artisan test --filter=DevAppReview ثم php artisan route:list --path=review
