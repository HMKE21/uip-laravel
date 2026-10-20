<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بند "UIP Meetings" — Round 7 (Collaboration Extras): بند 17
 * (Attendance Tracking)، بند 19 (File Sharing)، بند 20 (Meeting
 * Notes)، بند 21 (Polls). ست جداول جديدة بالكامل — نفس فلسفة migration
 * 2026_08_31_060000 (الشات): كيانات جديدة مالهاش وجود قبل كده، فمفيش
 * حاجة تتعدّل على meetings/meeting_participants الموجودين.
 *
 * actor key موحّد ('user:5'/'guest:12') برضو هنا في كل مكان بدل foreign
 * key حقيقي على users — نفس سبب الشات بالظبط (الضيف بند 23 لازم يقدر
 * يشارك ملف/يصوّت/يتسجّل حضوره، ومالوش صف في users أصلًا).
 *
 * --- meeting_attendance_sessions (بند 17) ---
 * صف واحد لكل "جلسة حضور" مستقلة (دخول→خروج)، مش صف واحد ثابت لكل
 * شخص زي meeting_participants.joined_at/left_at — عشان لو حد خرج ورجع
 * (بند 17 صراحة عايز "Number of joins"/"Number of leaves" منفصلين عن
 * بعض، مش قيمة واحدة بس). left_at=null يعني الجلسة لسه شغالة (مقفولة
 * عند الخروج الفعلي أو نهاية الاجتماع — راجع docblock
 * MeetingAttendanceService::recordJoin()/closeAllOpenSessions()).
 *
 * --- meeting_files (بند 19) ---
 * meetadata بس هنا — الملف الفعلي على القرص عبر FileUploadService
 * الموجود بالفعل (فئة 'meetings' جديدة في config/upload.php، نفس
 * الفاليديشن/الـ content-sniffing/الـ ClamAV hook القياسي، بدل ما
 * نعيد اختراعه). soft-delete (مش hard) عشان تاريخ "مين رفع ومين مسح"
 * يفضل موجود في التقارير حتى بعد الحذف.
 *
 * --- meeting_notes / meeting_action_items (بند 20) ---
 * جدولين منفصلين: meeting_notes صف واحد لكل اجتماع (singleton — الأجندة
 * + الملاحظات الحرة كنص واحد تعاوني)، meeting_action_items صفوف متعددة
 * للعناصر البنيوية اللي المثال في المواصفة وضحها بالظبط (Decision/
 * Action Item/Deadline) — كل واحد منهم بيحتاج حقول منظمة (type/
 * due_at/status) مش نص حر زي الأجندة.
 *
 * --- meeting_polls / meeting_poll_options / meeting_poll_votes (بند 21) ---
 * جدول أسئلة + جدول اختيارات + جدول أصوات منفصلين (زي أي استفتاء
 * قياسي). is_anonymous بيتحكم في العرض بس (Service مش بيرجّع
 * voter_display_name في النتائج لو true) — voter_key نفسه لازم يتخزن
 * دايمًا (حتى لو anonymous) عشان نمنع نفس الشخص يصوّت مرتين على نفس
 * الاختيار (unique constraint تحت).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('meeting_attendance_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();

            $table->string('participant_key', 40);
            $table->string('display_name', 150);

            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();

            $table->timestamps();

            $table->index(['meeting_id', 'participant_key']);
        });

        Schema::create('meeting_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();

            $table->string('uploader_key', 40);
            $table->string('uploader_display_name', 150);

            $table->string('original_name', 255);
            $table->string('stored_path', 500);
            $table->string('extension', 10);
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('size_bytes');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['meeting_id', 'id']);
        });

        Schema::create('meeting_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->unique()->constrained('meetings')->cascadeOnDelete();

            $table->longText('body')->nullable();

            $table->string('last_edited_by_key', 40)->nullable();
            $table->string('last_edited_by_display_name', 150)->nullable();
            $table->timestamp('last_edited_at')->nullable();

            $table->timestamps();
        });

        Schema::create('meeting_action_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();

            $table->enum('type', ['decision', 'action_item', 'task'])->default('task');
            $table->string('title', 500);
            $table->string('assignee_display_name', 150)->nullable();
            $table->timestamp('due_at')->nullable();
            $table->enum('status', ['open', 'done'])->default('open');

            $table->string('created_by_key', 40);
            $table->string('created_by_display_name', 150);

            $table->timestamps();

            $table->index(['meeting_id', 'id']);
        });

        Schema::create('meeting_polls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();

            $table->string('created_by_key', 40);
            $table->string('created_by_display_name', 150);

            $table->string('question', 500);
            $table->enum('poll_type', ['single_choice', 'multiple_choice'])->default('single_choice');
            $table->boolean('is_anonymous')->default(false);
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->index(['meeting_id', 'id']);
        });

        Schema::create('meeting_poll_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('meeting_polls')->cascadeOnDelete();

            $table->string('option_text', 300);
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();
        });

        Schema::create('meeting_poll_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('meeting_polls')->cascadeOnDelete();
            $table->foreignId('option_id')->constrained('meeting_poll_options')->cascadeOnDelete();

            $table->string('voter_key', 40);
            // بند 21 — "Anonymous poll": العمود ده لسه بيتخزن دايمًا (راجع
            // docblock الملف)، بس MeetingPollService::results() ميرجّعوش
            // لو $poll->is_anonymous.
            $table->string('voter_display_name', 150);

            $table->timestamps();

            // صوت واحد بس لكل (استفتاء، اختيار، شخص) — نفس منطق reaction
            // toggle بالظبط. الفرق بين single/multiple choice (شخص واحد
            // مايختارش أكتر من اختيار في نفس الاستفتاء لو single) اتفرض
            // في الـ Service (بيمسح صوته القديم قبل ما يسجّل الجديد)، مش
            // constraint على مستوى الداتابيز.
            $table->unique(['poll_id', 'option_id', 'voter_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_poll_votes');
        Schema::dropIfExists('meeting_poll_options');
        Schema::dropIfExists('meeting_polls');
        Schema::dropIfExists('meeting_action_items');
        Schema::dropIfExists('meeting_notes');
        Schema::dropIfExists('meeting_files');
        Schema::dropIfExists('meeting_attendance_sessions');
    }
};
