<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * اعتراضات المستخدمين على حجب المحتوى (Content Safety).
 *  - content_appeals          : اعتراض واحد = محادثة بين المستخدم والإدارة (open | answered | closed).
 *  - content_appeal_messages  : رسائل المحادثة (user | admin).
 * مفيش نص المحتوى المحجوب بيتخزّن هنا — المستخدم بيكتب بكلامه.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('content_appeals')) {
            Schema::create('content_appeals', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->index();
                $t->string('subject', 160);
                $t->string('category', 30)->nullable();        // فئة الحجب اللي المستخدم شافها (sexual | profanity | …)
                $t->string('status', 12)->default('open');       // open | answered | closed
                $t->string('resolution', 12)->nullable();        // resolved | declined (عند الإغلاق)
                $t->string('last_sender', 8)->default('user');   // user | admin
                $t->dateTime('last_message_at')->useCurrent();
                $t->dateTime('closed_at')->nullable();
                $t->unsignedBigInteger('closed_by')->nullable();
                $t->dateTime('created_at')->useCurrent();
                $t->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
                $t->index(['status', 'last_message_at'], 'idx_content_appeals_status');
            });
        }
        if (!Schema::hasTable('content_appeal_messages')) {
            Schema::create('content_appeal_messages', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('appeal_id')->index();
                $t->unsignedBigInteger('sender_id')->nullable();
                $t->string('sender_type', 8);                    // user | admin
                $t->text('body');
                $t->dateTime('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('content_appeal_messages');
        Schema::dropIfExists('content_appeals');
    }
};
