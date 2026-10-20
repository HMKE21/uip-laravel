<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exam & Assessment System — Question Versioning.
 *
 * المشكلة اللي الملف ده بيحلّها: لو مدرس عدّل سؤال (زي غيّر الإجابة
 * الصح في MCQ، أو زوّد/نقّص الدرجة) بعد ما طلاب already جاوبوا عليه،
 * مفيش أي أثر للنسخة القديمة — يعني التصحيح ممكن يبقى ظالم/غير متسق لو
 * حد احتاج يراجع أو يعيد يصحح بعدين.
 *
 * القرار: صف واحد "snapshot" كامل (JSON) لحالة السؤال + خياراته قبل أي
 * تعديل أو حذف — مش diff بين الحقول (أبسط، وموثوق 100% حتى لو اتضافت
 * حقول جديدة للسؤال مستقبلًا من غير ما حد يفتكر يحدّث منطق الـ diff).
 * كل الإصدارات القديمة بتتحفظ (مش بس آخر واحدة) — مطلوب صراحة في
 * التحليل الأصلي ("سجل نسخة قديمة").
 *
 * الجدول ده read-only من ناحية التطبيق (بيتكتب مرة واحدة وقت
 * التعديل/الحذف، وبعدين بس بيتقرا للمراجعة) — مفيش updated_at ولا soft
 * delete ليه، النسخة التاريخية لازم تفضل زي ما هي.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('exam_question_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('questions')->cascadeOnDelete();
            // 1, 2, 3... لكل سؤال — نسخة السؤال قبل التعديل رقم N ده.
            $table->unsignedInteger('version_number');
            // الحالة الكاملة للسؤال + خياراته (MCQ) وقت أخذ الـ snapshot،
            // زي ما كانت *قبل* التعديل/الحذف اللي سبب الـ snapshot ده.
            $table->json('snapshot');
            $table->foreignId('changed_by_academic_staff_id')->nullable()
                ->constrained('academic_staff')->nullOnDelete();
            $table->string('change_type', 20)->default('updated'); // updated | deleted
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['question_id', 'version_number']);
            $table->index('question_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_question_versions');
    }
};
