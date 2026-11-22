# Course video library + lecturer template (backend)

Copy these files over the project (same paths), then: `php artisan migrate`.

## New
- app/Services/CourseVideoService.php
- app/Http/Controllers/Api/CourseVideosApiController.php
- database/migrations/2028_01_10_100000_create_course_videos_tables.php  (course_videos, course_video_views)

## Changed
- app/Services/ChunkedUploadService.php   (init(..., purpose='course_video'), open(..., purpose), status/init now return name+size)
- app/Services/Tenancy/TenantSiteTemplates.php   (new template key: `lecturer`)
- config/upload.php   (category course_videos [private], course_video_* settings)
- routes/api.php   (videos routes under /courses/{id}/videos + signed stream route)

## API  (/api/v1/courses/{id}/videos ...)
GET    /videos                         list (student: published + my_progress; staff: all + viewers_count/completed_count/avg_percent)
POST   /videos/uploads                 init resumable upload {name,size,mime} -> {upload_id, chunk_bytes, total_chunks}
GET    /videos/uploads/{uid}           status -> {received:[idx..], complete}  (resume = send only missing indexes)
POST   /videos/uploads/{uid}/chunk     multipart {index, chunk}
DELETE /videos/uploads/{uid}           cancel
POST   /videos                         {upload_id,title,description?,password?,status?} after complete
PATCH  /videos/{vid}                   title|description|status|sort_order|password|remove_password
DELETE /videos/{vid}
POST   /videos/{vid}/play              {password?} -> {url, expires_in, resume_position, duration_seconds, tracking}
                                       423 = password required, 403 = wrong password
POST   /videos/{vid}/progress          {ranges:[[s,e],..], position, duration?, started?}  (students; send every ~15s + on pause/close)
GET    /videos/{vid}/viewers           staff: every enrolled student + percent/seconds/completed/last_watched_at
GET    /videos/report                  staff: students x videos matrix (per_video = {video_id: percent})
GET    /courses/videos/stream/{id}?... signed URL from /play (supports Range)

## Settings (.env, all optional)
UPLOAD_COURSE_VIDEO_CHUNKED_MAX_MB=0   (0 = unlimited)
UPLOAD_COURSE_VIDEO_CHUNK_MB=2
UPLOAD_COURSE_VIDEO_URL_TTL=7200
COURSE_VIDEO_COMPLETE_PCT=90

Assignments, submissions, grades and meetings/screen share already exist (CourseAssignmentService / CourseGradebookService / meetings) — not touched.
