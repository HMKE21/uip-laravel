<?php

namespace App\Services;

use App\Models\Exam;
use App\Repositories\ExamAttemptRepository;
use App\Repositories\ExamRepository;
use App\Repositories\ExamTargetRepository;

/**
 * Exam & Assessment System — Round 2. سطح قراءة-فقط للطالب: "امتحاناتي"
 * (Phase 8/9 في السبك، جزء الطالب). عمدًا بيرجع بيانات الامتحان الوصفية
 * بس (عنوان/مدة/درجة/تواريخ) — من غير أي سؤال أو اختيار، حتى لو الطالب
 * مؤهل يشوف الامتحان؛ محتوى الأسئلة نفسه هيتكشف بس وقت بدء المحاولة
 * (exam_attempts، Round 3) عشان محدش يقدر "يقرا" الامتحان من غير ما
 * يبدأ محاولة رسمية — نفس روح Phase 9 "Never rely only on frontend
 * validation"، بس هنا بتترجم لـ "الباك إند نفسه ميكشفش المحتوى قبل
 * أوانه" مش بس فحص صلاحية.
 */
class StudentExamService
{
    public function __construct(
        private ExamRepository $exams,
        private ExamTargetRepository $targets,
        private ExamAttemptRepository $attempts
    ) {
    }

    /** @return array<int,array<string,mixed>> كل الامتحانات المنشورة/المجدولة اللي الطالب ده مستهدف بيها. */
    public function listMyExams($studentId, $universityId): array
    {
        $examIds = $this->targets->examIdsForStudent($studentId, $universityId);
        if (!$examIds) {
            return [];
        }

        $exams = array_filter(
            array_map(fn ($id) => $this->exams->find($id), $examIds),
            function (?Exam $e) use ($studentId) {
                if ($e === null) {
                    return false;
                }
                if (in_array($e->status, ['published', 'scheduled'], true)) {
                    return true;
                }
                // Finished exams stay in the student's list (as "Completed") only if they took part,
                // or if the instructor opened a retake for them.
                return in_array($e->status, ['closed', 'grading', 'graded'], true)
                    && (count($this->attempts->forStudentAndExam($studentId, $e->id)) > 0
                        || $this->retakeIsOpen($e, $studentId));
            }
        );

        usort($exams, fn (Exam $a, Exam $b) => ($a->start_at?->timestamp ?? PHP_INT_MAX) <=> ($b->start_at?->timestamp ?? PHP_INT_MAX));

        return array_map(fn (Exam $e) => $this->summaryFor($e, $studentId), array_values($exams));
    }

    /** بيانات وصفية لامتحان واحد — null لو مش موجود أو الطالب مش مؤهل أو لسه مش منشور. */
    public function examSummaryForStudent($examId, $studentId, $universityId): ?array
    {
        $exam = $this->exams->find($examId);
        if (!$exam || (int) $exam->university_id !== (int) $universityId) {
            return null;
        }
        $finishedButTookPart = in_array($exam->status, ['closed', 'grading', 'graded'], true)
            && (count($this->attempts->forStudentAndExam($studentId, $exam->id)) > 0
                || $this->retakeIsOpen($exam, $studentId));
        if (!in_array($exam->status, ['published', 'scheduled'], true) && !$finishedButTookPart) {
            return null;
        }
        if (!$this->targets->studentIsEligibleForExam($examId, $studentId)) {
            return null;
        }

        return $this->summaryFor($exam, $studentId);
    }

    /** هل المدرس فاتح للطالب ده إعادة (محاولات إضافية لسه متبقية + نافذة خاصة مفتوحة)؟ */
    private function retakeIsOpen(Exam $exam, $studentId): bool
    {
        $override = $this->attempts->overrideFor($exam->id, $studentId);
        if (!$override || (int) $override->extra_attempts < 1 || !$override->windowIsOpen()) {
            return false;
        }
        $used = count($this->attempts->forStudentAndExam($studentId, $exam->id));
        return $used < (int) $exam->max_attempts + (int) $override->extra_attempts;
    }

    private function summaryFor(Exam $exam, $studentId = null): array
    {
        $mine = $studentId !== null ? $this->attempts->forStudentAndExam($studentId, $exam->id) : [];
        $last = $mine[0] ?? null;
        $override = $studentId !== null ? $this->attempts->overrideFor($exam->id, $studentId) : null;
        $extra = $override ? (int) $override->extra_attempts : 0;
        $resultVisible = $this->resultVisibleFor($exam, $last);

        return [
            'id'                 => $exam->id,
            'title'              => $exam->title,
            'description'        => $exam->description,
            'subject'            => $exam->subject,
            'exam_type'          => $exam->exam_type,
            'academic_year'      => $exam->academic_year,
            'semester'           => $exam->semester,
            'duration_minutes'   => $exam->duration_minutes,
            'start_at'           => $exam->start_at,
            'end_at'             => $exam->end_at,
            'max_attempts'       => $exam->max_attempts,
            'extra_attempts'     => $extra,
            'max_attempts_effective' => (int) $exam->max_attempts + $extra,
            'retake_allowed'     => $studentId !== null && $this->retakeIsOpen($exam, $studentId),
            'retake_available_until' => $override && $extra > 0 ? $override->available_until : null,
            'passing_score'      => $exam->passing_score !== null ? (float) $exam->passing_score : null,
            'total_marks'        => (float) $exam->total_marks,
            // Round 7 — عدد الأسئلة "المعلن" (نفسه لكل طالب دايمًا: يدوي +
            // مجموع questions_to_select لكل الـ pools)، مش عدد صفوف
            // exam_questions الفعلي (اللي بيكبر مع كل طالب يبدأ محاولة
            // ويسحب أسئلة جديدة من أي pool — راجع docblock ExamSystemService::examDetail()).
            'question_count'     => count($this->exams->manualQuestionsFor($exam->id))
                + array_sum(array_map(fn ($c) => (int) $c->questions_to_select, $this->exams->poolConfigsFor($exam->id))),
            'instructions'       => $exam->instructions,
            'result_visibility'  => $exam->result_visibility,
            'secure_mode_enabled' => (bool) $exam->secure_mode_enabled,
            'max_violations'     => $exam->max_violations !== null ? (int) $exam->max_violations : null,
            'status'             => $exam->status,
            'created_at'         => $exam->created_at,
            'allow_back_navigation' => (bool) $exam->allow_back_navigation,
            'auto_submit_on_timeout' => (bool) $exam->auto_submit_on_timeout,
            'show_answer_review' => (bool) $exam->show_answer_review,
            'show_score_only'    => (bool) $exam->show_score_only,
            // سياسة التسليم المتأخر — الطالب لازم يعرفها قبل ما يبدأ.
            'late_grace_minutes'   => (int) $exam->late_grace_minutes,
            'late_penalty_percent' => (float) $exam->late_penalty_percent,
            // الكاميرا/الهوية/الجلسة الواحدة — الطالب يعرفها قبل البدء (الإعفاء الفردي بيتحدد وقت البدء).
            'proctoring_mode'         => $exam->identity_check_required ? 'required' : (string) ($exam->proctoring_mode ?: 'off'),
            'identity_check_required' => (bool) $exam->identity_check_required,
            'single_session_enabled'  => (bool) $exam->single_session_enabled,
            // الامتحان محمي بباسورد؟ (الباسورد نفسه عمره ما بيترجع).
            'requires_password'       => (bool) $exam->has_access_password,
            'attempts_used'      => count($mine),
            // نفس قاعدة result_visibility اللي بتحكم صفحة النتيجة: الدرجة ما تتبعتش للطالب قبل ما تبقى ظاهرة له.
            'result_visible'     => $resultVisible,
            'results_published_at' => $exam->results_published_at,
            'last_attempt'       => $last ? [
                'id'         => $last->id,
                'status'     => $last->status,
                'score'      => $resultVisible && $last->score !== null ? (float) $last->score : null,
                'percentage' => $resultVisible && $last->percentage !== null ? (float) $last->percentage : null,
            ] : null,
        ];
    }

    /** نفس منطق ExamGradingService::isResultVisibleTo() على آخر محاولة للطالب. */
    private function resultVisibleFor(Exam $exam, $attempt): bool
    {
        if (!$attempt || $attempt->status !== 'graded') {
            return false;
        }

        return match ($exam->result_visibility) {
            'immediate'   => true,
            'after_close' => $exam->end_at === null || now()->greaterThanOrEqualTo($exam->end_at),
            'manual'      => $exam->results_published_at !== null && now()->greaterThanOrEqualTo($exam->results_published_at),
            default       => false,
        };
    }
}
