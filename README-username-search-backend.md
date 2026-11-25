# Backend: @username + بحث الناس (uip-laravel)

بعد النسخ: `php artisan migrate` ثم `php artisan route:clear`.

## ملفات جديدة
- database/migrations/2028_03_01_100000_add_username_and_people_search.php
- app/Services/UsernameService.php
- app/Http/Controllers/Api/UsernameApiController.php
- app/Http/Controllers/Api/GlobalSearchApiController.php

## ملفات معدّلة
routes/api.php, NetworkService, NetworkApiController, MessagingController, MessagingService, UserRepository, ConversationRepository

## Endpoints
GET  /api/v1/search?q=&types=people,projects,universities&limit=8&page=1&role=&relation=
GET  /api/v1/username                    -> {username, changed_at, can_change_at}
GET  /api/v1/username/check?u=hmke       -> {available, code, message, username}
GET  /api/v1/username/suggest            -> ["hmke","hmke_42",...]
PUT  /api/v1/username {username}         ("" = مسح)
DELETE /api/v1/username
GET  /api/v1/username/lookup/{handle}    -> كارت الشخص (نفس /network/people/{id}) — لضغطة @username في الشات
POST /api/v1/messaging/conversations/direct {recipient_username | recipient_email | recipient_id, body}
GET/PATCH /api/v1/network/settings       + email_searchable, discoverable
