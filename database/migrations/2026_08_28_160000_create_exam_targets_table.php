<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exam & Assessment System — Round 2 (Targeting). صف واحد = قاعدة
 * استهداف واحدة؛ أي طالب بيتوافق مع أي صف من صفوف امتحان واحد بيبقى
 * مؤهل ليشوفه (OR بين الصفوف، AND بين أعمدة الصف الواحد — راجع
 * ExamTargetRepository::eligibilityWhereGroups() للتنفيذ الكامل).
 *
 * الأعمدة كلها nullable ومعناها wildcard (تتجاهل) لو null، ماعدا
 * student_id: لو موجودة فالصف ده بيبقى معناه "الطالب ده بالتحديد" وباقي
 * الأعمدة المفروض تتسيب null (الـ service بيتأكد من كده وقت الحفظ).
 * صف من غير أي عمود موجود (كل حاجة null، حتى student_id) معناه "كل
 * طلاب الجامعة" — نفس منطق target_faculty_id/target_department_id/
 * target_academic_year في جدول announcements القديم (كل عمود null =
 * يشمل الكل)، بس هنا موزعة على صفوف متعددة بدل عمود واحد لكل امتحان،
 * عشان ندعم "Combined Filters" (Phase 8 في السبك) + استهداف طلاب
 * محددين بالإضافة لفلاتر الجامعة/الكلية/القسم/البرنامج/السنة/المجموعة.
 *
 * مفيش عمود course/subject هنا — الامتحان نفسه أصلًا عليه exams.subject،
 * فمفيش كيان "مادة" مستقل يتربط بيه الطالب في السكيما الحالية يتفلتر
 * عليه (راجع تقرير الـ round).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('exam_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            $table->foreignId('faculty_id')->nullable()->constrained('faculties')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->cascadeOnDelete();
            $table->foreignId('program_id')->nullable()->constrained('programs')->cascadeOnDelete();
            $table->unsignedTinyInteger('academic_year')->nullable();
            $table->foreignId('group_id')->nullable()->constrained('student_groups')->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->cascadeOnDelete();
            $table->timestamps();

            $table->index('exam_id');
            $table->index('student_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_targets');
    }
};
