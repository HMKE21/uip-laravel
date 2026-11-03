<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قفل البروفايل + خصوصية القوايم + حماية الصورة الشخصية.
 *  user_network_settings.profile_locked   1 = اللي مش من أصدقائي/متابعيني مايشوفوش منشوراتي وصوري وقوايمي
 *  user_network_settings.lists_visibility everyone | contacts | me   (مين يشوف الأصدقاء/المتابعين/المتابَعين)
 *  user_network_settings.photo_download   nobody | contacts | everyone (مين يقدر ينزّل صورتي الشخصية)
 *  user_profile_photos                    مسار الأصل (الحجم الكامل) — خاص خارج public/، ومابيتسلّمش غير عبر الـ API.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_network_settings')) {
            Schema::table('user_network_settings', function (Blueprint $table) {
                if (!Schema::hasColumn('user_network_settings', 'profile_locked')) {
                    $table->boolean('profile_locked')->default(false);
                }
                if (!Schema::hasColumn('user_network_settings', 'lists_visibility')) {
                    $table->string('lists_visibility', 10)->default('everyone');
                }
                if (!Schema::hasColumn('user_network_settings', 'photo_download')) {
                    $table->string('photo_download', 10)->default('nobody');
                }
            });
        }
        if (!Schema::hasTable('user_profile_photos')) {
            Schema::create('user_profile_photos', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->primary();
                $table->string('original_path', 255);
                $table->timestamp('updated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profile_photos');
        if (Schema::hasTable('user_network_settings')) {
            Schema::table('user_network_settings', function (Blueprint $table) {
                foreach (['profile_locked', 'lists_visibility', 'photo_download'] as $c) {
                    if (Schema::hasColumn('user_network_settings', $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }
    }
};
