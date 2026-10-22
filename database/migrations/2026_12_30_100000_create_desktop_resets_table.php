<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إعادة تهيئة "جهازي" بمهلة تراجع: الملفات بتتنقل للسلة (مش بتتمسح فورًا) + لقطة من الإعدادات/الويدجت،
 * ولمدة 24 ساعة الطالب يقدر يعمل "تراجع". بعدها الأمر المجدول drive:purge-resets بيمسحها نهائيًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('desktop_resets')) {
            return;
        }
        Schema::create('desktop_resets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->text('roots');                      // JSON: ids الجذور اللي اتنقلت للسلة
            $table->longText('snapshot')->nullable();   // JSON: الإعدادات + الويدجت + باسورد القفل (hash) قبل المسح
            $table->dateTime('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desktop_resets');
    }
};
