<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الحضور والغياب:
 *  - faculties.campus_lat/lng/radius : موقع الكلية (مرجع الجيوفنس).
 *  - attendance_windows : "تحضير" واحد لحصة واحدة (بيفتحه مدير المادة؛ فيه سر لتوليد QR متغيّر + موقع الدكتور).
 *  - attendance_records : حالة كل طالب في كل تحضير (present | late | absent | excused).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('faculties') && !Schema::hasColumn('faculties', 'campus_lat')) {
            Schema::table('faculties', function (Blueprint $table) {
                $table->decimal('campus_lat', 10, 7)->nullable();
                $table->decimal('campus_lng', 10, 7)->nullable();
                $table->unsignedInteger('campus_radius_m')->nullable();
            });
        }

        if (!Schema::hasTable('attendance_windows')) {
            Schema::create('attendance_windows', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('course_id')->index();
                $table->unsignedBigInteger('course_session_id')->nullable()->index();
                $table->unsignedBigInteger('opened_by_user_id');
                $table->string('secret', 64);                         // سر الـ HMAC — مبيتبعتش لأي عميل
                $table->dateTime('opens_at');
                $table->dateTime('closes_at');
                $table->dateTime('closed_at')->nullable();
                $table->string('status', 10)->default('open');       // open | closed
                $table->decimal('lat', 10, 7)->nullable();            // مكان مدير المادة وقت الفتح
                $table->decimal('lng', 10, 7)->nullable();
                $table->unsignedInteger('accuracy_m')->nullable();
                $table->unsignedInteger('radius_m')->nullable();      // نصف قطر حوالين مكان الدكتور
                $table->string('title', 200)->nullable();
                $table->timestamps();
                $table->index(['course_id', 'status']);
            });
        }

        if (!Schema::hasTable('attendance_records')) {
            Schema::create('attendance_records', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('window_id');
                $table->unsignedBigInteger('course_id')->index();
                $table->unsignedBigInteger('student_id')->index();
                $table->string('status', 10);                         // present | late | absent | excused
                $table->string('method', 10)->default('qr');          // qr | manual | auto
                $table->decimal('lat', 10, 7)->nullable();
                $table->decimal('lng', 10, 7)->nullable();
                $table->unsignedInteger('accuracy_m')->nullable();
                $table->unsignedInteger('distance_campus_m')->nullable();
                $table->unsignedInteger('distance_doctor_m')->nullable();
                $table->string('device_hash', 64)->nullable();
                $table->dateTime('checked_at')->nullable();
                $table->unsignedBigInteger('marked_by_user_id')->nullable();
                $table->timestamps();
                $table->unique(['window_id', 'student_id']);
                $table->index(['window_id', 'device_hash']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('attendance_windows');
        if (Schema::hasTable('faculties') && Schema::hasColumn('faculties', 'campus_lat')) {
            Schema::table('faculties', function (Blueprint $table) {
                $table->dropColumn(['campus_lat', 'campus_lng', 'campus_radius_m']);
            });
        }
    }
};
