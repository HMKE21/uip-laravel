<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "ستيكراتي": الملصقات اللي المستخدم بيعملها من صورة/فيديو (New sticker) — مكتبة شخصية.
 * الملف بيتخزّن مرة واحدة هنا، ولما يتبعت بيتنسخ كمرفق للرسالة (فالمستلم بيشوفه من نفس نظام الوسائط المحمية).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_stickers')) {
            return;
        }
        Schema::create('user_stickers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('kind', 8)->default('image');          // image | video (ستيكر متحرك)
            $table->string('stored_path', 500);
            $table->string('original_name', 190)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->string('label', 120)->nullable();
            $table->unsignedInteger('size_bytes')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['user_id', 'id'], 'idx_user_stickers_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_stickers');
    }
};
