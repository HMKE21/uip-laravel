<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Exam & Assessment System — Round 1، موسّعة في Round 6. سؤال واحد جوه
 * بنك أسئلة واحد (question_bank_id). Round 1 كانت بتغطي إنشاء نوعين بس
 * فعليًا من الفرونت (mcq, true_false)؛ Round 6 فتحت short_answer/essay
 * كمان (راجع AI_GRADABLE_TYPES) عشان AI Grading (Phase 18) يكون له
 * أسئلة فعلية يشتغل عليها. multi_select/file_upload لسه مقفولين لحد
 * round مخصص ليهم — نفس فلسفة "متفتحش UI مش جاهز خلفه" الأصلية.
 *
 * true_false متمثلة بـ 2 implicit options (True/False) بدل ما تتخزن
 * كصفوف question_options — راجع تعليق الـ migration. optionsForDisplay()
 * هي المكان اللي بيحصل فيه التمثيل ده.
 *
 * keywords (Round 6, Phase 20) عمود جديد — كلمات مفتاحية قصيرة متوقع
 * تظهر حرفيًا في إجابة الطالب، منفصلة عن expected_concepts (أفكار أعم).
 */
class Question extends Model
{
    use SoftDeletes;

    protected $table = 'questions';

    protected $fillable = [
        'question_bank_id', 'type', 'prompt', 'image_path', 'marks', 'difficulty', 'topic', 'tags',
        'correct_answer', 'accepted_answers', 'case_sensitive',
        'model_answer', 'ai_model_answer', 'expected_concepts', 'keywords', 'grading_instructions', 'ai_grading_enabled',
        'explanation', 'status', 'created_by_academic_staff_id',
    ];

    /** image_url بيتضاف تلقائيًا لأي toArray()/JSON للسؤال (والـ version snapshot). */
    protected $appends = ['image_url'];

    protected $casts = [
        'tags'               => 'array',
        'accepted_answers'   => 'array',
        'expected_concepts'  => 'array',
        'keywords'           => 'array',
        'case_sensitive'     => 'boolean',
        'ai_grading_enabled' => 'boolean',
        'marks'              => 'decimal:2',
    ];

    /** أنواع الأسئلة المتاحة فعليًا من Round 1 — باقي الأنواع أعمدتها جاهزة بس القيمة دي بتقفلها لحد الـ round بتاعها. */
    public const ROUND1_TYPES = ['mcq', 'true_false'];

    /** Round 6 — الأنواع اللي ممكن يتفعّلها AI grading عليها (essay/short_answer). فتحت للإنشاء من هنا. */
    public const AI_GRADABLE_TYPES = ['short_answer', 'essay'];

    /** مسار root-relative (/uploads/...) — بيمشي على نفس proxy الـ /uploads بتاع الفرونت. */
    public function getImageUrlAttribute(): ?string
    {
        $path = $this->attributes['image_path'] ?? null;
        return $path ? '/' . ltrim($path, '/') : null;
    }

    public function bank()
    {
        return $this->belongsTo(QuestionBank::class, 'question_bank_id');
    }

    public function options()
    {
        return $this->hasMany(QuestionOption::class)->orderBy('sort_order');
    }

    /** Round 6 — rubric واحد بالظبط لكل سؤال، لو المدرس عرّف واحد (Phase 19). */
    public function rubric()
    {
        return $this->hasOne(ExamRubric::class);
    }

    /**
     * قايمة الاختيارات جاهزة للعرض — بترجع صفوف question_options
     * الحقيقية للـ mcq/multi_select، أو زوج True/False مُصطنع (بدون id
     * حقيقي في الداتابيز) لو النوع true_false.
     */
    public function optionsForDisplay(): array
    {
        if ($this->type === 'true_false') {
            $correct = $this->correct_answer === 'true';
            return [
                ['id' => null, 'option_text' => 'True', 'is_correct' => $correct, 'sort_order' => 0],
                ['id' => null, 'option_text' => 'False', 'is_correct' => !$correct, 'sort_order' => 1],
            ];
        }
        return $this->options->map(fn ($o) => $o->toArray())->all();
    }
}
