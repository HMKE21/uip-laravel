<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حذف الجامعة بقى soft delete (قابل للاسترجاع) بدل الحذف النهائي اللي كان
 * بيمسح الصف وبيعمل cascade على الكليات والمجموعات وطلبات الانضمام.
 *
 * users.suspended_by_university_id / status_before_university_removal:
 * بنعرف بيهم أي طلاب اتوقفوا بسبب حذف الجامعة (مش بسبب تاني)، وكانوا في أنهي
 * حالة، عشان الاسترجاع يرجّعهم هم بس لحالتهم الأصلية.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('universities', function (Blueprint $table) {
            $table->dateTime('deleted_at')->nullable()->after('updated_at');
            $table->unsignedBigInteger('deleted_by')->nullable()->after('deleted_at');
            $table->index(['deleted_at'], 'idx_universities_deleted_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('suspended_by_university_id')->nullable();
            $table->string('status_before_university_removal', 20)->nullable();
            $table->index(['suspended_by_university_id'], 'idx_users_suspended_by_university');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_users_suspended_by_university');
            $table->dropColumn(['suspended_by_university_id', 'status_before_university_removal']);
        });
        Schema::table('universities', function (Blueprint $table) {
            $table->dropIndex('idx_universities_deleted_at');
            $table->dropColumn(['deleted_at', 'deleted_by']);
        });
    }
};
