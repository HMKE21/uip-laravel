<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "جهازي": فهرس نصوص الملفات (للبحث جوّه المحتوى). صف واحد لكل ملف نصي: النص نفسه (UTF-8، بسقف حجم) + بصمة النسخة اللي اتفهرسّت
 * (الحجم + sha1) — لو الملف اتغيّر البصمة بتختلف وبيتفهرس تاني تلقائيًا وقت البحث. الملفات اللي مش نصية بتتسجّل بـ content = NULL
 * عشان متتفحصش كل مرة. الصف بيتمسح مع الملف عند الحذف النهائي.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('drive_node_text')) {
            return;
        }
        Schema::create('drive_node_text', function (Blueprint $table) {
            $table->unsignedBigInteger('node_id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('size_bytes')->default(0);   // حجم الملف وقت الفهرسة
            $table->string('sha1', 40)->nullable();                 // sha1 الملف وقت الفهرسة
            $table->mediumText('content')->nullable();              // NULL = مش ملف نصي / ملف ثنائي
            $table->dateTime('indexed_at')->nullable();

            $table->index('user_id', 'drive_node_text_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_node_text');
    }
};
