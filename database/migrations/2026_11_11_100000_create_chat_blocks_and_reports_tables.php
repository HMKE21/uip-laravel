<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الشات: حظر المستخدمين (user_blocks) + بلاغات الرسائل/المستخدمين (chat_reports).
 *
 * chat_reports.university_id / faculty_id = جهة المبلَّغ عنه (أو المُبلِّغ لو المبلَّغ عنه
 * مالوش جهة) — دي اللي بتحدد مين من الجامعة/الكلية يشوف البلاغ. الأدمن بيشوف الكل.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_blocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('blocker_user_id');
            $table->unsignedBigInteger('blocked_user_id');
            $table->dateTime('created_at')->useCurrent();
            $table->foreign('blocker_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('blocked_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['blocker_user_id', 'blocked_user_id'], 'uq_user_blocks_pair');
            $table->index(['blocked_user_id'], 'idx_user_blocks_blocked');
        });

        Schema::create('chat_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reporter_user_id');
            $table->unsignedBigInteger('reported_user_id');
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->string('reason', 40);
            $table->text('details')->nullable();
            // نسخة من نص الرسالة وقت البلاغ — دليل يفضل حتى لو الرسالة اتحذفت أو اتعدلت.
            $table->text('message_snapshot')->nullable();
            $table->unsignedBigInteger('university_id')->nullable();
            $table->unsignedBigInteger('faculty_id')->nullable();
            $table->string('status', 20)->default('pending'); // pending | reviewing | resolved | dismissed
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->foreign('reporter_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('reported_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('conversation_id')->references('id')->on('conversations')->onDelete('set null');
            $table->foreign('message_id')->references('id')->on('messages')->onDelete('set null');
            $table->foreign('reviewed_by')->references('id')->on('users')->onDelete('set null');
            $table->index(['status', 'created_at'], 'idx_chat_reports_status');
            $table->index(['university_id'], 'idx_chat_reports_university');
            $table->index(['faculty_id'], 'idx_chat_reports_faculty');
            $table->index(['reporter_user_id'], 'idx_chat_reports_reporter');
            $table->index(['reported_user_id'], 'idx_chat_reports_reported');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_reports');
        Schema::dropIfExists('user_blocks');
    }
};
