<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * محتوى المادة (Course materials): محاضرات، فيديوهات، صور، ملفات، منشورات الدكتور/المعيد،
 * نتائج، وإعلانات الامتحانات. بيترفع من مدرّسي المادة (أو الكلية/الجامعة) ويشوفه الطلاب المسجّلين.
 * course_material_saves = "حفظ" الطالب للعنصر في قائمته (bookmark).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('course_materials')) {
            Schema::create('course_materials', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('course_id')->index();
                // lecture | video | image | file | post | result | exam
                $table->string('kind', 20);
                $table->string('title', 200);
                $table->text('body')->nullable();
                $table->string('external_url', 500)->nullable();      // YouTube / Drive / أي رابط خارجي
                $table->string('file_path', 500)->nullable();
                $table->string('file_name', 255)->nullable();
                $table->string('file_mime', 120)->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->timestamp('exam_at')->nullable();             // kind=exam
                $table->string('exam_location', 200)->nullable();
                $table->unsignedSmallInteger('exam_duration_min')->nullable();
                $table->boolean('is_pinned')->default(false);
                $table->string('status', 20)->default('published');   // published | draft
                $table->unsignedBigInteger('created_by_user_id')->nullable()->index();
                $table->string('author_role', 30)->nullable();
                $table->timestamps();
                $table->index(['course_id', 'kind']);
            });
        }

        if (!Schema::hasTable('course_material_saves')) {
            Schema::create('course_material_saves', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('material_id')->index();
                $table->unsignedBigInteger('student_id')->index();
                $table->timestamps();
                $table->unique(['material_id', 'student_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('course_material_saves');
        Schema::dropIfExists('course_materials');
    }
};
