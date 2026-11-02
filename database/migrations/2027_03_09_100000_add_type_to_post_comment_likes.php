<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** تفاعلات التعليقات: نفس أنواع تفاعلات المنشور (الإعجابات القديمة بتفضل like). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('post_comment_likes') && !Schema::hasColumn('post_comment_likes', 'type')) {
            Schema::table('post_comment_likes', function (Blueprint $table) {
                $table->string('type', 12)->default('like')->after('user_id');
                $table->index(['comment_id', 'type'], 'idx_post_comment_likes_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('post_comment_likes') && Schema::hasColumn('post_comment_likes', 'type')) {
            Schema::table('post_comment_likes', function (Blueprint $table) {
                $table->dropIndex('idx_post_comment_likes_type');
                $table->dropColumn('type');
            });
        }
    }
};
