<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "الرسائل المحفوظة" (Saved Messages): كل مستخدم عنده محادثة خاصة بيه لوحده (مشارك واحد = هو).
 * saved_for_user_id = صاحبها، وunique عشان يبقى فيه واحدة بس لكل مستخدم.
 * conversations.subject بيشيل الاسم اللي المستخدم يختاره (NULL = الاسم الافتراضي).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->unsignedBigInteger('saved_for_user_id')->nullable()->after('student_group_id');
            $table->unique('saved_for_user_id', 'uq_conversations_saved_for_user');
            $table->foreign('saved_for_user_id', 'fk_conversations_saved_for_user')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign('fk_conversations_saved_for_user');
            $table->dropUnique('uq_conversations_saved_for_user');
            $table->dropColumn('saved_for_user_id');
        });
    }
};
