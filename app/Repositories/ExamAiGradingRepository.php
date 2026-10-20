<?php

namespace App\Repositories;

use App\Models\ExamAiGrading;

/**
 * Exam & Assessment System — Round 6 (Phase 18). صف تشخيصي واحد لكل
 * exam_grades — upsertForGrade() هي نقطة الكتابة الوحيدة، بتتنادى من
 * ExamGradingService في كل مرحلة من دورة حياة الـ AI job (queued ->
 * processing -> completed|failed). راجع docblock ExamAiGrading نفسها.
 */
class ExamAiGradingRepository
{
    public function findForGrade($examGradeId): ?ExamAiGrading
    {
        return ExamAiGrading::where('exam_grade_id', $examGradeId)->first();
    }

    public function upsertForGrade($examGradeId, array $data): ExamAiGrading
    {
        $row = $this->findForGrade($examGradeId);
        if ($row) {
            $row->fill($data);
            $row->save();
            return $row;
        }

        return ExamAiGrading::create(array_merge($data, ['exam_grade_id' => $examGradeId]));
    }
}
