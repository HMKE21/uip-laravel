<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الطلبات الإدارية (شهادة قيد / بيان درجات / إفادة): الطالب ← الكلية ← الجامعة.
 *  - admin_requests       : الطلب. stage = faculty | university (الجهة الحالية)، status = pending | approved | rejected | cancelled.
 *  - admin_request_events : سجل الطلب (تقديم / تحويل للجامعة / موافقة / رفض / إلغاء) + ملاحظة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('admin_requests')) {
            Schema::create('admin_requests', function (Blueprint $table) {
                $table->id();
                $table->string('ref_no', 24)->nullable()->unique();
                $table->unsignedBigInteger('student_id')->index();
                $table->unsignedBigInteger('university_id')->index();
                $table->unsignedBigInteger('faculty_id')->nullable()->index();
                $table->string('type', 24);                                  // enrollment_certificate | transcript | statement
                $table->string('status', 12)->default('pending')->index();
                $table->string('stage', 12)->default('faculty');             // faculty | university
                $table->string('purpose', 200)->nullable();                  // الجهة الموجّه لها / الغرض
                $table->text('message')->nullable();
                $table->unsignedBigInteger('decided_by_user_id')->nullable();
                $table->string('decided_by_role', 12)->nullable();
                $table->text('decision_note')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->timestamps();
                $table->index(['university_id', 'status', 'stage']);
            });
        }

        if (!Schema::hasTable('admin_request_events')) {
            Schema::create('admin_request_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('request_id')->index();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('role', 12)->nullable();
                $table->string('action', 12);                                // submitted | forwarded | approved | rejected | cancelled
                $table->text('note')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_request_events');
        Schema::dropIfExists('admin_requests');
    }
};
