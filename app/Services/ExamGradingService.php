<?php

namespace App\Services;

use App\Jobs\GradeEssayAnswerWithAiJob;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamGrade;
use App\Repositories\ExamAiGradingRepository;
use App\Repositories\ExamAttemptRepository;
use App\Repositories\ExamGradeRepository;
use App\Repositories\ExamRepository;
use App\Repositories\ExamRubricRepository;
use App\Repositories\StudentRepository;
use App\Models\User;

/**
 * Exam & Assessment System — Round 4 (Grading Core, Phases 17/22)، موسّعة
 * في Round 6 (AI Grading, Phases 18/19/21). سطح الخدمة اللي بيغطي: تصحيح
 * أوتوماتيك للأسئلة الموضوعية (mcq/multi_select/true_false)، تصحيح يدوي
 * للمدرس لأي سؤال (بما فيها override)، تصحيح essay/short_answer بالـ AI
 * (Round 6)، Hybrid grading (accept/override على درجة الـ AI)، سجل تاريخ
 * الدرجات (exam_grade_history)، وحساب ظهور النتيجة للطالب حسب
 * result_visibility.
 *
 * finalizeSubmission() هي نقطة الدخول اللي ExamAttemptService بينادّيها
 * فورًا لما محاولة توصل submitted/auto_submitted — بتعمل صف exam_grades
 * لكل سؤال في الامتحان (pending للـ subjective، مصحح فورًا للـ objective)،
 * وبتودّي (Round 6) أي سؤال essay/short_answer بـ ai_grading_enabled=true
 * ومعاه إجابة فعلية لـ GradeEssayAnswerWithAiJob على الـ queue (Phase 38 —
 * "do not block the entire application" — التصحيح الأوتوماتيك للموضوعي
 * بيحصل sync في نفس الـ request زي الأول، بس AI grading بطبيعته أبطأ
 * وبيعتمد على مزوّد خارجي فمتعمّد يكون async). المحاولة بتفضل status=grading
 * لحد ما كل الـ AI jobs تخلص (recomputeAttemptTotals() هي اللي بتقرر ده،
 * بتتنادى تاني من جوه runAiGrading() لما job يخلص).
 *
 * Round 8 (Phase 29/42 — Notifications + Audit Trail): notifyGradeChanged()
 * وauditLog->record() بيتنادوا من جوه gradeManually()/acceptAiGrade()/
 * regradeQuestionWithAi()/publishResults() بالظبط (مش من الكنترولر) —
 * نفس قرار ExamSystemService::publishExam() فوق. الإشعار بيتبعت للطالب
 * بس لو الدرجة دي *override* على درجة موجودة فعلًا (previousScore !== null)
 * ونتيجة الامتحان أصلًا منشورة له (وإلا هيبقى إشعار عن درجة الطالب لسه
 * ماشافهاش، وده بيكسر result_visibility). publishResults() نفسها هي
 * اللي بتبعت 'exam_result_published' لكل طالب معاه محاولة graded.
 */
class ExamGradingService
{
    public function __construct(
        private ExamGradeRepository $grades,
        private ExamAttemptRepository $attempts,
        private ExamRepository $exams,
        private ExamRubricRepository $rubrics,
        private ExamAiGradingRepository $aiGradings,
        private AiExamGradingService $aiGrading,
        private StudentRepository $students,
        private NotificationService $notifications,
        private AuditLogService $auditLog
    ) {
    }

    // -----------------------------------------------------------------
    // Submission -> grading pipeline
    // -----------------------------------------------------------------

    /** بتتنادى فورًا لما محاولة تتسلم (يدوي أو أوتوماتيك) — بتنشئ/تحدّث صف تصحيح لكل سؤال، وبتحسب إجمالي الدرجة لو خلص كله. */
    public function finalizeSubmission(ExamAttempt $attempt): void
    {
        // Round 7 — أسئلة المحاولة دي بالذات (attempt-scoped)، مش كل
        // أسئلة الامتحان — عدد/هوية الأسئلة ممكن يختلف من طالب لطالب تحت
        // question pools (راجع ExamAttemptRepository::questionsForAttempt()).
        $pivotRows = $this->attempts->questionsForAttempt($attempt->id);
        $answers = $this->attempts->answersForAttempt($attempt->id);

        foreach ($pivotRows as $pivot) {
            $question = $pivot->question;
            $answer = $answers[$pivot->id] ?? null;
            $maxMarks = (float) ($pivot->marks_override ?? $question->marks);

            $existing = $this->grades->findForQuestion($attempt->id, $pivot->id);
            if ($existing && $existing->source === 'instructor') {
                // Already manually graded/overridden by the instructor — never clobber that here.
                continue;
            }

            if (in_array($question->type, ['mcq', 'multi_select', 'true_false'], true)) {
                $this->gradeAutomatic($attempt, $pivot, $question, $answer, $maxMarks);
            } elseif (!$existing && $this->isBlankAnswer($answer)) {
                // Blank written answer: nothing for the AI (or the instructor) to evaluate — it scores 0 right away.
                $this->gradeUnanswered($attempt, $pivot, $answer, $maxMarks);
            } elseif (!$existing) {
                // Subjective question, no grade yet — placeholder row so it shows up as "pending" for the instructor.
                $grade = $this->grades->upsert($attempt->id, $pivot->id, [
                    'exam_answer_id' => $answer?->id,
                    'marks_awarded'  => null,
                    'max_marks'      => $maxMarks,
                    'is_correct'     => null,
                    'source'         => null,
                ]);
                // Round 6 — essay/short_answer with AI grading configured and an actual
                // answer get queued for AI grading; everything else (blank answers,
                // file_upload, AI disabled) stays pending for the instructor exactly
                // like Round 4.
                $this->maybeDispatchAiGrading($question, $answer, $grade);
            }
        }

        $this->recomputeAttemptTotals($attempt);
    }

    /** "Auto Grade" — يعيد تشغيل التصحيح الأوتوماتيك للأسئلة الموضوعية بس (من غير ما يلمس أي درجة عدّلها المدرس يدويًا). */
    public function autoGradeAttempt(ExamAttempt $attempt): int
    {
        // Round 7 — attempt-scoped (راجع ملاحظة finalizeSubmission() فوق).
        $pivotRows = $this->attempts->questionsForAttempt($attempt->id);
        $answers = $this->attempts->answersForAttempt($attempt->id);
        $count = 0;

        foreach ($pivotRows as $pivot) {
            $question = $pivot->question;
            if (!in_array($question->type, ['mcq', 'multi_select', 'true_false'], true)) {
                continue;
            }
            $existing = $this->grades->findForQuestion($attempt->id, $pivot->id);
            if ($existing && $existing->source === 'instructor') {
                continue;
            }

            $maxMarks = (float) ($pivot->marks_override ?? $question->marks);
            $answer = $answers[$pivot->id] ?? null;
            $this->gradeAutomatic($attempt, $pivot, $question, $answer, $maxMarks);
            $count++;
        }

        $this->recomputeAttemptTotals($attempt);
        return $count;
    }

    /**
     * "Auto Grade" الكامل: الأسئلة الموضوعية + تصحيح الـ AI للأسئلة
     * المقالية/القصيرة فورًا (synchronous — من غير queue worker) عشان الدرجة
     * تظهر في نفس الطلب. بيتخطى أي سؤال المدرس صححه يدويًا.
     *
     * @return array{objective:int,ai_graded:int,ai_failed:int,ai_skipped_disabled:int,ai_skipped_empty:int,ai_skipped_instructor:int,errors:string[]}
     */
    public function autoGradeAttemptFull(ExamAttempt $attempt): array
    {
        $summary = [
            'objective' => $this->autoGradeAttempt($attempt),
            'ai_graded' => 0, 'ai_failed' => 0,
            'ai_skipped_disabled' => 0, 'ai_skipped_empty' => 0, 'ai_skipped_instructor' => 0, 'zeroed_empty' => 0,
            'errors' => [],
        ];

        $pivotRows = $this->attempts->questionsForAttempt($attempt->id);
        $answers = $this->attempts->answersForAttempt($attempt->id);

        foreach ($pivotRows as $pivot) {
            $question = $pivot->question;
            if (!in_array($question->type, ['short_answer', 'essay'], true)) {
                continue;
            }
            $existing = $this->grades->findForQuestion($attempt->id, $pivot->id);
            if ($existing && $existing->source === 'instructor') {
                $summary['ai_skipped_instructor']++;
                continue;
            }
            // الضغط الصريح على "إعادة التصحيح التلقائي" بيصحح بالـ AI حتى لو
            // خيار ai_grading_enabled مش متفعّل على السؤال (الخيار ده بيتحكم
            // بس في التصحيح التلقائي وقت التسليم).
            $answer = $answers[$pivot->id] ?? null;
            if ($this->isBlankAnswer($answer)) {
                // لو لسه من غير درجة (أو درجة تلقائية قديمة) → صفر. أي درجة تانية (AI/مدرس) مبتتلمسش.
                if (!$existing || $existing->marks_awarded === null || $existing->source === 'automatic') {
                    $this->gradeUnanswered($attempt, $pivot, $answer, (float) ($pivot->marks_override ?? $question->marks));
                    $summary['zeroed_empty']++;
                } else {
                    $summary['ai_skipped_empty']++;
                }
                continue;
            }

            $grade = $this->grades->upsert($attempt->id, $pivot->id, [
                'exam_answer_id' => $answer->id,
                'max_marks'      => (float) ($pivot->marks_override ?? $question->marks),
            ]);
            $this->runAiGrading($grade->id);

            $ai = $this->aiGradings->findForGrade($grade->id);
            if ($ai && $ai->status === 'completed') {
                $summary['ai_graded']++;
            } else {
                $summary['ai_failed']++;
                if ($ai && $ai->error_message) {
                    $summary['errors'][] = $ai->error_message;
                }
            }
        }

        $attempt->refresh();
        $this->recomputeAttemptTotals($attempt);
        $summary['errors'] = array_values(array_unique($summary['errors']));
        return $summary;
    }

    private function isBlankAnswer($answer): bool
    {
        return !$answer || trim((string) $answer->answer_text) === '';
    }

    /** إجابة كتابية فاضية = صفر تلقائي (source=automatic، is_correct=false). المدرس لسه يقدر يعدّلها. */
    private function gradeUnanswered(ExamAttempt $attempt, $pivot, $answer, float $maxMarks): ExamGrade
    {
        $previous = $this->grades->findForQuestion($attempt->id, $pivot->id);
        $grade = $this->grades->upsert($attempt->id, $pivot->id, [
            'exam_answer_id' => $answer?->id,
            'marks_awarded'  => 0.0,
            'max_marks'      => $maxMarks,
            'is_correct'     => false,
            'source'         => 'automatic',
            'graded_by_academic_staff_id' => null,
            'graded_at'      => now(),
        ]);
        $this->grades->logHistory([
            'exam_grade_id'  => $grade->id,
            'previous_score' => $previous && $previous->marks_awarded !== null ? (float) $previous->marks_awarded : null,
            'new_score'      => 0.0,
            'source'         => 'automatic',
            'changed_by_academic_staff_id' => null,
            'reason'         => 'No answer submitted',
            'changed_at'     => now(),
        ]);
        return $grade;
    }

    private function gradeAutomatic(ExamAttempt $attempt, $pivot, $question, $answer, float $maxMarks): ExamGrade
    {
        $isCorrect = $this->evaluateObjectiveAnswer($question, $answer);
        $marksAwarded = $isCorrect ? $maxMarks : 0.0;

        $grade = $this->grades->upsert($attempt->id, $pivot->id, [
            'exam_answer_id' => $answer?->id,
            'marks_awarded'  => $marksAwarded,
            'max_marks'      => $maxMarks,
            'is_correct'     => $isCorrect,
            'source'         => 'automatic',
            'graded_by_academic_staff_id' => null,
            'graded_at'      => now(),
        ]);

        $this->grades->logHistory([
            'exam_grade_id' => $grade->id,
            'previous_score' => null,
            'new_score'      => $marksAwarded,
            'source'         => 'automatic',
            'changed_by_academic_staff_id' => null,
            'reason'         => null,
            'changed_at'     => now(),
        ]);

        return $grade;
    }

    /** MCQ/multi_select: تطابق تام لمجموعة الاختيارات الصح (all-or-nothing — مفيش partial credit في Round 4). True/false: تطابق نصي. إجابة فاضية = غلط. */
    private function evaluateObjectiveAnswer($question, $answer): bool
    {
        if (!$answer) {
            return false;
        }

        if ($question->type === 'true_false') {
            $given = \App\Support\TrueFalse::normalize($answer->answer_text);
            $expected = \App\Support\TrueFalse::normalize($question->correct_answer);
            return $given !== null && $given === $expected;
        }

        $selected = collect($answer->selected_option_ids ?? [])->map(fn ($id) => (int) $id)->sort()->values();
        $correct = $question->options->where('is_correct', true)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values();

        return $selected->count() > 0 && $selected->all() === $correct->all();
    }

    // -----------------------------------------------------------------
    // Manual grading (instructor)
    // -----------------------------------------------------------------

    /**
     * تصحيح يدوي لسؤال واحد جوه محاولة — بيشتغل مع أي نوع سؤال (بما فيها
     * override على تصحيح أوتوماتيك). كل نداء بيسجل سطر في exam_grade_history
     * (Phase 22 — "Never silently overwrite grades").
     * @throws \InvalidArgumentException
     */
    public function gradeManually(ExamAttempt $attempt, $examQuestionId, float $marksAwarded, ?string $feedback, $staff, ?string $reason = null): ExamGrade
    {
        if (in_array($attempt->status, ['in_progress', 'cancelled'], true)) {
            throw new \InvalidArgumentException('This attempt has not been submitted yet.');
        }

        // Round 7 — attempt-scoped (نفس منطق finalizeSubmission()).
        $pivot = $this->attempts->pivotForAttempt($attempt->id, $examQuestionId);
        if (!$pivot) {
            throw new \InvalidArgumentException('Question not found in this attempt.');
        }

        $existing = $this->grades->findForQuestion($attempt->id, $examQuestionId);
        $maxMarks = $existing?->max_marks !== null ? (float) $existing->max_marks : (float) ($pivot->marks_override ?? $pivot->question->marks);

        if ($marksAwarded < 0 || $marksAwarded > $maxMarks) {
            throw new \InvalidArgumentException("Marks awarded must be between 0 and {$maxMarks}.");
        }

        $previousScore = $existing?->marks_awarded !== null ? (float) $existing->marks_awarded : null;
        $previousFeedback = $existing?->feedback;
        $answer = $this->attempts->findAnswer($attempt->id, $examQuestionId);

        $grade = $this->grades->upsert($attempt->id, $examQuestionId, [
            'exam_answer_id' => $answer?->id,
            'marks_awarded'  => $marksAwarded,
            'max_marks'      => $maxMarks,
            'feedback'       => $feedback,
            'source'         => 'instructor',
            'graded_by_academic_staff_id' => $staff->id,
            'graded_at'      => now(),
        ]);

        $this->grades->logHistory([
            'exam_grade_id'  => $grade->id,
            'previous_score' => $previousScore,
            'new_score'      => $marksAwarded,
            'source'         => 'instructor',
            'changed_by_academic_staff_id' => $staff->id,
            'reason'         => $reason,
            'changed_at'     => now(),
            'previous_feedback' => $previousFeedback,
            'new_feedback'   => $feedback,
        ]);

        $this->recomputeAttemptTotals($attempt);

        $exam = $this->exams->find($attempt->exam_id);
        $this->auditLog->record(
            $staff->user_id,
            $previousScore !== null ? 'academic_staff.exam_system.grade_overridden' : 'academic_staff.exam_system.grade_changed',
            'ExamGrade',
            $grade->id,
            $previousScore !== null ? ['marks_awarded' => $previousScore] : null,
            ['marks_awarded' => $marksAwarded, 'reason' => $reason]
        );

        // Phase 29 — بس لو الدرجة القديمة دي كانت موجودة *ونتيجة الامتحان
        // منشورة أصلًا للطالب*؛ غير كده الطالب لسه ماشافش أي درجة، فمفيش
        // "تغيير" يتقال له عنه (راجع docblock الكلاس فوق).
        if ($previousScore !== null && $exam && $exam->results_published_at) {
            $student = $this->students->find($attempt->student_id);
            if ($student) {
                $this->notifications->notify(
                    $student->user_id,
                    'exam_grade_changed',
                    'Grade updated: ' . $exam->title,
                    'One of your answers in "' . $exam->title . '" was regraded.',
                    null,
                    'normal'
                );
            }
        }

        return $grade;
    }

    /** @return \App\Models\ExamGradeHistory[] */
    public function historyFor(ExamGrade $grade): array
    {
        return $this->grades->historyFor($grade->id);
    }

    // -----------------------------------------------------------------
    // AI grading (Round 6 — Phases 18/19/21/38)
    // -----------------------------------------------------------------

    /**
     * Queues AI grading for one subjective answer, if it's actually eligible
     * (essay/short_answer, question has ai_grading_enabled, and the student
     * left a non-empty answer). Silently no-ops otherwise — those questions
     * simply stay pending for the instructor exactly like Round 4 behaved
     * before AI grading existed. Called from finalizeSubmission() (first
     * submit) and regradeQuestionWithAi() (explicit re-run).
     */
    private function maybeDispatchAiGrading($question, $answer, ExamGrade $grade): void
    {
        if (!in_array($question->type, ['short_answer', 'essay'], true)) {
            return;
        }
        if (!$question->ai_grading_enabled) {
            return;
        }
        if (!$answer || trim((string) $answer->answer_text) === '') {
            return;
        }

        $this->aiGradings->upsertForGrade($grade->id, [
            'status'       => 'queued',
            'requested_at' => now(),
        ]);

        GradeEssayAnswerWithAiJob::dispatch($grade->id);
    }

    /**
     * The actual AI grading work — dispatched via GradeEssayAnswerWithAiJob,
     * but callable directly too (e.g. from a `sync` queue driver or a test).
     * Never lets an AI failure produce a fake grade: on any error the
     * exam_grades row is left untouched (marks_awarded stays null/pending)
     * and exam_ai_gradings.status is set to 'failed' with the reason, so the
     * instructor can grade manually. Skips entirely (no-op) if an instructor
     * already graded/overrode this question by the time the job runs — the
     * Round 4 "never clobber a manual grade" rule extends to AI here too.
     */
    public function runAiGrading(int $examGradeId): void
    {
        $grade = $this->grades->find($examGradeId);
        if (!$grade || $grade->source === 'instructor') {
            return;
        }

        $attempt = $this->attempts->find($grade->exam_attempt_id);
        if (!$attempt) {
            return;
        }

        // Round 7 — attempt-scoped.
        $pivot = $this->attempts->pivotForAttempt($attempt->id, $grade->exam_question_id);
        $question = $pivot?->question;
        $answer = $this->attempts->findAnswer($attempt->id, $grade->exam_question_id);

        if (!$question || !$answer || trim((string) $answer->answer_text) === '') {
            $this->aiGradings->upsertForGrade($grade->id, [
                'status'        => 'failed',
                'error_message' => 'No answer text available to grade.',
                'completed_at'  => now(),
            ]);
            return;
        }

        $this->aiGradings->upsertForGrade($grade->id, [
            'status'       => 'processing',
            'requested_at' => now(),
        ]);

        $rubric = $this->rubrics->findForQuestion($question->id);
        $criteria = $rubric ? $rubric->criteria->all() : [];

        try {
            $result = $this->aiGrading->grade($question, (string) $answer->answer_text, (float) $grade->max_marks, $criteria);
        } catch (\Throwable $e) {
            $this->aiGradings->upsertForGrade($grade->id, [
                'status'        => 'failed',
                'error_message' => substr($e->getMessage(), 0, 1000),
                'completed_at'  => now(),
            ]);
            return;
        }

        $previousScore = $grade->marks_awarded !== null ? (float) $grade->marks_awarded : null;
        $previousFeedback = $grade->feedback;

        $grade = $this->grades->upsert($attempt->id, $grade->exam_question_id, [
            'exam_answer_id' => $answer->id,
            'marks_awarded'  => $result['marks_awarded'],
            'max_marks'      => (float) $grade->max_marks,
            'feedback'       => $result['feedback'],
            'source'         => 'ai',
            'graded_by_academic_staff_id' => null,
            'graded_at'      => now(),
        ]);

        $this->grades->logHistory([
            'exam_grade_id'  => $grade->id,
            'previous_score' => $previousScore,
            'new_score'      => $result['marks_awarded'],
            'source'         => 'ai',
            'changed_by_academic_staff_id' => null,
            'reason'         => 'AI grading',
            'changed_at'     => now(),
            'previous_feedback' => $previousFeedback,
            'new_feedback'   => $result['feedback'],
        ]);

        $this->aiGradings->upsertForGrade($grade->id, [
            'status'            => 'completed',
            'marks_awarded'     => $result['marks_awarded'],
            'max_marks'         => (float) $grade->max_marks,
            'confidence'        => $result['confidence'],
            'feedback'          => $result['feedback'],
            'strengths'         => $result['strengths'],
            'missing_concepts'  => $result['missing_concepts'],
            'reasoning_summary' => $result['reasoning_summary'],
            'completed_at'      => now(),
        ]);

        $this->recomputeAttemptTotals($attempt);
    }

    /**
     * Hybrid grading (Phase 21) — "Accept AI grade": instructor confirms the
     * AI's score as-is without changing the number. Distinct from
     * gradeManually() (which is "Modify"/"Override" — a different number)
     * even though both end up with source='instructor'; the
     * exam_grade_history reason makes the distinction auditable.
     * @throws \InvalidArgumentException لو مفيش درجة AI معلّقة تتقبل
     */
    public function acceptAiGrade(ExamGrade $grade, $staff): ExamGrade
    {
        if ($grade->source !== 'ai' || $grade->marks_awarded === null) {
            throw new \InvalidArgumentException('This question does not have a pending AI grade to accept.');
        }

        $attempt = $this->attempts->find($grade->exam_attempt_id);
        $marks = (float) $grade->marks_awarded;

        $grade->source = 'instructor';
        $grade->graded_by_academic_staff_id = $staff->id;
        $grade->graded_at = now();
        $grade->save();

        $this->grades->logHistory([
            'exam_grade_id'  => $grade->id,
            'previous_score' => $marks,
            'new_score'      => $marks,
            'source'         => 'instructor',
            'changed_by_academic_staff_id' => $staff->id,
            'reason'         => 'AI grade accepted as-is by instructor.',
            'changed_at'     => now(),
            'previous_feedback' => $grade->feedback,
            'new_feedback'   => $grade->feedback,
        ]);

        $this->recomputeAttemptTotals($attempt);

        $this->auditLog->record(
            $staff->user_id,
            'academic_staff.exam_system.ai_grade_accepted',
            'ExamGrade',
            $grade->id,
            null,
            ['marks_awarded' => $marks]
        );

        return $grade;
    }

    /**
     * Phase 23 (scoped to AI-gradable questions only for Round 6) — explicit
     * re-run of AI grading for one question, e.g. after the instructor edits
     * the model answer/rubric. Re-queues the same exam_grades row (marks
     * stay as they were until the job completes — the previous score/history
     * entry is never destroyed, only ever appended to).
     * @throws \InvalidArgumentException
     */
    public function regradeQuestionWithAi(ExamAttempt $attempt, $examQuestionId, $staff = null, bool $sync = false): ExamGrade
    {
        // Round 7 — attempt-scoped.
        $pivot = $this->attempts->pivotForAttempt($attempt->id, $examQuestionId);
        if (!$pivot) {
            throw new \InvalidArgumentException('Question not found in this attempt.');
        }
        $question = $pivot->question;
        if (!in_array($question->type, ['short_answer', 'essay'], true) || (!$sync && !$question->ai_grading_enabled)) {
            throw new \InvalidArgumentException('AI grading is not enabled for this question.');
        }

        $answer = $this->attempts->findAnswer($attempt->id, $examQuestionId);
        if (!$answer || trim((string) $answer->answer_text) === '') {
            throw new \InvalidArgumentException('This question has no student answer to grade.');
        }

        $maxMarks = (float) ($pivot->marks_override ?? $question->marks);
        $grade = $this->grades->upsert($attempt->id, $examQuestionId, [
            'exam_answer_id' => $answer->id,
            'max_marks'      => $maxMarks,
        ]);

        if ($sync) {
            // تشغيل فوري (من غير queue worker) عشان الدرجة تظهر في نفس الطلب.
            $this->runAiGrading($grade->id);
        } else {
            $this->maybeDispatchAiGrading($question, $answer, $grade);
        }

        // Phase 42 — "Exam Regraded". staff اختياري (nullable) عشان مسار
        // regradeQuestionWithAi() اللي بيتنادى من مسارات مش-مستخدم-مباشر
        // (لو وُجدت مستقبلًا) يفضل شغال من غير كسر — لو null بيتسجل
        // بدون actor (نفس سلوك AuditLogService::record() الطبيعي).
        $this->auditLog->record(
            $staff?->user_id,
            'academic_staff.exam_system.exam_regraded',
            'ExamGrade',
            $grade->id,
            null,
            ['exam_question_id' => (int) $examQuestionId, 'attempt_id' => $attempt->id]
        );

        return $grade->fresh();
    }

    /** Round 6 — تشخيص AI الخام لصف تصحيح واحد (للمدرس، Phase 18). null لو مفيش AI grading اتشغل عليه أصلًا. */
    public function aiGradingFor(ExamGrade $grade): ?\App\Models\ExamAiGrading
    {
        return $this->aiGradings->findForGrade($grade->id);
    }

    // -----------------------------------------------------------------
    // Totals
    // -----------------------------------------------------------------

    private function recomputeAttemptTotals(ExamAttempt $attempt): void
    {
        // Round 7 — عدد أسئلة المحاولة دي بالذات، مش كل أسئلة الامتحان
        // (ممكن يختلف من طالب لطالب تحت question pools).
        $questionCount = $this->attempts->questionCountForAttempt($attempt->id);
        $fullyGraded = $this->grades->isFullyGraded($attempt->id, $questionCount);

        if ($fullyGraded) {
            $exam = $this->exams->find($attempt->exam_id);
            $raw = (float) $this->grades->sumAwarded($attempt->id);
            // سياسة التسليم المتأخر: نسبة الخصم اللي اتلقطت وقت التسليم بتتخصم من المجموع، والدرجة الخام
            // بتتحفظ في score_before_penalty عشان الطالب والمدرس يشوفوا الفرق. بتتحسب من الصفر في كل مرة
            // (تعديل درجة سؤال بعد كده مش بيخصم مرتين).
            $penalty = $attempt->is_late ? (float) ($attempt->late_penalty_percent ?? 0) : 0.0;
            if ($penalty > 0) {
                $attempt->score_before_penalty = $raw;
                $score = round($raw * (1 - min(100.0, $penalty) / 100), 2);
            } else {
                $attempt->score_before_penalty = null;
                $score = $raw;
            }
            $attempt->score = $score;
            $attempt->percentage = $exam && (float) $exam->total_marks > 0
                ? round(($score / (float) $exam->total_marks) * 100, 2)
                : 0;
            $attempt->status = 'graded';
        } elseif (in_array($attempt->status, ['submitted', 'auto_submitted', 'grading'], true)) {
            $attempt->status = 'grading';
            $attempt->score = null;
            $attempt->percentage = null;
        }

        $attempt->save();
    }

    // -----------------------------------------------------------------
    // Instructor views
    // -----------------------------------------------------------------

    /** @return array<int,array<string,mixed>> ملخص كل محاولات امتحان واحد — للمدرس. */
    public function listAttemptsForExam(Exam $exam): array
    {
        $rows = $this->attempts->forExamWithStudentInfo($exam->id);
        $gradedCounts = $this->grades->gradedCountsForExam($exam->id);
        // Round 7 — عدد الأسئلة ممكن يختلف من محاولة لمحاولة تحت question
        // pools، فمبقاش رقم ثابت واحد لكل الامتحان — keyed بـ attempt_id
        // (راجع ExamAttemptRepository::questionCountsForExam()).
        $questionCounts = $this->attempts->questionCountsForExam($exam->id);
        // إعادة الامتحان بصلاحية المدرس — استثناء كل طالب (extra_attempts/available_until)
        // + عدد محاولاته الفعلي، عشان صفحة المدرس تعرض "متبقي كام" وتفعّل/تعطّل أزرار الإعادة.
        $overrides = $this->attempts->overridesForExam($exam->id);
        $usedByStudent = [];
        foreach ($rows as $r) {
            $usedByStudent[$r->student_id] = ($usedByStudent[$r->student_id] ?? 0) + 1;
        }

        return array_map(fn ($row) => [
            'id'              => $row->id,
            'student_id'      => $row->student_id,
            'student_number'  => $row->student_number,
            'student_name'    => $row->full_name,
            'student_email'   => $row->email,
            'attempt_number'  => $row->attempt_number,
            'status'          => $row->status,
            'auto_submitted'  => (bool) $row->auto_submitted,
            'started_at'      => $row->started_at,
            'submitted_at'    => $row->submitted_at,
            'score'           => $row->score !== null ? (float) $row->score : null,
            'percentage'      => $row->percentage !== null ? (float) $row->percentage : null,
            'violations_count' => (int) $row->violations_count,
            'is_late'         => (bool) $row->is_late,
            'extra_time_minutes' => (int) $row->extra_time_minutes,
            'late_penalty_percent' => $row->is_late && $row->late_penalty_percent !== null ? (float) $row->late_penalty_percent : null,
            'score_before_penalty' => $row->score_before_penalty !== null ? (float) $row->score_before_penalty : null,
            'graded_count'    => $gradedCounts[$row->id] ?? 0,
            'total_questions' => $questionCounts[$row->id] ?? 0,
            'cancelled_at'    => $row->cancelled_at,
            'cancel_reason'   => $row->cancel_reason,
            'student_attempts_used'  => $usedByStudent[$row->student_id] ?? 0,
            'identity_status' => $row->identity_status ?: 'none',
            'proctoring_flags_count' => (int) $row->proctoring_flags_count,
            'session_claims_count' => (int) $row->session_claims_count,
            'student_proctoring_waived' => isset($overrides[$row->student_id]) && (bool) $overrides[$row->student_id]->proctoring_waived,
            'student_extra_attempts' => isset($overrides[$row->student_id]) ? (int) $overrides[$row->student_id]->extra_attempts : 0,
            'student_available_until' => isset($overrides[$row->student_id]) ? $overrides[$row->student_id]->available_until : null,
        ], $rows);
    }

    /** التفاصيل الكاملة لمحاولة واحدة — للمدرس، بما فيها الإجابة الصح والدرجة الحالية لكل سؤال. */
    public function attemptDetailForInstructor(ExamAttempt $attempt): array
    {
        // Round 7 — attempt-scoped.
        $pivotRows = $this->attempts->questionsForAttempt($attempt->id);
        $answers = $this->attempts->answersForAttempt($attempt->id);
        $grades = $this->grades->forAttempt($attempt->id);

        $questions = array_map(function ($pivot) use ($answers, $grades) {
            $question = $pivot->question;
            $answer = $answers[$pivot->id] ?? null;
            $grade = $grades[$pivot->id] ?? null;
            $isAiGradable = in_array($question->type, ['short_answer', 'essay'], true);

            return [
                'exam_question_id' => $pivot->id,
                'type'             => $question->type,
                'prompt'           => $question->prompt,
                'image_url'        => $question->image_url,
                'max_marks'        => (float) ($pivot->marks_override ?? $question->marks),
                'options'          => $question->optionsForDisplay(),
                'correct_answer'   => $question->type === 'true_false' ? $question->correct_answer : null,
                'student_answer'   => $answer ? [
                    'selected_option_ids' => $answer->selected_option_ids,
                    'answer_text'         => $answer->answer_text,
                    'answered_at'         => $answer->answered_at,
                ] : null,
                'grade'            => $grade ? [
                    'id'             => $grade->id,
                    'marks_awarded'  => $grade->marks_awarded !== null ? (float) $grade->marks_awarded : null,
                    'is_correct'     => $grade->is_correct,
                    'feedback'       => $grade->feedback,
                    'source'         => $grade->source,
                    'graded_at'      => $grade->graded_at,
                ] : null,
                // Round 6 — grading guidance + AI diagnostic, instructor-only (never sent to students).
                'model_answer'     => $isAiGradable ? ($question->model_answer ?: $question->ai_model_answer) : null,
                'grading_instructions' => $isAiGradable ? $question->grading_instructions : null,
                'ai_grading_enabled'   => $isAiGradable ? (bool) $question->ai_grading_enabled : false,
                'rubric'           => $isAiGradable ? $this->rubricSummary($question) : null,
                'ai_grading'       => ($isAiGradable && $grade) ? $this->aiGradingSummary($grade) : null,
            ];
        }, $pivotRows);

        $exam = $attempt->exam;
        $student = $attempt->student;
        $studentUser = $student ? User::find($student->user_id) : null;

        return [
            'id'             => $attempt->id,
            'exam_id'        => $attempt->exam_id,
            'attempt_number' => $attempt->attempt_number,
            'status'         => $attempt->status,
            'score'          => $attempt->score !== null ? (float) $attempt->score : null,
            'percentage'     => $attempt->percentage !== null ? (float) $attempt->percentage : null,
            'started_at'     => $attempt->started_at,
            'submitted_at'   => $attempt->submitted_at,
            'is_late'        => (bool) $attempt->is_late,
            'late_penalty_percent' => $attempt->is_late && $attempt->late_penalty_percent !== null ? (float) $attempt->late_penalty_percent : null,
            'score_before_penalty' => $attempt->score_before_penalty !== null ? (float) $attempt->score_before_penalty : null,
            'extra_time_minutes'   => (int) $attempt->extra_time_minutes,
            // Round 5/6 — the instructor grading screen (header + security
            // card + AI review) needs the exam's title/secure-mode settings
            // and the student's display name alongside the attempt itself,
            // so they don't need a second round-trip to GET exams/{id}.
            'violations_count' => (int) $attempt->violations_count,
            'exam'           => $exam ? [
                'id'                   => $exam->id,
                'title'                => $exam->title,
                'total_marks'          => $exam->total_marks !== null ? (float) $exam->total_marks : null,
                'secure_mode_enabled'  => (bool) $exam->secure_mode_enabled,
                'max_violations'       => $exam->max_violations,
            ] : null,
            'student'        => $student ? [
                'id'             => $student->id,
                'name'           => $studentUser->full_name ?? null,
                'student_number' => $student->student_number,
            ] : null,
            'questions'      => $questions,
        ];
    }

    /** @return array{id:int,total_points:float,criteria:array<int,array{id:int,label:string,max_points:float}>}|null */
    private function rubricSummary($question): ?array
    {
        $rubric = $this->rubrics->findForQuestion($question->id);
        if (!$rubric) {
            return null;
        }
        return [
            'id'           => $rubric->id,
            'total_points' => $rubric->totalPoints(),
            'criteria'     => $rubric->criteria->map(fn ($c) => [
                'id'         => $c->id,
                'label'      => $c->label,
                'max_points' => (float) $c->max_points,
            ])->all(),
        ];
    }

    /** @return array<string,mixed>|null */
    private function aiGradingSummary(ExamGrade $grade): ?array
    {
        $ai = $this->aiGradings->findForGrade($grade->id);
        if (!$ai) {
            return null;
        }
        return [
            'status'             => $ai->status,
            'marks_awarded'      => $ai->marks_awarded !== null ? (float) $ai->marks_awarded : null,
            'confidence'         => $ai->confidence !== null ? (float) $ai->confidence : null,
            'feedback'           => $ai->feedback,
            'strengths'          => $ai->strengths,
            'missing_concepts'   => $ai->missing_concepts,
            'reasoning_summary'  => $ai->reasoning_summary,
            'error_message'      => $ai->error_message,
            'completed_at'       => $ai->completed_at,
        ];
    }

    // -----------------------------------------------------------------
    // Student-facing result visibility (Phase 24)
    // -----------------------------------------------------------------

    /** بيقرر لو الطالب يقدر يشوف نتيجته دلوقتي — حسب exam.result_visibility. */
    public function isResultVisibleTo(Exam $exam, ExamAttempt $attempt): bool
    {
        if ($attempt->status !== 'graded') {
            return false;
        }

        return match ($exam->result_visibility) {
            'immediate'   => true,
            'after_close' => $exam->end_at === null || now()->greaterThanOrEqualTo($exam->end_at),
            'manual'      => $exam->results_published_at !== null && now()->greaterThanOrEqualTo($exam->results_published_at),
            default       => false,
        };
    }

    /** نتيجة الطالب لو متاحة — من غير أي كشف للإجابة الصح/الدرجة لو الإتاحة لسه ما وصلتش. */
    public function studentResultView(Exam $exam, ExamAttempt $attempt): array
    {
        $visible = $this->isResultVisibleTo($exam, $attempt);

        $base = [
            'status'     => $attempt->status,
            'visible'    => $visible,
            'score'      => null,
            'percentage' => null,
            'questions'  => null,
            'score_only' => (bool) $exam->show_score_only,
            'review_allowed' => (bool) $exam->show_answer_review && !$exam->show_score_only,
            'passing_score' => $exam->passing_score !== null ? (float) $exam->passing_score : null,
            'total_marks' => (float) $exam->total_marks,
            'is_late' => (bool) $attempt->is_late,
        ];

        if (!$visible) {
            return $base;
        }
        // التسليم المتأخر: الطالب لازم يعرف إن في خصم اتطبّق وقبل كام (بس لما النتيجة تكون ظاهرة له).
        $base['late_penalty_percent'] = $attempt->is_late && (float) $attempt->late_penalty_percent > 0 ? (float) $attempt->late_penalty_percent : null;
        $base['score_before_penalty'] = $attempt->score_before_penalty !== null ? (float) $attempt->score_before_penalty : null;

        // Round 7 — attempt-scoped.
        $pivotRows = $this->attempts->questionsForAttempt($attempt->id);
        $answers = $this->attempts->answersForAttempt($attempt->id);
        $grades = $this->grades->forAttempt($attempt->id);

        $base['score'] = $attempt->score !== null ? (float) $attempt->score : null;
        $base['percentage'] = $attempt->percentage !== null ? (float) $attempt->percentage : null;

        // Exam settings: score-only / no answer review → grades only, no per-question breakdown.
        if (!$base['review_allowed']) {
            return $base;
        }
        $base['questions'] = array_map(function ($pivot) use ($answers, $grades) {
            $question = $pivot->question;
            $answer = $answers[$pivot->id] ?? null;
            $grade = $grades[$pivot->id] ?? null;

            return [
                'exam_question_id' => $pivot->id,
                'prompt'           => $question->prompt,
                'image_url'        => $question->image_url,
                'max_marks'        => (float) ($pivot->marks_override ?? $question->marks),
                'options'          => $question->optionsForDisplay(),
                'my_answer'        => $answer ? [
                    'selected_option_ids' => $answer->selected_option_ids,
                    'answer_text'         => $answer->answer_text,
                ] : null,
                'marks_awarded'    => $grade?->marks_awarded !== null ? (float) $grade->marks_awarded : null,
                'is_correct'       => $grade?->is_correct,
                'feedback'         => $grade?->feedback,
            ];
        }, $pivotRows);

        return $base;
    }

    // -----------------------------------------------------------------
    // Manual result publishing (result_visibility = 'manual')
    // -----------------------------------------------------------------

    public function publishResults(Exam $exam): Exam
    {
        $exam->results_published_at = now();
        $exam->save();

        foreach ($this->attempts->forExamWithStudentInfo($exam->id) as $row) {
            if ($row->status === 'graded') {
                $this->notifications->notify(
                    $row->user_id,
                    'exam_result_published',
                    'Result published: ' . $exam->title,
                    'Your result for "' . $exam->title . '" is now available.',
                    null,
                    'normal'
                );
            }
        }

        return $exam;
    }

    public function unpublishResults(Exam $exam): Exam
    {
        $exam->results_published_at = null;
        $exam->save();
        return $exam;
    }
}
