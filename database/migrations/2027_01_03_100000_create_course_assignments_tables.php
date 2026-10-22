<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * التكليفات والتسليمات:
 *  - course_assignments : تكليف بموعد وأقصى درجة (مرفق اختياري) — بينزّله مديرو المادة.
 *  - assignment_submissions : تسليم واحد لكل طالب (نص و/أو ملف) + الدرجة والتعليق. status: submitted | graded | returned.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('course_assignments')) {
            Schema::create('course_assignments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('course_id')->index();
                $table->string('title', 200);
                $table->text('body')->nullable();
                $table->dateTime('due_at')->nullable();
                $table->decimal('max_score', 6, 2)->default(100);
                $table->decimal('weight', 5, 2)->nullable();            // % من درجة المادة (للربط بدفتر الدرجات لاحقًا)
                $table->boolean('allow_late')->default(true);
                $table->string('status', 10)->default('published');     // draft | published
                $table->string('file_path', 500)->nullable();
                $table->string('file_name', 255)->nullable();
                $table->string('file_mime', 120)->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();
                $table->index(['course_id', 'status', 'due_at']);
            });
        }

        if (!Schema::hasTable('assignment_submissions')) {
            Schema::create('assignment_submissions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('assignment_id');
                $table->unsignedBigInteger('course_id')->index();
                $table->unsignedBigInteger('student_id')->index();
                $table->text('body')->nullable();
                $table->string('file_path', 500)->nullable();
                $table->string('file_name', 255)->nullable();
                $table->string('file_mime', 120)->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->dateTime('submitted_at');
                $table->boolean('is_late')->default(false);
                $table->unsignedSmallInteger('attempts')->default(1);
                $table->string('status', 10)->default('submitted');    // submitted | graded | returned
                $table->decimal('score', 6, 2)->nullable();
                $table->text('feedback')->nullable();
                $table->unsignedBigInteger('graded_by_user_id')->nullable();
                $table->dateTime('graded_at')->nullable();
                $table->timestamps();
                $table->unique(['assignment_id', 'student_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_submissions');
        Schema::dropIfExists('course_assignments');
    }
};
