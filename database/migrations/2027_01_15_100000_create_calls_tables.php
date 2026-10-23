<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المكالمات 1:1 (زي واتساب/ماسنجر).
 *
 *  calls         المكالمة نفسها + حالتها: ringing | active | ended | declined | missed | cancelled | busy | failed
 *  call_signals  رسائل الـ signaling المؤقتة (offer/answer/ice) — بتتقري بـ polling وبتتمسح مع المكالمة (calls:prune)
 *
 * caller_client / callee_client = معرّف التبويب اللي بدأ/ردّ على المكالمة (نفس المستخدم ممكن يكون فاتح أكتر من تبويب
 * أو جهاز: واحد بس يرد، والباقي بيقفل الرنّة). *_seen_at = آخر نبضة (heartbeat) من كل طرف.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('calls')) {
            Schema::create('calls', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('conversation_id');
                $table->unsignedBigInteger('caller_id');
                $table->unsignedBigInteger('callee_id');
                $table->string('type', 10)->default('audio');          // audio | video
                $table->string('status', 12)->default('ringing');
                $table->string('end_reason', 24)->nullable();          // hangup | declined | timeout | cancelled | busy | connection_lost | ...
                $table->string('caller_client', 64)->nullable();
                $table->string('callee_client', 64)->nullable();
                $table->dateTime('created_at')->useCurrent();
                $table->dateTime('answered_at')->nullable();
                $table->dateTime('ended_at')->nullable();
                $table->dateTime('caller_seen_at')->nullable();
                $table->dateTime('callee_seen_at')->nullable();
                $table->unsignedInteger('duration_seconds')->nullable();
                $table->unsignedBigInteger('log_message_id')->nullable();   // رسالة السجل في الشات
                $table->foreign('conversation_id')->references('id')->on('conversations')->onDelete('cascade');
                $table->foreign('caller_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('callee_id')->references('id')->on('users')->onDelete('cascade');
                $table->index(['callee_id', 'status'], 'idx_calls_callee_status');
                $table->index(['caller_id', 'status'], 'idx_calls_caller_status');
                $table->index(['conversation_id', 'created_at'], 'idx_calls_conversation');
            });
        }

        if (!Schema::hasTable('call_signals')) {
            Schema::create('call_signals', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('call_id');
                $table->unsignedBigInteger('from_user_id');
                $table->unsignedBigInteger('to_user_id');
                $table->string('kind', 16);                            // offer | answer | ice | ...
                $table->longText('payload')->nullable();
                $table->dateTime('created_at')->useCurrent();
                $table->foreign('call_id')->references('id')->on('calls')->onDelete('cascade');
                $table->index(['call_id', 'to_user_id', 'id'], 'idx_call_signals_poll');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('call_signals');
        Schema::dropIfExists('calls');
    }
};
