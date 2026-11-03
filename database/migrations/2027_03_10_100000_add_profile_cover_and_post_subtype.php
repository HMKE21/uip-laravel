<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بروفايل زي فيسبوك:
 *  - posts.subtype            'profile_photo' | 'cover_photo' لما المنشور ناتج عن تغيير الصورة الشخصية / الغلاف (يظهر "غيّر صورته الشخصية")
 *  - user_profile_covers      صورة الغلاف لكل مستخدم (واحدة)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('posts') && !Schema::hasColumn('posts', 'subtype')) {
            Schema::table('posts', function (Blueprint $table) {
                $table->string('subtype', 16)->nullable()->after('kind');
            });
        }
        if (!Schema::hasTable('user_profile_covers')) {
            Schema::create('user_profile_covers', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->primary();
                $table->string('cover_path', 255);
                $table->timestamp('updated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profile_covers');
        if (Schema::hasTable('posts') && Schema::hasColumn('posts', 'subtype')) {
            Schema::table('posts', function (Blueprint $table) {
                $table->dropColumn('subtype');
            });
        }
    }
};
