<?php

namespace App\Repositories;

use App\Models\ExamQuestionVersion;
use App\Models\Question;

/**
 * Question Versioning. نقطة الكتابة الوحيدة هي snapshot() — بتتنادى من
 * ExamSystemService *قبل* أي تعديل أو حذف فعلي على السؤال (راجع
 * ExamSystemService::updateQuestion()/deleteQuestion())، عشان الـ
 * snapshot يمثّل "الحالة قبل" مش "الحالة بعد".
 */
class ExamQuestionVersionRepository
{
    public function nextVersionNumber($questionId): int
    {
        return 1 + (int) (ExamQuestionVersion::where('question_id', $questionId)->max('version_number') ?? 0);
    }

    /** بياخد الـ Question *زي ما هي دلوقتي* (قبل ما تتعدّل) ويعمل snapshot كامل ليها + خياراتها. */
    public function snapshot(Question $question, $changedByAcademicStaffId, string $changeType = 'updated'): ExamQuestionVersion
    {
        $data = $question->toArray();
        $data['options'] = $question->type === 'mcq' ? $question->optionsForDisplay() : null;

        return ExamQuestionVersion::create([
            'question_id'                  => $question->id,
            'version_number'                => $this->nextVersionNumber($question->id),
            'snapshot'                      => $data,
            'changed_by_academic_staff_id'  => $changedByAcademicStaffId,
            'change_type'                   => $changeType,
            'created_at'                    => now(),
        ]);
    }

    /** @return ExamQuestionVersion[] الأحدث الأول. */
    public function listForQuestion($questionId): array
    {
        return ExamQuestionVersion::where('question_id', $questionId)
            ->orderByDesc('version_number')
            ->get()
            ->all();
    }

    public function find($versionId): ?ExamQuestionVersion
    {
        return ExamQuestionVersion::find($versionId);
    }
}
