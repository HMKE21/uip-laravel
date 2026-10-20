<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المجموعة 5 (التقييم):
 *  - 5.1 project_grade_members: درجة المشروع + تعديل فردي (adjustment بنقاط مئوية) لكل عضو بحساب،
 *    والنتيجة الفردية (final_percentage + letter_grade) هي اللي بيقرأها التخرج.
 *  - 5.3 قفل الدرجة النهائية: status='final' = مقفولة. أي تعديل بعدها بيعدي على "إعادة فتح" بسبب مكتوب
 *    (reopened_*)، وكل اعتماد/إعادة فتح بيتسجل في project_grade_revisions مع snapshot كامل.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_grades', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(0);
            $table->unsignedBigInteger('reopened_by')->nullable();
            $table->dateTime('reopened_at')->nullable();
            $table->text('reopen_reason')->nullable();
        });

        Schema::create('project_grade_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_grade_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('user_id');
            $table->decimal('adjustment', 5, 2)->default(0);
            $table->decimal('final_percentage', 5, 2)->nullable();
            $table->string('letter_grade', 2)->nullable();
            $table->text('comments')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->foreign('project_grade_id')->references('id')->on('project_grades')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['project_grade_id', 'user_id'], 'uq_grade_member');
            $table->index(['user_id'], 'idx_grade_members_user');
        });

        Schema::create('project_grade_revisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_grade_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedInteger('revision');
            $table->string('action', 20); // finalized | reopened
            $table->unsignedBigInteger('actor_id');
            $table->string('actor_role', 30);
            $table->text('reason')->nullable();
            $table->decimal('total_score', 6, 2)->nullable();
            $table->decimal('max_score', 6, 2)->nullable();
            $table->string('letter_grade', 2)->nullable();
            $table->json('snapshot')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->foreign('project_grade_id')->references('id')->on('project_grades')->onDelete('cascade');
            $table->index(['project_grade_id', 'id'], 'idx_grade_revisions');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_grade_revisions');
        Schema::dropIfExists('project_grade_members');
        Schema::table('project_grades', function (Blueprint $table) {
            $table->dropColumn(['revision', 'reopened_by', 'reopened_at', 'reopen_reason']);
        });
    }
};
