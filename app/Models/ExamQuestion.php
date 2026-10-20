<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Exam & Assessment System — Round 1، موسّعة في Round 7. صف pivot واحد
 * يربط exam <-> question (exam_questions)، مع marks_override (يغلب على
 * questions.marks لو موجود — راجع ExamSystemService::totalMarksFor())
 * وsort_order (ترتيب التأليف الثابت لو source=manual؛ الترتيب الفعلي
 * اللي الطالب شافه لكل محاولة — بعد أي خلط — محفوظ في exam_attempt_questions
 * منفصل، راجع docblock الموديل ده).
 *
 * source/question_pool_id (Round 7، Phase 6): صف source=manual اتضاف
 * يدويًا بالمدرس (Round 1 الأصلي). صف source=pool معناه طلع من pool سحب
 * عشوائي لطالب واحد على الأقل (question_pool_id بيقول أنهي pool) — الصف
 * نفسه بيتشارك بين كل الطلاب اللي طلعلهم نفس السؤال (dedup على
 * exam_id+question_id، راجع ExamRepository::findOrCreateQuestionForPool())،
 * بس ظهوره الفعلي لطالب بعينه بيتحدد بوجود صف مقابل في exam_attempt_questions
 * مش بمجرد وجوده هنا.
 *
 * موديل مستقل (مش استخدام ->using() جوه belongsToMany بس) عشان
 * الـ service محتاج يتعامل مع صفوف الـ pivot مباشرة (reorder/remove
 * سؤال واحد بالـ id بتاعه) من غير ما يعدي على الـ exam ككل.
 */
class ExamQuestion extends Model
{
    protected $table = 'exam_questions';

    protected $fillable = [
        'exam_id', 'question_id', 'marks_override', 'sort_order', 'source', 'question_pool_id',
    ];

    protected $casts = [
        'marks_override' => 'decimal:2',
    ];

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function question()
    {
        return $this->belongsTo(Question::class);
    }

    /** Round 7 — الـ pool اللي السؤال ده طلع منه، لو source=pool. */
    public function pool()
    {
        return $this->belongsTo(QuestionPool::class, 'question_pool_id');
    }
}
