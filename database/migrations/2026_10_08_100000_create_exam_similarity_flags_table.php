<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * كشف التشابه بين إجابات المقالي. كل صف = إشارة واحدة: إجابتين (محاولتين مختلفتين، طالبين مختلفين)
 * على نفس السؤال بينهم تشابه عالي. الإشارة للمراجعة البشرية بس — مفيش أي إجراء تلقائي على الطالب.
 * status: pending (لسه ماتراجعتش) / confirmed (المدرس أكد) / dismissed (المدرس استبعد).
 * إعادة التحليل بتحدّث الأرقام بس وعمرها ما بترجّع status مراجَع لـ pending.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('exam_similarity_flags')) {
            return;
        }
        Schema::create('exam_similarity_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            $table->foreignId('exam_question_id')->constrained('exam_questions')->cascadeOnDelete();
            $table->foreignId('attempt_a_id')->constrained('exam_attempts')->cascadeOnDelete();
            $table->foreignId('attempt_b_id')->constrained('exam_attempts')->cascadeOnDelete();
            $table->decimal('similarity', 5, 2);            // 0..100
            $table->unsignedInteger('matched_words')->default(0);
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('reviewed_by_academic_staff_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->unique(['exam_question_id', 'attempt_a_id', 'attempt_b_id'], 'uq_similarity_pair');
            $table->index(['exam_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_similarity_flags');
    }
};
