<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "مكتبتي" (My Library): تفضيلات الشخص بتتخزن على السيرفر بدل المتصفح عشان تتزامن بين أجهزته:
 *  - marks   : المفضلة + التاجات + الروابط اللي اتبعتتله (نفس بيانات useDriveMarks)
 *  - library : العناصر المخفية + مصدر العرض + شكل العرض + المجلدات الذكية
 * صف واحد لكل مستخدم (user_id فريد). الـ JSON بيتنضّف في DriveLibraryState::sanitize قبل ما يتحفظ.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('drive_library_state')) {
            return;
        }
        Schema::create('drive_library_state', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->longText('marks')->nullable();
            $table->longText('library')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_library_state');
    }
};
