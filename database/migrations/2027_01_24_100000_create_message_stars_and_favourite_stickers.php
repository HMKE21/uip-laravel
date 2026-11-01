<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قائمة الرسالة (كليك يمين / ضغطة طويلة):
 *  - message_stars: "تمييز بنجمة" — شخصي لكل مستخدم (التاني مبيشوفش إنك مميّز الرسالة).
 *  - favourite_stickers: "إضافة للمفضلة" للملصقات / الـ GIF — شخصي برضه، ومحفوظ بالمحتوى (url) مش بالرسالة،
 *    فيفضل موجود حتى لو الرسالة اتحذفت.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('message_stars')) {
            Schema::create('message_stars', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('message_id');
                $table->timestamp('created_at')->useCurrent();
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('message_id')->references('id')->on('messages')->onDelete('cascade');
                $table->unique(['user_id', 'message_id'], 'uq_message_stars');
                $table->index(['message_id'], 'idx_message_stars_message');
            });
        }

        if (!Schema::hasTable('favourite_stickers')) {
            Schema::create('favourite_stickers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('kind', 12)->default('sticker');      // sticker | gif
                $table->string('item_key', 64);                      // sha1 للـ url — بيمنع التكرار
                $table->string('url', 1000);
                $table->string('label', 190)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->unique(['user_id', 'item_key'], 'uq_favourite_stickers');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('favourite_stickers');
        Schema::dropIfExists('message_stars');
    }
};
