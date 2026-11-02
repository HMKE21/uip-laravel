# Posts backend — الباتش النهائي (جاهز للتجربة قبل الفرونت)

## التركيب
انسخ محتوى `files/` فوق مشروع Laravel بنفس المسارات (20 ملف: 17 جديد + 3 معدّلين).

- **جديد (17):** migrationين، `config/posts.php`، 9 Services (`PostSettings`, `PostVisibility`, `PostMediaService`, `PostNotifier`, `PostService`, `PostInteractionService`, `PostFeedService`, `PostFollowService`, `PostModerationService`)، 3 Controllers (`PostsApiController`, `PostMediaApiController`, `PostsAdminApiController`)، أمرين (`posts:publish-due`, `posts:prune`).
- **معدّل (3):** `routes/api.php` (إضافة بلوك الروتس + 3 imports)، `routes/console.php` (سطرين schedule)، `config/upload.php` (فئة `posts`).

لو ملفاتك المشتركة اتغيرت عن النسخة اللي اشتغلت عليها: متنسخش الـ 3 ملفات دي، طبّق بدالها الباتشات اللي في `patches/`:
```
git apply --3way patches/routes_api.php.patch patches/routes_console.php.patch patches/config_upload.php.patch
```

## التشغيل
```
php artisan migrate        # 2027_03_05_100000 (الجداول) ثم 2027_03_05_100100 (صلاحيتي posts.use / posts.moderate)
php artisan route:list --path=posts
php artisan posts:publish-due
php artisan posts:prune
```
الصلاحية `posts.use` بتتمنح لكل الأدوار، و`posts.moderate` للأدمن فقط (تتعدّل من /admin/roles).

## Endpoints (كلها تحت /api/v1)
- `posts/*` (بصلاحية `posts.use`): config, feed, tags/trending, tags/suggest, people/suggestions, profile/{id}, follow/{id}, follows/{id}/followers|following, authors/{id}/mute, media (upload / file / thumbnail / preview / download / text / link / run-token)، comments/{id}/(replies|like)، وCRUD المنشور + publish/archive/unarchive/pin/unpin/react/reactions/save/repost/share/view/hide/report/comments.
- `posts/media/signed/{id}/{variant}`: رابط موقّع نسبي (بره uip.auth عمدًا، التوقيع + PostVisibility بيتفحصوا جوه) — نفس نمط وسائط الشات.
- `posts-admin/*` (بصلاحية `posts.moderate`): stats, settings (GET/PUT), reports, reports/{id}/resolve, pending, {id}/approve|reject.

## اللي اتعمل في التحقق
- `php -l` على كل الملفات (PHP 8.3): نضيف.
- كل ميثود مذكورة في الروتس موجودة في الكنترولر (ولا فيه ميثود عامة مش متوصّلة بروت).
- كل كلاس/ميثود خارجي بيناديه الكود موجود فعلًا في المشروع وتوقيعه مطابق (`RoleService::primaryRoleFor`, `MessagingService::isAvatarHidden`, `NotificationService::notify`, `FileUploadService::store/absolutePath`, `NotificationRepository::portalsForRole`, `ChunkedUploadService::open/discard`).
- مفيش تعارض في أسماء الجداول مع الموجود، وأنواع الأعمدة متوافقة مع `users.id`.
- إصلاح: تكرار البلاغ على نفس المنشور من نفس المستخدم (الـ unique بيتخطّاه لما comment_id = NULL) بقى بيتفحص في `PostInteractionService::report`.

## ⚠️ اللي ما اتجرّبش
المشروع محتاج PHP 8.4 وبيئتي عليها 8.3 ومفيش وصول لـ Packagist، فـ **migrate والكود ما اتشغّلوش فعليًا**. أول حاجة تتأكد منها عندك: `php artisan migrate` ثم `route:list`، وبعدها جرّب upload لـ PDF وفيديو وملف كود (المرفقات بتعتمد على `pdftoppm`/`ffmpeg`/LibreOffice لو متاحين على السيرفر؛ لو مش موجودين المفروض تتخطّى المعاينة بدون ما تكسر الرفع).
