الاستوري (Status) — الباك إند
=================================
انسخ الملفات دي فوق مشروع Laravel بنفس المسارات (نفس شكل الفولدرات):

جديد:
  database/migrations/2027_01_14_100000_create_stories_tables.php
  app/Services/StoryService.php
  app/Http/Controllers/Api/StoriesApiController.php
  app/Console/Commands/StoriesPrune.php
معدّل (إضافة بس، مفيش حاجة اتشالت):
  routes/api.php      -> سطر use + 10 routes جوّه مجموعة /messaging (بعد /inbox)
  routes/console.php  -> تعليق إرشادي لأمر التنظيف

بعد النسخ:
  php artisan migrate
  (اختياري) crontab كل ساعة:  php artisan stories:prune

Endpoints (كلها تحت /api/v1/messaging وبنفس صلاحية messaging.use):
  GET    /stories                 الفيد { me, groups[], reactions[], limits }
  POST   /stories                 نشر (multipart): type=text|image|video, body | media, caption, bg, font,
                                  audience=contacts|everyone|selected|except, audience_ids[], expires_hours=6|12|24|48, allow_replies
  GET    /stories/contacts?q=     ناس تختارهم للجمهور
  DELETE /stories/{id}            حذف (صاحبها بس)
  POST   /stories/{id}/view       تسجيل مشاهدة
  GET    /stories/{id}/viewers    مين شاف (صاحبها بس)
  POST   /stories/{id}/react      emoji (فاضي = شيل التفاعل)
  POST   /stories/{id}/reply      body — بيوصل كرسالة في الشات المباشر مع metadata.story_reply
  POST|DELETE /stories/mute/{userId}   كتم / إلغاء كتم

إعدادات اختيارية في config/messaging.php تحت مفتاح 'stories' (القيم الافتراضية شغالة من غيرها):
  max_text=700, max_caption=300, max_video_seconds=90, max_active=30, max_image_kb=10240, max_video_kb=65536
