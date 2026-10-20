<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\AcademicStaffRepository;
use App\Services\AiExamGradingService;
use App\Services\AiQuestionParsingService;
use App\Services\AuditLogService;
use App\Services\ExamSystemService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * سطح /api/v1/exam-system/* — Round 1 (Foundation) بس: بنوك الأسئلة،
 * الأسئلة (mcq/true_false)، الامتحانات، وربط سؤال بامتحان. كل المنطق
 * جوه ExamSystemService المشتركة (راجع docblock الكلاس). targeting/
 * attempts/grading/security-events لسه Rounds 2-5.
 *
 * RBAC: uip.auth بتغطي الجروب (routes/api.php)؛ role='academic_staff'
 * بتتفحص جوه requireAcademicStaff() زي AcademicStaffApiController بالظبط.
 * $academicStaffId دايمًا مُشتق من AcademicStaffRepository::findByUserId
 * (uip_user_id -> academic_staff.id)، مش من العميل — نفس القرار اللي
 * migration docblock بتاع Round 1 بيقوله. كل عمليات القراءة/التعديل/الحذف
 * بتعدي على *Repository::findOwned() الأول (جوه الخدمة) — عضو هيئة
 * تدريس تاني عمره ما يلمس بنك/سؤال/امتحان مش بتاعه حتى لو عرف الـ id.
 */
class ExamSystemApiController extends Controller
{
    public function __construct(
        private ExamSystemService $examSystem,
        private AcademicStaffRepository $staffRepo,
        private AuditLogService $auditLog,
        private AiQuestionParsingService $aiParser,
        private AiExamGradingService $aiGrading
    ) {
    }

    private function requireAcademicStaff(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'academic_staff') {
            return $this->apiError('Only academic staff accounts can access the exam system.', null, 403);
        }
        return null;
    }

    /** بترجع [academicStaff, null] لو الحساب متربط بصف academic_staff، أو [null, JsonResponse] غير كده. */
    private function resolveStaff(Request $request): array
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        $staff = $this->staffRepo->findByUserId($userId);
        if (!$staff) {
            return [null, $this->apiError('No academic staff profile found for this account.', null, 404)];
        }
        return [$staff, null];
    }

    private function scopeFor($staff): array
    {
        return [
            'university_id' => $staff->university_id,
            'faculty_id'    => $staff->faculty_id,
            'department_id' => $staff->department_id,
        ];
    }

    // ---------------------------------------------------------------
    // Question Banks — /api/v1/exam-system/question-banks
    // ---------------------------------------------------------------

    public function indexBanks(Request $request)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        return $this->apiSuccess($this->examSystem->listBanks($staff->id), 'Question banks retrieved successfully.');
    }

    public function storeBank(Request $request)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $validator = Validator::make($request->all(), [
            'title'       => 'required|string|max:200',
            'subject'     => 'nullable|string|max:150',
            'description' => 'nullable|string|max:5000',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $bank = $this->examSystem->createBank($staff->id, $this->scopeFor($staff), $request->all());

        $this->auditLog->record($staff->user_id, 'academic_staff.exam_system.bank_created', 'QuestionBank', $bank->id);

        return $this->apiSuccess($bank->toArray(), 'Question bank created successfully.', 201);
    }

    public function showBank(Request $request, $id)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $bank = $this->examSystem->findOwnedBank($id, $staff->id);
        if (!$bank) {
            return $this->apiError('Question bank not found.', null, 404);
        }

        $data = $bank->toArray();
        $data['questions'] = $this->examSystem->listQuestions($bank->id);

        return $this->apiSuccess($data, 'Question bank retrieved successfully.');
    }

    public function updateBank(Request $request, $id)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $bank = $this->examSystem->findOwnedBank($id, $staff->id);
        if (!$bank) {
            return $this->apiError('Question bank not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'title'       => 'sometimes|required|string|max:200',
            'subject'     => 'nullable|string|max:150',
            'description' => 'nullable|string|max:5000',
            'status'      => 'sometimes|in:active,archived',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $bank = $this->examSystem->updateBank($bank, $request->all());

        $this->auditLog->record($staff->user_id, 'academic_staff.exam_system.bank_updated', 'QuestionBank', $bank->id);

        return $this->apiSuccess($bank->toArray(), 'Question bank updated successfully.');
    }

    public function destroyBank(Request $request, $id)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $bank = $this->examSystem->findOwnedBank($id, $staff->id);
        if (!$bank) {
            return $this->apiError('Question bank not found.', null, 404);
        }

        $this->examSystem->deleteBank($bank);

        $this->auditLog->record($staff->user_id, 'academic_staff.exam_system.bank_deleted', 'QuestionBank', (int) $id);

        return $this->apiSuccess(null, 'Question bank deleted successfully.');
    }

    // ---------------------------------------------------------------
    // Questions — /api/v1/exam-system/question-banks/{bankId}/questions
    // ---------------------------------------------------------------

    public function storeQuestion(Request $request, $bankId)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $bank = $this->examSystem->findOwnedBank($bankId, $staff->id);
        if (!$bank) {
            return $this->apiError('Question bank not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'type'                => 'required|in:mcq,true_false,short_answer,essay',
            'prompt'              => 'required|string|max:5000',
            'marks'               => 'nullable|numeric|min:0.25',
            'difficulty'          => 'nullable|in:easy,medium,hard',
            'topic'               => 'nullable|string|max:150',
            'tags'                => 'nullable|array',
            'explanation'         => 'nullable|string|max:5000',
            'correct_answer'      => 'nullable|string|max:2000',
            'options'             => 'required_if:type,mcq|array',
            'options.*.option_text' => 'required_with:options|string|max:2000',
            'options.*.is_correct'  => 'nullable|boolean',
            // short_answer grading
            'accepted_answers'    => 'nullable|array',
            'accepted_answers.*'  => 'string|max:500',
            'case_sensitive'      => 'nullable|boolean',
            // essay/short_answer AI + manual grading guidance (Round 6)
            'model_answer'          => 'nullable|string|max:10000',
            'expected_concepts'     => 'nullable|array',
            'expected_concepts.*'   => 'string|max:300',
            'keywords'              => 'nullable|array',
            'keywords.*'            => 'string|max:100',
            'grading_instructions'  => 'nullable|string|max:5000',
            'ai_grading_enabled'    => 'nullable|boolean',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        try {
            $question = $this->examSystem->createQuestion($bank, $staff->id, $request->all());
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        $data = $question->toArray();
        $data['options'] = $question->optionsForDisplay();

        $this->auditLog->record($staff->user_id, 'academic_staff.exam_system.question_created', 'Question', $question->id);

        return $this->apiSuccess($data, 'Question created successfully.', 201);
    }

    /**
     * POST question-banks/{bankId}/questions/parse-ai — AI fallback for the
     * bulk "Add Questions" box (Round 9 UX follow-up). The client-side
     * regex parser (src/utils/questionParsing.js) handles common paste
     * shapes for free; when the instructor pastes something it can't make
     * sense of — free-form notes, a paragraph per question with no
     * markers, mixed formats in one paste — the frontend calls this
     * instead, and AiQuestionParsingService does the same segmentation/
     * classification job with real language understanding. Nothing is
     * persisted here: the AI's questions come back the same shape the
     * frontend already renders in the review list, and the instructor
     * still edits/confirms/Add-All's them through storeQuestion() like
     * any other question. Bank ownership is still checked, for the same
     * reason every other nested question-banks/{bankId}/* route checks
     * it, even though parsing itself never touches the bank row.
     */
    public function parseQuestionsWithAi(Request $request, $bankId)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $bank = $this->examSystem->findOwnedBank($bankId, $staff->id);
        if (!$bank) {
            return $this->apiError('Question bank not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'text' => 'required|string|max:20000',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        if (!$this->aiParser->isAvailable()) {
            return $this->apiError('AI parsing is not configured for this platform. Ask an admin to set it up under AI Controls.', null, 422);
        }

        try {
            $questions = $this->aiParser->parse($request->input('text'));
        } catch (\RuntimeException $e) {
            return $this->apiError('AI parsing failed: ' . $e->getMessage(), null, 502);
        }

        return $this->apiSuccess(['questions' => $questions], 'Questions parsed successfully.');
    }

    /**
     * POST /api/v1/exam-system/question-banks/{bankId}/questions/ai-edit
     * body: instruction (≤ 500), questions[] (≤ 40: type, prompt, options[], correct_answer).
     * الـ AI بيعدّل الأسئلة بتعليمات الدكتور ويرجعها بنفس الشكل. مبيحفظش حاجة.
     */
    public function reviseQuestionsWithAi(Request $request, $bankId)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        if (!$this->examSystem->findOwnedBank($bankId, $staff->id)) {
            return $this->apiError('Question bank not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'instruction'                   => 'required|string|max:500',
            'questions'                     => 'required|array|min:1|max:40',
            'questions.*.type'              => 'required|in:mcq,true_false,short_answer,essay',
            'questions.*.prompt'            => 'required|string|max:5000',
            'questions.*.options'           => 'nullable|array|max:12',
            'questions.*.options.*.option_text' => 'required_with:questions.*.options|string|max:2000',
            'questions.*.options.*.is_correct'  => 'nullable|boolean',
            'questions.*.correct_answer'    => 'nullable|string|max:2000',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        if (!$this->aiParser->isAvailable()) {
            return $this->apiError('AI editing is not configured for this platform. Ask an admin to set it up under AI Controls.', null, 422);
        }

        try {
            $questions = $this->aiParser->revise($request->input('questions'), (string) $request->input('instruction'));
        } catch (\RuntimeException $e) {
            return $this->apiError('AI editing failed: ' . $e->getMessage(), null, 502);
        }

        return $this->apiSuccess(['questions' => $questions], 'Questions revised successfully.');
    }

    /** الـ AI يكتب إجابة نموذجية لسؤال مقالي (مسودة يراجعها المدرس في الفورم؛ مش بتتحفظ هنا). */
    public function generateModelAnswer(Request $request)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        $validator = Validator::make($request->all(), [
            'prompt' => 'required|string|max:5000',
            'marks'  => 'nullable|numeric|min:0',
            'type'   => 'nullable|in:short_answer,essay',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }
        try {
            $answer = $this->aiGrading->generateModelAnswer(
                (string) $request->input('prompt'), (float) $request->input('marks', 0), $request->input('type')
            );
        } catch (\Throwable $e) {
            return $this->apiError('AI could not generate a model answer right now.', null, 503);
        }
        return $this->apiSuccess(['model_answer' => $answer]);
    }

    public function updateQuestion(Request $request, $id)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $question = $this->examSystem->findOwnedQuestion($id, $staff->id);
        if (!$question) {
            return $this->apiError('Question not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'prompt'              => 'sometimes|required|string|max:5000',
            'marks'               => 'nullable|numeric|min:0.25',
            'difficulty'          => 'nullable|in:easy,medium,hard',
            'topic'               => 'nullable|string|max:150',
            'tags'                => 'nullable|array',
            'explanation'         => 'nullable|string|max:5000',
            'status'              => 'sometimes|in:active,archived',
            'correct_answer'      => 'nullable|string|max:2000',
            'options'             => 'nullable|array',
            'options.*.option_text' => 'required_with:options|string|max:2000',
            'options.*.is_correct'  => 'nullable|boolean',
            'accepted_answers'    => 'nullable|array',
            'accepted_answers.*'  => 'string|max:500',
            'case_sensitive'      => 'nullable|boolean',
            'model_answer'          => 'nullable|string|max:10000',
            'expected_concepts'     => 'nullable|array',
            'expected_concepts.*'   => 'string|max:300',
            'keywords'              => 'nullable|array',
            'keywords.*'            => 'string|max:100',
            'grading_instructions'  => 'nullable|string|max:5000',
            'ai_grading_enabled'    => 'nullable|boolean',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        try {
            $question = $this->examSystem->updateQuestion($question, $request->all(), $staff->id);
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        $data = $question->toArray();
        $data['options'] = $question->optionsForDisplay();

        $this->auditLog->record($staff->user_id, 'academic_staff.exam_system.question_updated', 'Question', $question->id);

        return $this->apiSuccess($data, 'Question updated successfully.');
    }

    /** Question Versioning — سجل كل نسخة قديمة للسؤال ده قبل أي تعديل/حذف، الأحدث الأول. */
    public function showQuestionVersions(Request $request, $id)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $question = $this->examSystem->findOwnedQuestion($id, $staff->id);
        if (!$question) {
            return $this->apiError('Question not found.', null, 404);
        }

        $versions = $this->examSystem->questionVersionHistory($question->id);
        $data = array_map(fn ($v) => [
            'id'                 => $v->id,
            'version_number'     => $v->version_number,
            'change_type'        => $v->change_type,
            'changed_by_academic_staff_id' => $v->changed_by_academic_staff_id,
            'created_at'         => $v->created_at,
            'snapshot'           => $v->snapshot,
        ], $versions);

        return $this->apiSuccess($data, 'Question version history retrieved.');
    }

    /** POST questions/{id}/image — multipart field "image" (jpg/png/webp/gif، حد أقصى 5MB). */
    public function uploadQuestionImage(Request $request, $id)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $question = $this->examSystem->findOwnedQuestion($id, $staff->id);
        if (!$question) {
            return $this->apiError('Question not found.', null, 404);
        }

        try {
            $question = $this->examSystem->setQuestionImage($question, $request->file('image'), $staff->id);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        $data = $question->toArray();
        $data['options'] = $question->optionsForDisplay();

        $this->auditLog->record($staff->user_id, 'academic_staff.exam_system.question_image_set', 'Question', (int) $id);

        return $this->apiSuccess($data, 'Question image saved.');
    }

    public function removeQuestionImage(Request $request, $id)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $question = $this->examSystem->findOwnedQuestion($id, $staff->id);
        if (!$question) {
            return $this->apiError('Question not found.', null, 404);
        }

        $question = $this->examSystem->removeQuestionImage($question, $staff->id);
        $data = $question->toArray();
        $data['options'] = $question->optionsForDisplay();

        $this->auditLog->record($staff->user_id, 'academic_staff.exam_system.question_image_removed', 'Question', (int) $id);

        return $this->apiSuccess($data, 'Question image removed.');
    }

    public function destroyQuestion(Request $request, $id)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $question = $this->examSystem->findOwnedQuestion($id, $staff->id);
        if (!$question) {
            return $this->apiError('Question not found.', null, 404);
        }

        $this->examSystem->deleteQuestion($question, $staff->id);

        $this->auditLog->record($staff->user_id, 'academic_staff.exam_system.question_deleted', 'Question', (int) $id);

        return $this->apiSuccess(null, 'Question deleted successfully.');
    }

    // ---------------------------------------------------------------
    // Rubrics — /api/v1/exam-system/questions/{questionId}/rubric (Round 6, Phase 19)
    // ---------------------------------------------------------------

    public function showRubric(Request $request, $questionId)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $question = $this->examSystem->findOwnedQuestion($questionId, $staff->id);
        if (!$question) {
            return $this->apiError('Question not found.', null, 404);
        }

        return $this->apiSuccess($this->examSystem->getRubric($question), 'Rubric retrieved successfully.');
    }

    public function saveRubric(Request $request, $questionId)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $question = $this->examSystem->findOwnedQuestion($questionId, $staff->id);
        if (!$question) {
            return $this->apiError('Question not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'criteria'               => 'required|array|min:1',
            'criteria.*.label'       => 'required|string|max:200',
            'criteria.*.max_points'  => 'required|numeric|min:0.01',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        try {
            $rubric = $this->examSystem->saveRubric($question, $staff->id, $request->input('criteria'));
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        $this->auditLog->record($staff->user_id, 'academic_staff.exam_system.rubric_saved', 'Question', $question->id);

        return $this->apiSuccess($rubric, 'Rubric saved successfully.');
    }

    public function destroyRubric(Request $request, $questionId)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $question = $this->examSystem->findOwnedQuestion($questionId, $staff->id);
        if (!$question) {
            return $this->apiError('Question not found.', null, 404);
        }

        $this->examSystem->deleteRubric($question);

        $this->auditLog->record($staff->user_id, 'academic_staff.exam_system.rubric_deleted', 'Question', $question->id);

        return $this->apiSuccess(null, 'Rubric deleted successfully.');
    }

    // ---------------------------------------------------------------
    // Exams — /api/v1/exam-system/exams
    // ---------------------------------------------------------------

    public function indexExams(Request $request)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $exams = $this->examSystem->listExamsWithMeta($staff->id);

        return $this->apiSuccess($exams, 'Exams retrieved successfully.');
    }

    public function storeExam(Request $request)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $validator = Validator::make($request->all(), [
            'title'               => 'required|string|max:200',
            'description'         => 'nullable|string|max:5000',
            'subject'             => 'nullable|string|max:150',
            'exam_type'           => 'nullable|in:midterm,final,quiz,practice',
            'course_id'           => 'nullable|integer',
            'academic_year'       => 'nullable|string|max:20',
            'semester'            => 'nullable|string|max:20',
            'duration_minutes'    => 'required|integer|min:1',
            'start_at'            => 'nullable|date',
            'end_at'              => 'nullable|date|after_or_equal:start_at',
            'max_attempts'        => 'nullable|integer|min:1|max:10',
            'passing_score'       => 'nullable|numeric|min:0',
            'instructions'        => 'nullable|string|max:5000',
            'randomize_questions' => 'nullable|boolean',
            'randomize_options'   => 'nullable|boolean',
            'result_visibility'   => 'nullable|in:immediate,after_close,manual',
            'secure_mode_enabled' => 'nullable|boolean',
            'max_violations'      => 'nullable|integer|min:1|max:255',
            'auto_submit_on_timeout' => 'nullable|boolean',
            'allow_back_navigation'  => 'nullable|boolean',
            'show_answer_review'     => 'nullable|boolean',
            'show_score_only'        => 'nullable|boolean',
            // سياسة التسليم المتأخر — الدكتور بيحددها وقت إنشاء الامتحان: فترة سماح بالدقايق + نسبة خصم من الدرجة.
            'late_grace_minutes'     => 'nullable|integer|min:0|max:120',
            'late_penalty_percent'   => 'nullable|numeric|min:0|max:100',
            // جلسة واحدة + كاميرا/تحقق هوية.
            'single_session_enabled'    => 'nullable|boolean',
            'proctoring_mode'           => 'nullable|in:off,optional,required',
            'identity_check_required'   => 'nullable|boolean',
            'snapshot_interval_seconds' => 'nullable|integer|min:15|max:600',
            // باسورد دخول اختياري يحدده الدكتور (بيتخزن hash). remove_access_password لإلغائه.
            'access_password'           => 'nullable|string|min:4|max:64',
            'remove_access_password'    => 'nullable|boolean',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $exam = $this->examSystem->createExam($staff->id, $this->scopeFor($staff), $request->all());

        $this->auditLog->record($staff->user_id, 'academic_staff.exam_system.exam_created', 'Exam', $exam->id);

        return $this->apiSuccess($exam->toArray(), 'Exam created successfully.', 201);
    }

    public function showExam(Request $request, $id)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $exam = $this->examSystem->findOwnedExam($id, $staff->id);
        if (!$exam) {
            return $this->apiError('Exam not found.', null, 404);
        }

        return $this->apiSuccess($this->examSystem->examDetail($exam), 'Exam retrieved successfully.');
    }

    public function updateExam(Request $request, $id)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $exam = $this->examSystem->findOwnedExam($id, $staff->id);
        if (!$exam) {
            return $this->apiError('Exam not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'title'               => 'sometimes|required|string|max:200',
            'description'         => 'nullable|string|max:5000',
            'subject'             => 'nullable|string|max:150',
            'exam_type'           => 'nullable|in:midterm,final,quiz,practice',
            'course_id'           => 'nullable|integer',
            'academic_year'       => 'nullable|string|max:20',
            'semester'            => 'nullable|string|max:20',
            'duration_minutes'    => 'sometimes|required|integer|min:1',
            'start_at'            => 'nullable|date',
            'end_at'              => 'nullable|date|after_or_equal:start_at',
            'max_attempts'        => 'nullable|integer|min:1|max:10',
            'passing_score'       => 'nullable|numeric|min:0',
            'instructions'        => 'nullable|string|max:5000',
            'randomize_questions' => 'nullable|boolean',
            'randomize_options'   => 'nullable|boolean',
            'result_visibility'   => 'nullable|in:immediate,after_close,manual',
            'secure_mode_enabled' => 'nullable|boolean',
            'max_violations'      => 'nullable|integer|min:1|max:255',
            'auto_submit_on_timeout' => 'nullable|boolean',
            'allow_back_navigation'  => 'nullable|boolean',
            'show_answer_review'     => 'nullable|boolean',
            'show_score_only'        => 'nullable|boolean',
            // سياسة التسليم المتأخر — الدكتور بيحددها وقت إنشاء الامتحان: فترة سماح بالدقايق + نسبة خصم من الدرجة.
            'late_grace_minutes'     => 'nullable|integer|min:0|max:120',
            'late_penalty_percent'   => 'nullable|numeric|min:0|max:100',
            // جلسة واحدة + كاميرا/تحقق هوية.
            'single_session_enabled'    => 'nullable|boolean',
            'proctoring_mode'           => 'nullable|in:off,optional,required',
            'identity_check_required'   => 'nullable|boolean',
            'snapshot_interval_seconds' => 'nullable|integer|min:15|max:600',
            // باسورد دخول اختياري يحدده الدكتور (بيتخزن hash). remove_access_password لإلغائه.
            'access_password'           => 'nullable|string|min:4|max:64',
            'remove_access_password'    => 'nullable|boolean',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $exam = $this->examSystem->updateExam($exam, $request->all());

        $this->auditLog->record($staff->user_id, 'academic_staff.exam_system.exam_updated', 'Exam', $exam->id);

        return $this->apiSuccess($exam->toArray(), 'Exam updated successfully.');
    }

    public function destroyExam(Request $request, $id)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $exam = $this->examSystem->findOwnedExam($id, $staff->id);
        if (!$exam) {
            return $this->apiError('Exam not found.', null, 404);
        }

        $this->examSystem->deleteExam($exam);

        $this->auditLog->record($staff->user_id, 'academic_staff.exam_system.exam_deleted', 'Exam', (int) $id);

        return $this->apiSuccess(null, 'Exam deleted successfully.');
    }

    /** POST /api/v1/exam-system/exams/{id}/questions — يضيف سؤال (من أي بنك بتاع نفس العضو) للامتحان. */
    public function addExamQuestion(Request $request, $id)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $exam = $this->examSystem->findOwnedExam($id, $staff->id);
        if (!$exam) {
            return $this->apiError('Exam not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'question_id'    => 'required|integer',
            'marks_override' => 'nullable|numeric|min:0.25',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $question = $this->examSystem->findOwnedQuestion($request->input('question_id'), $staff->id);
        if (!$question) {
            return $this->apiError('Question not found.', null, 404);
        }

        try {
            $this->examSystem->addQuestionToExam($exam, $question, $request->input('marks_override'));
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        $this->auditLog->record($staff->user_id, 'academic_staff.exam_system.exam_question_added', 'Exam', $exam->id, null, ['question_id' => $question->id]);

        return $this->apiSuccess($this->examSystem->examDetail($exam->fresh()), 'Question added to exam successfully.', 201);
    }

    /** DELETE /api/v1/exam-system/exams/{id}/questions/{examQuestionId} */
    public function removeExamQuestion(Request $request, $id, $examQuestionId)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $exam = $this->examSystem->findOwnedExam($id, $staff->id);
        if (!$exam) {
            return $this->apiError('Exam not found.', null, 404);
        }

        if (!$this->examSystem->removeQuestionFromExam($exam, $examQuestionId)) {
            return $this->apiError('Question is not part of this exam.', null, 404);
        }

        $this->auditLog->record($staff->user_id, 'academic_staff.exam_system.exam_question_removed', 'Exam', $exam->id, null, ['exam_question_id' => (int) $examQuestionId]);

        return $this->apiSuccess($this->examSystem->examDetail($exam->fresh()), 'Question removed from exam successfully.');
    }

    /** POST /api/v1/exam-system/exams/{id}/questions/bulk-remove — body: exam_question_ids[]. بيشيل أسئلة كتير من الامتحان مرة واحدة (الأسئلة نفسها تفضل في البنك). */
    public function bulkRemoveExamQuestions(Request $request, $id)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $exam = $this->examSystem->findOwnedExam($id, $staff->id);
        if (!$exam) {
            return $this->apiError('Exam not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'exam_question_ids'   => 'required|array|min:1|max:500',
            'exam_question_ids.*' => 'integer',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $removed = $this->examSystem->removeQuestionsFromExam($exam, $request->input('exam_question_ids'));

        $this->auditLog->record($staff->user_id, 'academic_staff.exam_system.exam_questions_bulk_removed', 'Exam', $exam->id, null, ['exam_question_ids' => $request->input('exam_question_ids'), 'removed' => $removed]);

        return $this->apiSuccess($this->examSystem->examDetail($exam->fresh()), $removed . ' question(s) removed from exam successfully.');
    }

    /** PATCH /api/v1/exam-system/exams/{id}/questions/{examQuestionId} — body: marks. بيعدّل درجة سؤال واحد. */
    public function updateExamQuestionMarks(Request $request, $id, $examQuestionId)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $exam = $this->examSystem->findOwnedExam($id, $staff->id);
        if (!$exam) {
            return $this->apiError('Exam not found.', null, 404);
        }

        $validator = Validator::make($request->all(), ['marks' => 'required|numeric|min:0.25|max:1000']);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        try {
            $found = $this->examSystem->setQuestionMarks($exam, $examQuestionId, (float) $request->input('marks'));
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }
        if (!$found) {
            return $this->apiError('Question is not part of this exam.', null, 404);
        }

        $this->auditLog->record($staff->user_id, 'academic_staff.exam_system.exam_question_marks_updated', 'Exam', $exam->id, null, ['exam_question_id' => (int) $examQuestionId, 'marks' => (float) $request->input('marks')]);

        return $this->apiSuccess($this->examSystem->examDetail($exam->fresh()), 'Question points updated successfully.');
    }

    /** PUT /api/v1/exam-system/exams/{id}/marks — body: total_marks. بيوزّع الإجمالي بالتساوي على الأسئلة. */
    public function distributeExamMarks(Request $request, $id)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $exam = $this->examSystem->findOwnedExam($id, $staff->id);
        if (!$exam) {
            return $this->apiError('Exam not found.', null, 404);
        }

        $validator = Validator::make($request->all(), ['total_marks' => 'required|numeric|min:0.25|max:100000']);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        try {
            $this->examSystem->distributeTotalMarks($exam, (float) $request->input('total_marks'));
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        $this->auditLog->record($staff->user_id, 'academic_staff.exam_system.exam_marks_distributed', 'Exam', $exam->id, null, ['total_marks' => (float) $request->input('total_marks')]);

        return $this->apiSuccess($this->examSystem->examDetail($exam->fresh()), 'Total points distributed across the questions.');
    }

    /** PATCH /api/v1/exam-system/exams/{id}/questions/reorder (body: {order: [examQuestionId, ...]}) */
    public function reorderExamQuestions(Request $request, $id)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $exam = $this->examSystem->findOwnedExam($id, $staff->id);
        if (!$exam) {
            return $this->apiError('Exam not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'order'   => 'required|array',
            'order.*' => 'integer',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $this->examSystem->reorderExamQuestions($exam, $request->input('order'));

        return $this->apiSuccess($this->examSystem->examDetail($exam->fresh()), 'Exam questions reordered successfully.');
    }

    // ---------------------------------------------------------------
    // Targeting — Round 2 — /api/v1/exam-system/exams/{id}/targets
    // ---------------------------------------------------------------

    private function targetRowsValidator(Request $request)
    {
        return Validator::make($request->all(), [
            'targets'                  => 'required|array',
            'targets.*.student_id'     => 'nullable|integer',
            'targets.*.faculty_id'     => 'nullable|integer',
            'targets.*.department_id'  => 'nullable|integer',
            'targets.*.academic_year'  => 'nullable|integer|min:1|max:8',
            'targets.*.group_id'       => 'nullable|integer',
        ]);
    }

    /** GET — صفوف الاستهداف الحالية + عدد الطلاب المؤهلين دلوقتي. */
    public function showExamTargets(Request $request, $id)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $exam = $this->examSystem->findOwnedExam($id, $staff->id);
        if (!$exam) {
            return $this->apiError('Exam not found.', null, 404);
        }

        return $this->apiSuccess($this->examSystem->examTargetsSummary($exam), 'Exam targets retrieved successfully.');
    }

    /**
     * GET /api/v1/exam-system/targeting/students?q=... — typeahead بحث
     * لفورم "Add Specific Student" (محكوم بجامعة الكولر، راجع
     * ExamSystemService::searchStudentsForTargeting). ثابتة الاسم
     * (targeting/students) عمدًا قبل أي استخدام تاني لـ exams/{id} تحت،
     * زي باقي segments الثابتة في نفس المجموعة (analytics/dashboard/...).
     */
    public function searchTargetStudents(Request $request)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $rows = $this->examSystem->searchStudentsForTargeting(
            (int) $staff->university_id,
            (string) $request->input('q', '')
        );

        return $this->apiSuccess($rows, 'Students retrieved successfully.');
    }

    /**
     * GET /api/v1/exam-system/targeting/groups — قايمة مجموعات جامعة
     * الكولر لـ dropdown "Group" في فورم الاستهداف (بدل كتابة Group ID
     * رقمي أعمى).
     */
    public function listTargetGroups(Request $request)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $rows = $this->examSystem->groupsForTargeting((int) $staff->university_id);

        return $this->apiSuccess($rows, 'Groups retrieved successfully.');
    }

    /** GET — عينة الطلاب المؤهلين فعليًا (لمراجعة المدرس قبل النشر). */
    public function listExamTargetStudents(Request $request, $id)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $exam = $this->examSystem->findOwnedExam($id, $staff->id);
        if (!$exam) {
            return $this->apiError('Exam not found.', null, 404);
        }

        return $this->apiSuccess($this->examSystem->examTargetsPreviewList($exam), 'Matching students retrieved successfully.');
    }

    /** PUT — بيستبدل كل صفوف الاستهداف بالكامل بمجموعة جديدة. */
    public function replaceExamTargets(Request $request, $id)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $exam = $this->examSystem->findOwnedExam($id, $staff->id);
        if (!$exam) {
            return $this->apiError('Exam not found.', null, 404);
        }

        $validator = $this->targetRowsValidator($request);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        try {
            $summary = $this->examSystem->replaceExamTargets($exam, $request->input('targets'));
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        $this->auditLog->record($staff->user_id, 'academic_staff.exam_system.exam_targets_updated', 'Exam', $exam->id, null, [
            'targets_count' => count($request->input('targets')), 'matching_count' => $summary['matching_count'],
        ]);

        return $this->apiSuccess($summary, 'Exam targets saved successfully.');
    }

    /** POST — بيحسب عدد الطلاب المؤهلين لصفوف مُقترحة من غير ما يحفظهم (live preview وقت التعديل في الفورم). */
    public function previewExamTargets(Request $request, $id)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $exam = $this->examSystem->findOwnedExam($id, $staff->id);
        if (!$exam) {
            return $this->apiError('Exam not found.', null, 404);
        }

        $validator = $this->targetRowsValidator($request);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        try {
            $count = $this->examSystem->previewTargetCount($exam, $request->input('targets'));
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess(['matching_count' => $count], 'Matching student count calculated successfully.');
    }

    /** POST /api/v1/exam-system/exams/{id}/publish */
    public function publishExam(Request $request, $id)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $exam = $this->examSystem->findOwnedExam($id, $staff->id);
        if (!$exam) {
            return $this->apiError('Exam not found.', null, 404);
        }

        try {
            $exam = $this->examSystem->publishExam($exam);
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        $this->auditLog->record($staff->user_id, 'academic_staff.exam_system.exam_published', 'Exam', $exam->id, null, ['status' => $exam->status]);

        return $this->apiSuccess($exam->toArray(), 'Exam published successfully.');
    }

    /** POST /api/v1/exam-system/exams/{id}/unpublish */
    public function unpublishExam(Request $request, $id)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $exam = $this->examSystem->findOwnedExam($id, $staff->id);
        if (!$exam) {
            return $this->apiError('Exam not found.', null, 404);
        }

        try {
            $exam = $this->examSystem->unpublishExam($exam);
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        $this->auditLog->record($staff->user_id, 'academic_staff.exam_system.exam_unpublished', 'Exam', $exam->id);

        return $this->apiSuccess($exam->toArray(), 'Exam moved back to draft successfully.');
    }

    // ---------------------------------------------------------------
    // Question Pools — Round 7 (Phase 6) — /api/v1/exam-system/question-banks/{bankId}/pools + pools/{id}
    // ---------------------------------------------------------------

    public function indexPools(Request $request, $bankId)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $bank = $this->examSystem->findOwnedBank($bankId, $staff->id);
        if (!$bank) {
            return $this->apiError('Question bank not found.', null, 404);
        }

        return $this->apiSuccess($this->examSystem->listPools($bank->id), 'Question pools retrieved successfully.');
    }

    public function storePool(Request $request, $bankId)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $bank = $this->examSystem->findOwnedBank($bankId, $staff->id);
        if (!$bank) {
            return $this->apiError('Question bank not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:200',
            'description' => 'nullable|string|max:5000',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $pool = $this->examSystem->createPool($bank, $staff->id, $request->all());

        $this->auditLog->record($staff->user_id, 'academic_staff.exam_system.pool_created', 'QuestionPool', $pool->id);

        return $this->apiSuccess($this->examSystem->poolDetail($pool), 'Question pool created successfully.', 201);
    }

    public function showPool(Request $request, $id)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $pool = $this->examSystem->findOwnedPool($id, $staff->id);
        if (!$pool) {
            return $this->apiError('Question pool not found.', null, 404);
        }

        return $this->apiSuccess($this->examSystem->poolDetail($pool), 'Question pool retrieved successfully.');
    }

    public function updatePool(Request $request, $id)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $pool = $this->examSystem->findOwnedPool($id, $staff->id);
        if (!$pool) {
            return $this->apiError('Question pool not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'name'        => 'sometimes|required|string|max:200',
            'description' => 'nullable|string|max:5000',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $pool = $this->examSystem->updatePool($pool, $request->all());

        $this->auditLog->record($staff->user_id, 'academic_staff.exam_system.pool_updated', 'QuestionPool', $pool->id);

        return $this->apiSuccess($this->examSystem->poolDetail($pool), 'Question pool updated successfully.');
    }

    public function destroyPool(Request $request, $id)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $pool = $this->examSystem->findOwnedPool($id, $staff->id);
        if (!$pool) {
            return $this->apiError('Question pool not found.', null, 404);
        }

        $this->examSystem->deletePool($pool);

        $this->auditLog->record($staff->user_id, 'academic_staff.exam_system.pool_deleted', 'QuestionPool', (int) $id);

        return $this->apiSuccess(null, 'Question pool deleted successfully.');
    }

    /** PUT /api/v1/exam-system/pools/{id}/questions — استبدال كامل لعضوية الـ pool (body: {question_ids: [...]}) */
    public function syncPoolQuestions(Request $request, $id)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $pool = $this->examSystem->findOwnedPool($id, $staff->id);
        if (!$pool) {
            return $this->apiError('Question pool not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'question_ids'   => 'required|array',
            'question_ids.*' => 'integer',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        try {
            $detail = $this->examSystem->syncPoolQuestions($pool, $request->input('question_ids'));
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        $this->auditLog->record($staff->user_id, 'academic_staff.exam_system.pool_questions_synced', 'QuestionPool', $pool->id, null, ['count' => count($detail['question_ids'])]);

        return $this->apiSuccess($detail, 'Question pool membership updated successfully.');
    }

    // ---------------------------------------------------------------
    // Question Pool configs on an exam — Round 7 (Phases 6-7) —
    // /api/v1/exam-system/exams/{id}/pools + /exams/{id}/pools/{configId}
    // ---------------------------------------------------------------

    private function poolConfigValidator(Request $request, bool $partial = false)
    {
        $req = $partial ? 'sometimes|required' : 'required';
        return Validator::make($request->all(), [
            'question_pool_id'                => $partial ? 'prohibited' : 'required|integer',
            'questions_to_select'              => "{$req}|integer|min:1",
            'marks_per_question'               => "{$req}|numeric|min:0.01",
            'difficulty_distribution'          => 'nullable|array',
            'difficulty_distribution.*'        => 'integer|min:1',
            'topic_distribution'               => 'nullable|array',
            'topic_distribution.*'             => 'integer|min:1',
        ]);
    }

    public function indexExamPools(Request $request, $id)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $exam = $this->examSystem->findOwnedExam($id, $staff->id);
        if (!$exam) {
            return $this->apiError('Exam not found.', null, 404);
        }

        return $this->apiSuccess($this->examSystem->examPoolConfigs($exam), 'Exam question pools retrieved successfully.');
    }

    public function attachExamPool(Request $request, $id)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $exam = $this->examSystem->findOwnedExam($id, $staff->id);
        if (!$exam) {
            return $this->apiError('Exam not found.', null, 404);
        }

        $validator = $this->poolConfigValidator($request);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $pool = $this->examSystem->findOwnedPool($request->input('question_pool_id'), $staff->id);
        if (!$pool) {
            return $this->apiError('Question pool not found.', null, 404);
        }

        try {
            $config = $this->examSystem->attachPoolToExam($exam, $pool, $request->all());
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        $this->auditLog->record($staff->user_id, 'academic_staff.exam_system.exam_pool_attached', 'Exam', $exam->id, null, ['question_pool_id' => $pool->id]);

        return $this->apiSuccess($this->examSystem->examDetail($exam->fresh()), 'Question pool attached to exam successfully.', 201);
    }

    public function updateExamPool(Request $request, $id, $configId)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $exam = $this->examSystem->findOwnedExam($id, $staff->id);
        if (!$exam) {
            return $this->apiError('Exam not found.', null, 404);
        }

        $config = $this->examSystem->findOwnedPoolConfig($exam, $configId);
        if (!$config) {
            return $this->apiError('This pool is not attached to the exam.', null, 404);
        }

        $validator = $this->poolConfigValidator($request, true);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        try {
            $this->examSystem->updatePoolOnExam($exam, $config, $request->all());
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        $this->auditLog->record($staff->user_id, 'academic_staff.exam_system.exam_pool_updated', 'Exam', $exam->id, null, ['exam_question_pool_id' => (int) $configId]);

        return $this->apiSuccess($this->examSystem->examDetail($exam->fresh()), 'Question pool configuration updated successfully.');
    }

    public function detachExamPool(Request $request, $id, $configId)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }

        $exam = $this->examSystem->findOwnedExam($id, $staff->id);
        if (!$exam) {
            return $this->apiError('Exam not found.', null, 404);
        }

        if (!$this->examSystem->detachPoolFromExam($exam, $configId)) {
            return $this->apiError('This pool is not attached to the exam.', null, 404);
        }

        $this->auditLog->record($staff->user_id, 'academic_staff.exam_system.exam_pool_detached', 'Exam', $exam->id, null, ['exam_question_pool_id' => (int) $configId]);

        return $this->apiSuccess($this->examSystem->examDetail($exam->fresh()), 'Question pool removed from exam successfully.');
    }
}
