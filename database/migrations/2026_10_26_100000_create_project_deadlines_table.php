<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مواعيد مشاريع التخرج: موعد آخر تسليم + فترة المناقشة. الجامعة بتحدد موعد عام،
 * والكلية تقدر تحدد موعد خاص بيها (faculty_id) وبيتقدّم على العام.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('project_deadlines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('university_id');
            $table->unsignedBigInteger('faculty_id')->nullable();
            $table->string('title', 190);
            $table->dateTime('submission_deadline')->nullable();
            $table->date('defense_starts_at')->nullable();
            $table->date('defense_ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->foreign('university_id')->references('id')->on('universities')->onDelete('cascade');
            $table->foreign('faculty_id')->references('id')->on('faculties')->onDelete('cascade');
            $table->index(['university_id', 'faculty_id', 'is_active'], 'idx_project_deadlines_scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_deadlines');
    }
};
