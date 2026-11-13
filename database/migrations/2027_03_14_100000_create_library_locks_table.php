<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قفل "مكتبتي" — اختياري ولكل مستخدم:
 *  - mode = own      باسورد خاص بالمكتبة (lock_hash = bcrypt)
 *  - mode = account  بيتفتح بباسورد الحساب نفسه (مفيش lock_hash)
 *  - mode = mypc     بيتفتح بباسورد قفل "جهازي" (desktop_settings.lock_hash) — لو اتغيّر هناك بيتغيّر هنا لوحده
 * مفيش صف = المكتبة مفتوحة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('library_locks')) {
            return;
        }
        Schema::create('library_locks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('mode', 8)->default('own');
            $table->string('lock_hash', 255)->nullable();
            $table->dateTime('changed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_locks');
    }
};
