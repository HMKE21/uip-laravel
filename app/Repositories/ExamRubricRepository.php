<?php

namespace App\Repositories;

use App\Models\ExamRubric;
use App\Models\ExamRubricCriterion;
use Illuminate\Support\Facades\DB;

/**
 * Exam & Assessment System — Round 6 (Phase 19). كل استعلامات
 * exam_rubrics + exam_rubric_criteria من هنا — نفس نمط ExamGradeRepository
 * (الجدولين المرتبطين في كلاس واحد). upsertRubric() بتعمل
 * delete-then-recreate كامل لكل الـ criteria جوه transaction — مفيش
 * partial update لبند واحد من الفرونت، دايمًا القائمة كاملة بتتستبدل
 * (أبسط عقد، وrubric واحد صغير مش محتاج diffing).
 */
class ExamRubricRepository
{
    public function findForQuestion($questionId): ?ExamRubric
    {
        return ExamRubric::with('criteria')->where('question_id', $questionId)->first();
    }

    /**
     * @param array<int,array{label:string,max_points:float|string}> $criteria
     */
    public function upsertRubric($questionId, $academicStaffId, array $criteria): ExamRubric
    {
        return DB::transaction(function () use ($questionId, $academicStaffId, $criteria) {
            $rubric = ExamRubric::firstOrCreate(
                ['question_id' => $questionId],
                ['created_by_academic_staff_id' => $academicStaffId]
            );

            ExamRubricCriterion::where('exam_rubric_id', $rubric->id)->delete();
            foreach (array_values($criteria) as $i => $criterion) {
                ExamRubricCriterion::create([
                    'exam_rubric_id' => $rubric->id,
                    'label'          => trim((string) $criterion['label']),
                    'max_points'     => (float) $criterion['max_points'],
                    'sort_order'     => $i,
                ]);
            }

            return $rubric->fresh('criteria');
        });
    }

    /** cascadeOnDelete جوه الـ migration بيمسح الـ criteria لوحدها. */
    public function deleteForQuestion($questionId): bool
    {
        return ExamRubric::where('question_id', $questionId)->delete() > 0;
    }
}
