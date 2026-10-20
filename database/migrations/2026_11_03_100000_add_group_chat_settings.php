<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp-style group chats: description / photo, invite link, "who can send / edit info / add members",
 * allowed message types, join approval + requests, and per-member "restricted from sending".
 * Every new column has a default that keeps today's behaviour for existing conversations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            if (!Schema::hasColumn('conversations', 'description')) {
                $table->text('description')->nullable();
            }
            if (!Schema::hasColumn('conversations', 'avatar_path')) {
                $table->string('avatar_path', 255)->nullable();
            }
            if (!Schema::hasColumn('conversations', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable();
            }
            if (!Schema::hasColumn('conversations', 'invite_token')) {
                $table->string('invite_token', 64)->nullable()->unique('uq_conversations_invite_token');
            }
            if (!Schema::hasColumn('conversations', 'invite_enabled')) {
                $table->boolean('invite_enabled')->default(1);
            }
            if (!Schema::hasColumn('conversations', 'only_admins_send')) {
                $table->boolean('only_admins_send')->default(0);
            }
            if (!Schema::hasColumn('conversations', 'members_can_edit_info')) {
                $table->boolean('members_can_edit_info')->default(0);
            }
            if (!Schema::hasColumn('conversations', 'members_can_add')) {
                $table->boolean('members_can_add')->default(0);
            }
            if (!Schema::hasColumn('conversations', 'join_approval')) {
                $table->boolean('join_approval')->default(0);
            }
            if (!Schema::hasColumn('conversations', 'allowed_types')) {
                // null = كل الأنواع مسموحة. وإلا مصفوفة JSON من: text,image,video,audio,file,poll,gif,sticker
                $table->json('allowed_types')->nullable();
            }
            if (!Schema::hasColumn('conversations', 'max_members')) {
                $table->unsignedInteger('max_members')->nullable();
            }
        });

        Schema::table('conversation_participants', function (Blueprint $table) {
            if (!Schema::hasColumn('conversation_participants', 'send_restricted')) {
                $table->boolean('send_restricted')->default(0);
            }
            if (!Schema::hasColumn('conversation_participants', 'added_by')) {
                $table->unsignedBigInteger('added_by')->nullable();
            }
            if (!Schema::hasColumn('conversation_participants', 'join_source')) {
                $table->string('join_source', 20)->nullable(); // created | added | link | approved
            }
        });

        if (!Schema::hasTable('conversation_join_requests')) {
            Schema::create('conversation_join_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('conversation_id');
                $table->unsignedBigInteger('user_id');
                $table->string('status', 12)->default('pending'); // pending | approved | rejected
                $table->dateTime('created_at')->useCurrent();
                $table->unsignedBigInteger('decided_by')->nullable();
                $table->dateTime('decided_at')->nullable();
                $table->foreign('conversation_id')->references('id')->on('conversations')->onDelete('cascade');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->index(['conversation_id', 'status'], 'idx_join_req_conv_status');
                $table->index(['user_id', 'status'], 'idx_join_req_user_status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_join_requests');
        Schema::table('conversation_participants', function (Blueprint $table) {
            foreach (['send_restricted', 'added_by', 'join_source'] as $c) {
                if (Schema::hasColumn('conversation_participants', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        Schema::table('conversations', function (Blueprint $table) {
            if (Schema::hasColumn('conversations', 'invite_token')) {
                $table->dropUnique('uq_conversations_invite_token');
            }
            foreach (['description', 'avatar_path', 'created_by', 'invite_token', 'invite_enabled', 'only_admins_send',
                      'members_can_edit_info', 'members_can_add', 'join_approval', 'allowed_types', 'max_members'] as $c) {
                if (Schema::hasColumn('conversations', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
