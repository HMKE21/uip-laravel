<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meetings & Collaboration Platform — Round 9 (Recording): بند 18
 * ("Meeting Recording" — Audio/Video/Screen-share recording, Recording
 * status, Recording processing, Recording storage, Recording playback,
 * Recording access permissions).
 *
 * "Design the system to support meeting recording" + "Client-Side
 * recording as a practical temporary solution (without SFU)" (نص خطة
 * الـ Round، مش المواصفة نفسها) — يعني مفيش سيرفر-سايد transcoding
 * pipeline حقيقي هنا (ده يحتاج SFU/media server، خارج نطاق البند)،
 * التسجيل بيحصل في المتصفح نفسه (MediaRecorder API) والملف النهائي
 * بيتاپلود دفعة واحدة وقت stop() — نفس فكرة meeting_files بالظبط بس
 * مع status lifecycle إضافي (بند "Recording processing" + "Use
 * asynchronous processing where appropriate"):
 *   recording -> processing -> completed
 *                            -> failed
 *
 * kind: نوع المحتوى المسجّل (audio/video/screen_share) — "architecture
 * should allow" الثلاثة كأنواع منفصلة، مش بالضرورة مسجّلين في نفس
 * الوقت؛ كل صف هنا تسجيلة واحدة من نوع واحد.
 *
 * بند "Recording access permissions" + "Do not expose recordings to
 * unauthorized users": اتنفّذت في الكود (MeetingsRecordingsApiController/
 * MeetingRecordingService)، مش عمود جديد هنا — نفس مبدأ meeting_files
 * (canView للقراءة، canManage للتحكم في التسجيل نفسه).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('meeting_recordings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();

            $table->string('kind', 20)->default('video'); // audio|video|screen_share

            $table->string('started_by_key', 40);
            $table->string('started_by_display_name', 150);

            // recording: لسه في المتصفح، الملف لسه مالوصلش السيرفر.
            // processing: الملف وصل ووقف upload()، لسه بننهي (finalize) —
            // بند "Use asynchronous processing where appropriate"، عبر
            // ProcessMeetingRecordingJob (queued).
            // completed / failed: النهاية.
            $table->string('status', 20)->default('recording');

            $table->timestamp('started_at');
            $table->timestamp('stopped_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();

            // metadata الملف الفعلي — فاضية لحد ما stop() يتم برفع الملف
            // (زي meeting_files، عبر FileUploadService الموجود).
            $table->string('original_name')->nullable();
            $table->string('stored_path')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            $table->text('failure_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['meeting_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_recordings');
    }
};
