<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Repositories\ExamAttemptRepository;
use App\Repositories\ExamRepository;
use App\Repositories\ExamTargetRepository;
use App\Support\AttemptShuffle;
use Illuminate\Support\Facades\DB;

/**
 * Exam & Assessment System — Round 3 (Attempts + Timer + Auto-save).
 * سطح الخدمة اللي ExamAttemptApiController بيكلمه بالكامل: بدء محاولة
 * (مع كل فحوصات Phase 9 — access control)، جلب محاولة (مع محتوى
 * الأسئلة، من غير أي إجابة صح مكشوفة)، حفظ/مسح إجابة (Phase 12 —
 * auto-save)، وتسليم المحاولة (يدوي أو أوتوماتيك).
 *
 * enforceTimer() هي القلب اللي بيحقق Phase 11 "The timer must be enforced
 * by the backend. Do NOT rely only on JavaScript": كل نقطة دخول بتلمس
 * محاولة شغالة (get/save/submit) بتنادي enforceTimer() الأول — لو الوقت
 * عدى (now() >= expires_at بحساب السيرفر، مفيش أي وقت من العميل بيتصدق)
 * المحاولة بتتحول auto_submitted فورًا قبل ما أي عملية تانية تكمل. ده
 * بديل "lazy" لجدولة queue/cron حقيقية — AutoSubmitExpiredExamAttempts
 * command (يتسجل عبر Laravel Scheduler زي PublishScheduledAnnouncements
 * بالظبط) هو الـ backstop لمحاولات الطالب اللي سابها من غير ما يرجعلها
 * تاني خالص (متصفح مقفول، مفيش أي request جاي يشغّل enforceTimer).
 *
 * التصحيح (score/percentage) عمدًا برّه Round 3 — submitAttempt() بتوقف
 * عند status=submitted/auto_submitted من غير ما تحسب أي درجة، Round 4
 * هو اللي هيقرأ الإجابات المحفوظة دي ويحوّل الحالة لـ grading -> graded.
 *
 * تحديث Round 4: submitAttempt()/enforceTimer() بقوا بينادوا
 * ExamGradingService::finalizeSubmission() فورًا بعد أي انتقال لحالة
 * submitted/auto_submitted — التصحيح الأوتوماتيك بيحصل في نفس الـ request،
 * من غير أي queue/job إضافي.
 *
 * تحديث Round 5: بقينا بنسجل أحداث نظامية (exam_started/exam_submitted/
 * auto_submitted) عبر ExamSecurityService::logSystemEvent() في نفس نقاط
 * الانتقال دي بالظبط — راجع performAutoSubmit() (helper مشتركة بين
 * enforceTimer() والـ auto-submit بسبب تخطي حد المخالفات، الأخيرة
 * الـ controller (ExamSecurityApiController) بينادّيها عبر
 * autoSubmitDueToViolations() بعد ما ExamSecurityService::recordClientEvent()
 * يرجّع threshold_exceeded=true). الاتجاه هنا واحد بس: ExamAttemptService
 * بيعتمد على ExamSecurityService، مش العكس — عشان نتجنب circular dependency.
 */
class ExamAttemptService
{
    public function __construct(
        private ExamAttemptRepository $attempts,
        private ExamRepository $exams,
        private ExamTargetRepository $targets,
        private ExamGradingService $grading,
        private ExamSecurityService $security,
        private QuestionSelectionService $selection,
        private ExamProctoringService $proctoring
    ) {
    }

    /** startAttempt(): الامتحان بيطلب لقطة بدء ومابعتهاش — الكونترولر بيحوّلها لـ 422 code=start_photo_required. */
    public const ERR_START_PHOTO_REQUIRED = 4101;

    /** الامتحان محمي بباسورد: الطالب ما بعتش باسورد / بعت باسورد غلط (الكونترولر بيحوّلهم لـ 422 بـ code). */
    public const ERR_PASSWORD_REQUIRED = 4102;
    public const ERR_PASSWORD_INVALID = 4103;

    // -----------------------------------------------------------------
    // Start / resume
    // -----------------------------------------------------------------

    /**
     * @param array{start_photo?:?\Illuminate\Http\UploadedFile, id_card?:?\Illuminate\Http\UploadedFile, photo_flags?:mixed, ip?:?string} $options
     *        لو الامتحان بيطلب لقطة بدء ومش معفي الطالب، start_photo إجباري عند إنشاء محاولة **جديدة** (الاستكمال مابيطلبهاش).
     * @return array{attempt: ExamAttempt, resumed: bool}
     * @throws \InvalidArgumentException لو الامتحان مش متاح/الطالب مش مؤهل/تخطى المحاولات/لقطة البدء ناقصة (code = ERR_START_PHOTO_REQUIRED).
     */
    public function startAttempt($examId, $studentId, $universityId, array $options = []): array
    {
        $exam = $this->exams->find($examId);
        if (!$exam) {
            throw new \InvalidArgumentException('Exam not found.');
        }

        // لو فيه محاولة شغالة بالفعل، نكمل عليها (ونفحص التايمر بتاعها
        // أول حاجة — ممكن تكون فعليًا خلصت وقتها ولسه الحالة in_progress
        // لحد ما حد يلمسها).
        $active = $this->attempts->activeAttempt($studentId, $examId);
        if ($active) {
            $active = $this->enforceTimer($active);
            if ($active->isActive()) {
                return ['attempt' => $active, 'resumed' => true];
            }
        }

        $this->assertAccessible($exam, $studentId, $universityId);
        $this->assertAccessPassword($exam, $options['access_password'] ?? null);

        // لقطة البدء بتتفحص قبل ما نخلق أي حاجة عشان مايتخلقش صف محاولة يتيم.
        $startPhoto = $options['start_photo'] ?? null;
        $idCard = $options['id_card'] ?? null;
        $photoFlags = $options['photo_flags'] ?? [];
        if ($this->proctoring->requiresStartPhoto($exam, $studentId)) {
            if (!$startPhoto) {
                throw new \InvalidArgumentException('A camera photo is required to start this exam.', self::ERR_START_PHOTO_REQUIRED);
            }
            $this->proctoring->inspectImage($startPhoto);
            if ($idCard) {
                $this->proctoring->inspectImage($idCard);
            }
        } else {
            $startPhoto = null;
            $idCard = null;
        }

        $now = now();

        // Round 7 — لو سحب الـ pools فشل (مثلاً pool اتقلّص بعد ما اتضاف
        // للامتحان ومبقاش كافي)، مينفعش نسيب صف exam_attempts يتيم من
        // غير أسئلة خالص — transaction واحدة تلف الإنشاء + السحب مع بعض.
        $attempt = DB::transaction(function () use ($exam, $examId, $studentId, $now, $startPhoto, $idCard, $photoFlags, $options) {
            $attempt = $this->attempts->create([
                'exam_id'          => $exam->id,
                'student_id'       => $studentId,
                'attempt_number'   => $this->attempts->nextAttemptNumber($studentId, $examId),
                'status'           => 'in_progress',
                'started_at'       => $now,
                'last_activity_at' => $now,
                'expires_at'       => $now->copy()->addMinutes((int) $exam->duration_minutes),
            ]);

            // بيحدد "الطالب ده هيشوف إيه بالظبط" مرة واحدة بس هنا (أسئلة
            // يدوية + سحب من أي pools متضافة + ترتيب نهائي، راجع
            // QuestionSelectionService::resolveForAttempt()). ثابت بعد كده.
            $this->selection->resolveForAttempt($exam, $attempt);

            if ($startPhoto) {
                $this->proctoring->storeStartPhotos($attempt, $exam, $startPhoto, $idCard, $photoFlags, $options['ip'] ?? null);
            }

            return $attempt;
        });

        $this->security->logSystemEvent($attempt, 'exam_started');
        if ($startPhoto) {
            $this->proctoring->logStartEvents($attempt, $exam, $photoFlags);
        }

        return ['attempt' => $attempt, 'resumed' => false];
    }

    /** Phase 9 — كل فحوصات "هل الطالب ده يقدر يبدأ محاولة على الامتحان ده دلوقتي؟". */
    /**
     * لو الدكتور حط باسورد للامتحان، الطالب لازم يبعته صح قبل ما تتخلق أي محاولة جديدة.
     * (استكمال محاولة شغالة بيحصل قبل كده ومابيطلبهوش تاني.)
     */
    private function assertAccessPassword(Exam $exam, $plain): void
    {
        $hash = $exam->getAttributes()['access_password'] ?? null;
        if (empty($hash)) {
            return;
        }
        $plain = is_string($plain) ? $plain : '';
        if (trim($plain) === '') {
            throw new \InvalidArgumentException('This exam is password protected. Enter the exam password to start.', self::ERR_PASSWORD_REQUIRED);
        }
        if (!\Illuminate\Support\Facades\Hash::check($plain, $hash)) {
            throw new \InvalidArgumentException('Incorrect exam password.', self::ERR_PASSWORD_INVALID);
        }
    }

    private function assertAccessible(Exam $exam, $studentId, $universityId): void
    {
        if ((int) $exam->university_id !== (int) $universityId) {
            throw new \InvalidArgumentException('Exam not found.');
        }

        // استثناء الطالب ده (إعادة الامتحان بصلاحية المدرس): محاولات إضافية، و/أو
        // نافذة وصول خاصة (available_until) بتتخطى start_at/end_at وحالة closed.
        $override = $this->attempts->overrideFor($exam->id, $studentId);
        $extraAttempts = $override ? (int) $override->extra_attempts : 0;
        $privateWindow = $override && $extraAttempts > 0 && $override->windowIsOpen();

        $openStatuses = ['published', 'scheduled', 'active'];
        if ($privateWindow) {
            $openStatuses = array_merge($openStatuses, ['closed', 'grading', 'graded']);
        }
        if (!in_array($exam->status, $openStatuses, true)) {
            throw new \InvalidArgumentException('This exam is not currently open for attempts.');
        }
        if (!$privateWindow) {
            if ($exam->start_at && now()->lessThan($exam->start_at)) {
                throw new \InvalidArgumentException('This exam has not started yet.');
            }
            if ($exam->end_at && now()->greaterThan($exam->end_at)) {
                throw new \InvalidArgumentException('This exam is closed and no longer accepts attempts.');
            }
        }
        if (!$this->targets->studentIsEligibleForExam($exam->id, $studentId)) {
            throw new \InvalidArgumentException('You are not eligible to take this exam.');
        }
        if ($this->attempts->countFinishedForStudentAndExam($studentId, $exam->id) >= (int) $exam->max_attempts + $extraAttempts) {
            throw new \InvalidArgumentException('You have already used the maximum number of attempts for this exam.');
        }
    }

    // -----------------------------------------------------------------
    // Read
    // -----------------------------------------------------------------

    /** @return ExamAttempt[] كل محاولات الطالب على امتحان بعينه (تاريخ + الشغالة لو فيه)، بعد ما التايمر يتفحص لكل واحدة شغالة. */
    public function listAttempts($studentId, $examId): array
    {
        return array_map(
            fn (ExamAttempt $a) => $a->isActive() ? $this->enforceTimer($a) : $a,
            $this->attempts->forStudentAndExam($studentId, $examId)
        );
    }

    /**
     * الشكل الكامل للمحاولة (وصفها + الأسئلة + إجابات الطالب الحالية)،
     * من غير أي is_correct مكشوف. بتفحص التايمر الأول — لو خلصت هترجع
     * تفاصيلها بعد ما تتحول auto_submitted تلقائيًا.
     */
    public function attemptDetail(ExamAttempt $attempt): array
    {
        $attempt = $this->enforceTimer($attempt);
        $exam = $this->exams->find($attempt->exam_id);
        // Round 7 — أسئلة المحاولة دي بالذات (ترتيبها الفعلي، بعد أي خلط)،
        // مش كل أسئلة الامتحان — ممكن تختلف عن طالب تاني تحت question pools.
        $pivotRows = $this->attempts->questionsForAttempt($attempt->id);
        $answers = $this->attempts->answersForAttempt($attempt->id);

        $questions = array_map(function ($pivot, $i) use ($answers, $attempt, $exam) {
            $question = $pivot->question;
            $answer = $answers[$pivot->id] ?? null;

            return [
                'exam_question_id' => $pivot->id,
                'question_id'      => $question->id,
                'type'             => $question->type,
                'prompt'           => $question->prompt,
                'image_url'        => $question->image_url,
                'marks'            => (float) ($pivot->marks_override ?? $question->marks),
                'sort_order'       => $i,
                'options'          => $this->safeOptions($question, $attempt, $exam),
                'my_answer'        => $answer ? [
                    'selected_option_ids' => $answer->selected_option_ids,
                    'answer_text'         => $answer->answer_text,
                    'answered_at'         => $answer->answered_at,
                ] : null,
            ];
        }, $pivotRows, array_keys($pivotRows));

        return [
            'id'                     => $attempt->id,
            'exam_id'                => $attempt->exam_id,
            'attempt_number'         => $attempt->attempt_number,
            'status'                 => $attempt->status,
            'started_at'             => $attempt->started_at,
            'expires_at'             => $attempt->expires_at,
            'submitted_at'           => $attempt->submitted_at,
            'time_remaining_seconds' => $attempt->remainingSeconds(),
            // سياسة التسليم المتأخر: جوه فترة السماح time_remaining_seconds=0 وgrace_remaining_seconds هو العد التنازلي.
            'in_grace'               => $attempt->inGrace(),
            'grace_remaining_seconds' => $attempt->graceRemainingSeconds(),
            'grace_ends_at'          => $attempt->graceMinutes() > 0 ? $attempt->graceEndsAt() : null,
            'extra_time_minutes'     => (int) $attempt->extra_time_minutes,
            'is_late'                => (bool) $attempt->is_late,
            'violations_count'       => $attempt->violations_count,
            'violations_remaining'   => $exam->max_violations !== null ? max(0, (int) $exam->max_violations - $attempt->violations_count) : null,
            'exam'                   => [
                'id'                    => $exam->id,
                'title'                 => $exam->title,
                'duration_minutes'      => $exam->duration_minutes,
                'total_marks'           => (float) $exam->total_marks,
                'passing_score'         => $exam->passing_score !== null ? (float) $exam->passing_score : null,
                'instructions'          => $exam->instructions,
                'result_visibility'     => $exam->result_visibility,
                'secure_mode_enabled'   => (bool) $exam->secure_mode_enabled,
                'max_violations'        => $exam->max_violations !== null ? (int) $exam->max_violations : null,
                'allow_back_navigation' => (bool) $exam->allow_back_navigation,
                'auto_submit_on_timeout' => (bool) $exam->auto_submit_on_timeout,
                'late_grace_minutes'    => (int) $exam->late_grace_minutes,
                'late_penalty_percent'  => (float) $exam->late_penalty_percent,
                'single_session_enabled' => (bool) $exam->single_session_enabled,
            ],
            // كاميرا/هوية للطالب ده (effective: بيراعي الإعفاء).
            'proctoring'             => $this->proctoring->studentConfig($exam, $attempt->student_id),
            'identity_status'        => $attempt->identity_status ?: 'none',
            'questions'              => $questions,
        ];
    }

    /**
     * أسئلة الاختيارات (mcq/true_false) من غير is_correct — الطالب ميشوفش
     * أي إجابة صح في أي وقت قبل التصحيح. Round 7 (Phase 7 "Random Option
     * Order") — لو exam.randomize_options=true، ترتيب الاختيارات بيتخلط
     * بشكل حتمي لكل محاولة (AttemptShuffle — نفس الطالب دايمًا بيشوف نفس
     * الترتيب لو رجع تاني، طالب تاني هيشوف ترتيب مختلف).
     */
    private function safeOptions($question, ExamAttempt $attempt, Exam $exam): ?array
    {
        if ($question->type === 'true_false') {
            $options = [
                ['id' => 'true', 'option_text' => 'True'],
                ['id' => 'false', 'option_text' => 'False'],
            ];
            if ($exam->randomize_options) {
                $options = AttemptShuffle::order($options, $attempt->id, fn ($o) => $o['id'] . ':' . $question->id);
            }
            return $options;
        }
        if (in_array($question->type, ['mcq', 'multi_select'], true)) {
            $options = $question->options->map(fn ($o) => [
                'id'          => $o->id,
                'option_text' => $o->option_text,
                'sort_order'  => $o->sort_order,
            ])->all();
            if ($exam->randomize_options) {
                $options = AttemptShuffle::order($options, $attempt->id, fn ($o) => $o['id']);
            }
            return $options;
        }
        return null;
    }

    // -----------------------------------------------------------------
    // Result (Round 4 — Phase 24 visibility, delegates to ExamGradingService)
    // -----------------------------------------------------------------

    /**
     * نتيجة الطالب لمحاولة واحدة، محكومة بـ result_visibility بتاع الامتحان —
     * راجع ExamGradingService::studentResultView()/isResultVisibleTo() للمنطق
     * الفعلي. بتفحص التايمر الأول زي attemptDetail() بالظبط (محاولة لسه
     * شغالة وعدى وقتها المفروض تتحول auto_submitted قبل ما نحسب أي ظهور نتيجة).
     */
    public function studentResult(ExamAttempt $attempt): array
    {
        $attempt = $this->enforceTimer($attempt);
        $exam = $this->exams->find($attempt->exam_id);

        return $this->grading->studentResultView($exam, $attempt);
    }

    // -----------------------------------------------------------------
    // Save / delete answer (Phase 12 — Auto Save)
    // -----------------------------------------------------------------

    /**
     * @param array{selected_option_ids?: array, answer_text?: ?string} $payload
     * @return array{attempt: ExamAttempt, deleted: bool}
     * @throws \InvalidArgumentException
     */
    public function saveAnswer(ExamAttempt $attempt, $examQuestionId, array $payload): array
    {
        $attempt = $this->enforceTimer($attempt);
        if (!$attempt->isActive()) {
            throw new \InvalidArgumentException('This attempt is no longer in progress — the exam has already been submitted.');
        }

        // Round 7 — لازم يستخدم pivotForAttempt() (attempt-scoped) مش
        // exams->findPivot() (exam-scoped): تحت question pools ممكن سؤال
        // يكون تابع لنفس الامتحان بس اتسحب لطالب تاني بس، فمينفعش نتأكد
        // إن السؤال "تابع للامتحان" بس — لازم "تابع للمحاولة دي بالذات".
        $pivot = $this->attempts->pivotForAttempt($attempt->id, $examQuestionId);
        if (!$pivot) {
            throw new \InvalidArgumentException('Question not found in this attempt.');
        }
        $question = $pivot->question;

        $isEmpty = empty($payload['selected_option_ids']) && (!isset($payload['answer_text']) || $payload['answer_text'] === '' || $payload['answer_text'] === null);
        if ($isEmpty) {
            $this->attempts->deleteAnswer($attempt->id, $examQuestionId);
            $this->touchActivity($attempt);
            return ['attempt' => $attempt, 'deleted' => true];
        }

        if ($question->type === 'true_false') {
            // The UI may send answer_text, or selected_option_ids (['true']); Arabic wording is accepted too.
            $raw = $payload['answer_text'] ?? null;
            if (($raw === null || $raw === '') && !empty($payload['selected_option_ids'])) {
                $raw = reset($payload['selected_option_ids']);
            }
            $value = \App\Support\TrueFalse::normalize($raw);
            if ($value === null) {
                throw new \InvalidArgumentException('answer_text must be "true" or "false" for this question.');
            }
            $data = ['answer_text' => $value, 'selected_option_ids' => null];
        } elseif (in_array($question->type, ['mcq', 'multi_select'], true)) {
            $ids = array_map('intval', $payload['selected_option_ids'] ?? []);
            $validIds = $question->options->pluck('id')->map(fn ($id) => (int) $id)->all();
            if (array_diff($ids, $validIds)) {
                throw new \InvalidArgumentException('One or more selected options do not belong to this question.');
            }
            if ($question->type === 'mcq' && count($ids) > 1) {
                throw new \InvalidArgumentException('This question accepts only one selected option.');
            }
            $data = ['selected_option_ids' => array_values($ids), 'answer_text' => null];
        } else {
            $data = ['answer_text' => (string) ($payload['answer_text'] ?? ''), 'selected_option_ids' => null];
        }

        $this->attempts->upsertAnswer($attempt->id, $examQuestionId, $data);
        $this->touchActivity($attempt);
        $this->security->logSystemEvent($attempt, 'answer_saved', ['exam_question_id' => (int) $examQuestionId]);

        return ['attempt' => $attempt, 'deleted' => false];
    }

    public function deleteAnswer(ExamAttempt $attempt, $examQuestionId): ExamAttempt
    {
        $attempt = $this->enforceTimer($attempt);
        if (!$attempt->isActive()) {
            throw new \InvalidArgumentException('This attempt is no longer in progress — the exam has already been submitted.');
        }
        $this->attempts->deleteAnswer($attempt->id, $examQuestionId);
        $this->touchActivity($attempt);
        return $attempt;
    }

    private function touchActivity(ExamAttempt $attempt): void
    {
        $attempt->last_activity_at = now();
        $attempt->save();
    }

    // -----------------------------------------------------------------
    // Submit (manual + auto)
    // -----------------------------------------------------------------

    /** تسليم يدوي (زرار Submit بتاع الطالب). لو التايمر لقاها خلصت أصلاً، بترجع الحالة auto_submitted من غير error. */
    public function submitAttempt(ExamAttempt $attempt): ExamAttempt
    {
        $attempt = $this->enforceTimer($attempt);
        if (!$attempt->isActive()) {
            return $attempt;
        }

        // سياسة التسليم المتأخر: التسليم بعد الموعد (المعدّل) وجوه فترة السماح = متأخر.
        $this->markLate($attempt, $attempt->inGrace());

        $attempt->status = 'submitted';
        $attempt->submitted_at = now();
        $attempt->auto_submitted = false;
        $attempt->save();

        $this->grading->finalizeSubmission($attempt);
        $this->security->logSystemEvent($attempt, 'exam_submitted');

        return $attempt;
    }

    /**
     * بتفحص لو المحاولة عدى وقتها (الوقت + فترة السماح)، وبتحوّلها auto_submitted لو كده. بترجع المحاولة (معدّلة أو زي ما هي).
     * جوه فترة السماح المحاولة بتفضل in_progress — الطالب لسه يقدر يحفظ ويسلّم (والتسليم بيتعلّم متأخر).
     */
    public function enforceTimer(ExamAttempt $attempt): ExamAttempt
    {
        if ($attempt->isActive() && $attempt->isHardExpired()) {
            // submitted_at = لحظة انتهاء الوقت النظرية، مش وقت تشغيل enforceTimer() الفعلي — عشان submitted_at
            // يعكس نهاية المحاولة الحقيقية مش تأخير الـ lazy check. لو الطالب كان لسه بيشتغل جوه فترة السماح
            // (آخر نشاط بعد expires_at) بنعتبره تسليم متأخر وبنحسب النهاية من آخر نشاط (بحد أقصى نهاية السماح).
            $submittedAt = $attempt->expires_at;
            $late = false;
            if ($attempt->graceMinutes() > 0 && $attempt->last_activity_at && $attempt->last_activity_at->greaterThan($attempt->expires_at)) {
                $graceEnd = $attempt->graceEndsAt();
                $submittedAt = $attempt->last_activity_at->lessThan($graceEnd) ? $attempt->last_activity_at : $graceEnd;
                $late = true;
            }
            $this->markLate($attempt, $late);
            $this->performAutoSubmit($attempt, $submittedAt, ['reason' => 'time_expired']);
        }
        return $attempt;
    }

    /** بتعلّم المحاولة متأخرة وبتاخد snapshot لنسبة الخصم من الامتحان (الخصم الفعلي بيتحسب في ExamGradingService). */
    private function markLate(ExamAttempt $attempt, bool $late): void
    {
        $attempt->is_late = $late;
        $attempt->late_penalty_percent = $late ? (float) ($attempt->exam?->late_penalty_percent ?? 0) : null;
    }

    /**
     * Round 5 — بتتنادى من ExamSecurityApiController لما
     * ExamSecurityService::recordClientEvent() يرجّع threshold_exceeded=true
     * (تخطى exam.max_violations). نفس تأثير enforceTimer() بالظبط (auto_submitted
     * + finalizeSubmission)، بس السبب مختلف — metadata الحدث النظامي بيوضحه.
     * بترجع المحاولة زي ما هي من غير أي تأثير لو أصلاً مش شغالة (idempotent).
     */
    public function autoSubmitDueToViolations(ExamAttempt $attempt): ExamAttempt
    {
        if ($attempt->isActive()) {
            $this->markLate($attempt, $attempt->inGrace());
            $this->performAutoSubmit($attempt, now(), ['reason' => 'max_violations_exceeded', 'violations_count' => $attempt->violations_count]);
        }
        return $attempt;
    }

    /** الانتقال المشترك بين enforceTimer() وautoSubmitDueToViolations() — status/submitted_at/auto_submitted + grading + security log، في مكان واحد عشان الاتنين ميختلفوش. */
    private function performAutoSubmit(ExamAttempt $attempt, $submittedAt, array $eventMetadata): void
    {
        $attempt->status = 'auto_submitted';
        $attempt->submitted_at = $submittedAt;
        $attempt->auto_submitted = true;
        $attempt->save();

        $this->grading->finalizeSubmission($attempt);
        $this->security->logSystemEvent($attempt, 'auto_submitted', $eventMetadata);
    }

    /** الـ sweep بتاع AutoSubmitExpiredExamAttempts command — بيرجع عدد المحاولات اللي اتسلمت أوتوماتيك. */
    public function autoSubmitAllExpired(): int
    {
        $count = 0;
        foreach ($this->attempts->allExpiredInProgress() as $attempt) {
            $this->enforceTimer($attempt);
            $count++;
        }
        return $count;
    }
}
