<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بند "Integrated Meeting & Collaboration Platform" (UIP___Integrated_
 * Meeting___Collaboration_Platform.md) — Round 1 (Foundation) بس:
 * meetings, meeting_participants, meeting_invitations. لسه من غير أي
 * حاجة خاصة بالـ WebRTC/signaling نفسه (ده Round 3-4)، من غير
 * chat/reactions/polls/files (Round 5-7)، من غير bulk invite بالـ
 * role/group/project أو التقويم (Round 8 — بيوسّع meeting_invitations
 * بعمود جديد فقط، additive).
 *
 * نفس قرارات exam_system_foundation/group_hub بالظبط: id() bigIncrements
 * عادي، foreignId()->constrained('users') على جدول users القديم اللي
 * موجود فعلًا في التطبيق الحقيقي (وفي بيئة الاختبار عبر
 * 2020_01_01_000000_create_test_support_tables.php).
 *
 * host_user_id: أي مستخدم مسجّل بأي دور (student/faculty/...)
 * يقدر يستضيف اجتماع — النظام ده مش مقصور على دور معيّن زي exam-system
 * (اللي مقصور على academic_staff)، فمفيش عمود "created_by_academic_staff_id"
 * هنا، host_user_id بيتاخد من uip_user_id في التوكن مباشرة (نفس منطق
 * "الخدمة هي اللي بتشتقه، مش العميل" اللي معمول بيه في كل حتة تانية).
 *
 * uuid + meeting_code + join_token: 3 معرّفات مختلفة الغرض عمدًا (بند
 * "توليد الرابط/الـ ID الآمن"):
 *   - uuid: المعرّف العام للـ API (الروابط الداخلية زي /meetings/{uuid})
 *     بدل تسريب الـ id التسلسلي (نفس سبب وجود users.uuid أصلًا).
 *   - meeting_code: رقم قابل للنطق/الكتابة يدويًا (زي Zoom Meeting ID)
 *     يتقال بالصوت أو يتكتب — قصير عمدًا، مش سري لوحده.
 *   - join_token: توكن طويل عشوائي (32 حرف hex) هو اللي بيدخل فعليًا في
 *     رابط الانضمام المباشر (/join/{join_token}) — ده اللي بيمنع تخمين
 *     رابط اجتماع حد تاني، meeting_code وحده مش كافي كحماية.
 *
 * password_hash/waiting_room_enabled/allow_guests/max_participants/settings:
 * أعمدة إعداد (schema) بس هنا — منطق الـ lobby/waiting-room/guest-access
 * الفعلي هو Round 2، لكن تأجيل إضافتهم كعمود لبعدين كان هيحتاج migration
 * تانية تعدّل جدول شغال بالفعل، فاتحطوا هنا زي باقي أعمدة meetings
 * الأساسية (نفس قرار "كل أعمدة exams المعروفة اتحطت في migration
 * التأسيس" في exam_system_foundation).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('host_user_id')->constrained('users')->cascadeOnDelete();

            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->enum('type', ['instant', 'scheduled'])->default('instant');

            // scheduled: لسه ما بدأش. lobby: بدأ لكن لسه في انتظار الهوست
            // (Round 2). live: جاري فعليًا (Round 4 هيبقى فيه اتصال حقيقي
            // ورا الحالة دي). ended/cancelled: نهائية.
            $table->enum('status', ['scheduled', 'lobby', 'live', 'ended', 'cancelled'])->default('scheduled');

            $table->timestamp('scheduled_start_at')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();

            $table->string('meeting_code', 20)->unique();
            $table->string('join_token', 64)->unique();
            $table->string('password_hash')->nullable();

            $table->boolean('waiting_room_enabled')->default(true);
            $table->boolean('allow_guests')->default(false);
            $table->unsignedInteger('max_participants')->nullable();
            $table->json('settings')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['host_user_id', 'status']);
            $table->index(['status', 'scheduled_start_at']);
        });

        Schema::create('meeting_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->enum('role', ['host', 'co_host', 'participant'])->default('participant');
            $table->enum('status', ['invited', 'joined', 'left', 'removed', 'declined'])->default('invited');
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();

            $table->timestamps();

            $table->unique(['meeting_id', 'user_id']);
            $table->index(['meeting_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('meeting_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->foreignId('invited_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('invited_user_id')->constrained('users')->cascadeOnDelete();

            // توكن قبول مستقل عن join_token بتاع الاجتماع نفسه — بيسمح
            // بإلغاء دعوة واحدة (expire/cancel) من غير ما يأثر على رابط
            // الانضمام العام للاجتماع أو باقي الدعوات.
            $table->string('token', 64)->unique();
            $table->enum('status', ['pending', 'accepted', 'declined', 'expired', 'cancelled'])->default('pending');
            $table->string('message', 500)->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('responded_at')->nullable();

            $table->timestamps();

            $table->unique(['meeting_id', 'invited_user_id']);
            $table->index(['invited_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_invitations');
        Schema::dropIfExists('meeting_participants');
        Schema::dropIfExists('meetings');
    }
};
