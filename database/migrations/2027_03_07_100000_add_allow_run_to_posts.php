<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * posts.allow_run — صاحب المنشور هو اللي يقرر لو الكود المرفق يتشغّل للناس (تشغيل sandbox بيستهلك موارد السيرفر).
 * المنشورات القديمة بتفضل زي ما هي (الافتراضي 1)، والجديدة الواجهة بتبعتها false إلا لو صاحبها فعّلها.
 * ناتج التشغيل اللي ينشره الكاتب مع الكود بيتخزّن في post_media.meta.output (من غير عمود جديد).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('posts') && !Schema::hasColumn('posts', 'allow_run')) {
            Schema::table('posts', function (Blueprint $table) {
                $table->boolean('allow_run')->default(1)->after('allow_download');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('posts') && Schema::hasColumn('posts', 'allow_run')) {
            Schema::table('posts', function (Blueprint $table) {
                $table->dropColumn('allow_run');
            });
        }
    }
};
