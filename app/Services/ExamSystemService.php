<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamQuestionPool;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\QuestionPool;
use App\Repositories\DepartmentRepository;
use App\Repositories\ExamRepository;
use App\Repositories\ExamQuestionVersionRepository;
use App\Repositories\ExamRubricRepository;
use App\Repositories\ExamTargetRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\QuestionBankRepository;
use App\Repositories\QuestionPoolRepository;
use App\Repositories\QuestionRepository;
use App\Repositories\StudentGroupRepository;
use App\Repositories\StudentRepository;
use App\Services\NotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Exam & Assessment System — Rounds 1-2، موسّعة في Round 6. سطح الخدمة
 * اللي ExamSystemApiController بيكلمه بالكامل: بنوك الأسئلة، الأسئلة
 * (mcq/true_false من Round 1 + short_answer/essay من Round 6 — راجع
 * Question::ROUND1_TYPES وAI_GRADABLE_TYPES)، الامتحانات، ربط سؤال
 * بامتحان (exam_questions pivot)، استهداف الامتحان (exam_targets، راجع
 * Round 2 section تحت)، وrubrics (Round 6 Phase 19 — بند لكل سؤال essay/
 * short_answer). زي GroupCollaborationService بالظبط: كل ميثودز
 * التعديل/الحذف بتاخد الـ id المستهدف وبتتحقق من الملكية عبر الـ
 * repository's findOwned() قبل أي حاجة — عضو هيئة تدريس مقدرش يلمس
 * بنك/سؤال/امتحان/استهداف/rubric مش بتاعه حتى لو عرف الـ id (rubric
 * ملكيته عبر السؤال نفسه — findOwnedQuestion() الأول دايمًا).
 *
 * attempts/grading/security-events لسه في خدمات تانية (Rounds 3-5:
 * ExamAttemptService/ExamGradingService/ExamSecurityService) — الخدمة دي
 * عمدًا بتوقف عند "امتحان منشور بأسئلة ومستهدفين محددين + rubric لو
 * محتاج"، زي ما اتفق في خطة البناء.
 *
 * Round 8 (Phase 29 — Notifications): publishExam()/replaceExamTargets()
 * هما مصدر الحقيقة الوحيد لـ"مين بقى مؤهل يشوف الامتحان فعلًا" —
 * الإشعار بيتبعت من هنا بالظبط (مش من الكنترولر) عشان قايمة الطلاب
 * المستهدفين متتحسبش مرتين. publishExam() -> 'exam_scheduled' (لو
 * start_at لسه في المستقبل) أو 'exam_available' (لو اتاح فورًا) لكل
 * الطلاب المؤهلين. replaceExamTargets() على امتحان *منشور بالفعل*
 * (تعديل استهداف بعد النشر — إضافة طلاب لاحقًا) -> 'exam_assigned' بس
 * للطلاب الجداد (diff قبل/بعد)، مش إعادة إشعار الكل تاني.
 */
class ExamSystemService
{
    public function __construct(
        private QuestionBankRepository $banks,
        private QuestionRepository $questions,
        private ExamRepository $exams,
        private ExamTargetRepository $targets,
        private ExamRubricRepository $rubrics,
        private FacultyRepository $faculties,
        private DepartmentRepository $departments,
        private StudentGroupRepository $groups,
        private StudentRepository $students,
        private QuestionPoolRepository $pools,
        private NotificationService $notifications,
        private ExamQuestionVersionRepository $questionVersions,
        private FileUploadService $uploads
    ) {
    }

    // ---------------------------------------------------------------
    // Question Banks
    // ---------------------------------------------------------------

    /** بنوك الأسئلة اللي عضو هيئة التدريس ده أنشأها، مع عدد الأسئلة في كل واحد. */
    public function listBanks($academicStaffId): array
    {
        return array_map(function (QuestionBank $bank) {
            $row = $bank->toArray();
            $row['questions_count'] = $this->questions->countForBank($bank->id);
            return $row;
        }, $this->banks->forCreator($academicStaffId));
    }

    public function findOwnedBank($id, $academicStaffId): ?QuestionBank
    {
        return $this->banks->findOwned($id, $academicStaffId);
    }

    /**
     * @param array{university_id:int,faculty_id:?int,department_id:?int} $scope
     *   جامعة/كلية/قسم العضو نفسه (denormalized على البنك — راجع docblock الـ migration).
     */
    public function createBank($academicStaffId, array $scope, array $data): QuestionBank
    {
        return $this->banks->create([
            'university_id'                => $scope['university_id'],
            'faculty_id'                   => $scope['faculty_id'] ?? null,
            'department_id'                => $scope['department_id'] ?? null,
            'created_by_academic_staff_id' => $academicStaffId,
            'title'                        => trim($data['title']),
            'subject'                      => $data['subject'] ?? null,
            'description'                  => $data['description'] ?? null,
            'status'                       => 'active',
        ]);
    }

    public function updateBank(QuestionBank $bank, array $data): QuestionBank
    {
        $bank->fill(array_intersect_key($data, array_flip(['title', 'subject', 'description', 'status'])));
        $bank->save();
        return $bank;
    }

    public function deleteBank(QuestionBank $bank): void
    {
        $bank->delete();
    }

    // ---------------------------------------------------------------
    // Questions
    // ---------------------------------------------------------------

    public function listQuestions($bankId): array
    {
        return array_map(function (Question $q) {
            $row = $q->toArray();
            $row['options'] = $q->optionsForDisplay();
            return $row;
        }, $this->questions->forBank($bankId));
    }

    public function findOwnedQuestion($id, $academicStaffId): ?Question
    {
        return $this->questions->findOwned($id, $academicStaffId);
    }

    /**
     * @throws \InvalidArgumentException لو النوع مش متاح أو البيانات مش صالحة (mcq options، true_false correct_answer، أو AI grading من غير model_answer/instructions).
     */
    public function createQuestion(QuestionBank $bank, $academicStaffId, array $data): Question
    {
        $this->validateForType($data);

        return DB::transaction(function () use ($bank, $academicStaffId, $data) {
            $isAiGradable = in_array($data['type'], Question::AI_GRADABLE_TYPES, true);

            $question = $this->questions->create([
                'question_bank_id'             => $bank->id,
                'type'                          => $data['type'],
                'prompt'                        => trim($data['prompt']),
                'marks'                         => $data['marks'] ?? 1,
                'difficulty'                    => $data['difficulty'] ?? 'medium',
                'topic'                         => $data['topic'] ?? null,
                'tags'                          => $data['tags'] ?? null,
                'correct_answer'                => $data['type'] === 'true_false'
                    ? (\App\Support\TrueFalse::normalize($data['correct_answer'] ?? 'true') ?? 'true')
                    : ($data['type'] === 'short_answer' ? ($data['correct_answer'] ?? null) : null),
                'accepted_answers'              => $data['type'] === 'short_answer' ? ($data['accepted_answers'] ?? null) : null,
                'case_sensitive'                => $data['type'] === 'short_answer' ? (bool) ($data['case_sensitive'] ?? false) : false,
                'model_answer'                  => $isAiGradable ? ($data['model_answer'] ?? null) : null,
                'expected_concepts'             => $isAiGradable ? ($data['expected_concepts'] ?? null) : null,
                'keywords'                      => $isAiGradable ? ($data['keywords'] ?? null) : null,
                'grading_instructions'          => $isAiGradable ? ($data['grading_instructions'] ?? null) : null,
                'ai_grading_enabled'            => $isAiGradable ? (bool) ($data['ai_grading_enabled'] ?? false) : false,
                'explanation'                   => $data['explanation'] ?? null,
                'status'                        => 'active',
                'created_by_academic_staff_id'  => $academicStaffId,
            ]);

            if ($data['type'] === 'mcq') {
                $this->questions->replaceOptions($question->id, $data['options']);
            }

            return $question->fresh();
        });
    }

    public function updateQuestion(Question $question, array $data, $academicStaffId = null): Question
    {
        if (isset($data['type']) && $data['type'] !== $question->type) {
            $this->validateForType($data);
        }

        // Question Versioning: بنسجّل snapshot لحالة السؤال *قبل* أي
        // تعديل — لو مفيش تغيير فعلي حصل (fill() مغيرتش حاجة) هيبقى فيه
        // صف history "زيادة" بس ده أأمن بكتير من تفويت تعديل فعلي بسبب
        // منطق diff ناقص. الفريق يقدر يفلتر الصفوف المتطابقة عرضًا لو
        // حابب يقلل الضوضاء بعدين.
        $this->questionVersions->snapshot($question, $academicStaffId, 'updated');

        $fillable = array_intersect_key($data, array_flip([
            'prompt', 'marks', 'difficulty', 'topic', 'tags', 'explanation', 'status', 'correct_answer',
            'accepted_answers', 'case_sensitive', 'model_answer', 'expected_concepts', 'keywords',
            'grading_instructions', 'ai_grading_enabled',
        ]));
        if ($question->type === 'true_false' && array_key_exists('correct_answer', $fillable)) {
            $fillable['correct_answer'] = \App\Support\TrueFalse::normalize($fillable['correct_answer']) ?? $question->correct_answer;
        }
        $question->fill($fillable);

        $question->save();

        if ($question->type === 'mcq' && isset($data['options'])) {
            $this->validateMcqOptions($data['options']);
            $this->questions->replaceOptions($question->id, $data['options']);
        }

        return $question->fresh();
    }

    /**
     * بيرفع/بيستبدل الصورة التوضيحية للسؤال. بيسجّل version snapshot قبل التغيير
     * وبيمسح الملف القديم بعد نجاح الحفظ.
     * @throws \RuntimeException لو الملف مش صالح (رسالتها آمنة للعرض).
     */
    public function setQuestionImage(Question $question, ?UploadedFile $file, $academicStaffId = null): Question
    {
        $stored = $this->uploads->store($file, 'question_images', (string) $question->question_bank_id, 5120);

        $this->questionVersions->snapshot($question, $academicStaffId, 'updated');
        $old = $question->getAttributes()['image_path'] ?? null;
        $question->image_path = $stored['stored_path'];
        $question->save();
        if ($old) {
            $this->uploads->delete($old);
        }

        return $question->fresh();
    }

    public function removeQuestionImage(Question $question, $academicStaffId = null): Question
    {
        $old = $question->getAttributes()['image_path'] ?? null;
        if ($old) {
            $this->questionVersions->snapshot($question, $academicStaffId, 'updated');
            $question->image_path = null;
            $question->save();
            $this->uploads->delete($old);
        }

        return $question->fresh();
    }

    public function deleteQuestion(Question $question, $academicStaffId = null): void
    {
        $this->questionVersions->snapshot($question, $academicStaffId, 'deleted');
        $question->delete();
    }

    /** @return \App\Models\ExamQuestionVersion[] الأحدث الأول. */
    public function questionVersionHistory($questionId): array
    {
        return $this->questionVersions->listForQuestion($questionId);
    }

    /** @throws \InvalidArgumentException */
    private function validateForType(array $data): void
    {
        $type = $data['type'] ?? null;
        $allowedTypes = array_merge(Question::ROUND1_TYPES, Question::AI_GRADABLE_TYPES);
        if (!in_array($type, $allowedTypes, true)) {
            throw new \InvalidArgumentException(
                "Question type '{$type}' is not available yet — only " . implode(', ', $allowedTypes) . ' are supported so far.'
            );
        }

        if ($type === 'mcq') {
            $this->validateMcqOptions($data['options'] ?? []);
        }

        if ($type === 'true_false' && isset($data['correct_answer']) && \App\Support\TrueFalse::normalize($data['correct_answer']) === null) {
            throw new \InvalidArgumentException("A true/false question's correct_answer must be 'true' or 'false'.");
        }
    }

    /** @throws \InvalidArgumentException */
    private function validateMcqOptions(array $options): void
    {
        if (count($options) < 2) {
            throw new \InvalidArgumentException('An MCQ question needs at least 2 options.');
        }
        $correctCount = 0;
        foreach ($options as $opt) {
            if (empty(trim((string) ($opt['option_text'] ?? '')))) {
                throw new \InvalidArgumentException('Every option needs text.');
            }
            if (!empty($opt['is_correct'])) {
                $correctCount++;
            }
        }
        if ($correctCount !== 1) {
            throw new \InvalidArgumentException('An MCQ question needs exactly one correct option.');
        }
    }

    // ---------------------------------------------------------------
    // Rubrics (Round 6 — Phase 19)
    // ---------------------------------------------------------------

    /** @return array{id:int,total_points:float,criteria:array<int,array{id:int,label:string,max_points:float}>}|null */
    public function getRubric(Question $question): ?array
    {
        $rubric = $this->rubrics->findForQuestion($question->id);
        if (!$rubric) {
            return null;
        }
        return $this->rubricToArray($rubric);
    }

    /**
     * بتستبدل كل الـ criteria دفعة واحدة (مفيش partial update لبند واحد).
     * مجموع max_points لازم يساوي درجة السؤال بالظبط — عشان AI grading
     * (Phase 19: "AI grading must evaluate according to the rubric") يكون
     * دايمًا متسق مع الدرجة القصوى الحقيقية للسؤال.
     * @param array<int,array{label:string,max_points:float|string}> $criteria
     * @throws \InvalidArgumentException
     */
    public function saveRubric(Question $question, $academicStaffId, array $criteria): array
    {
        if (empty($criteria)) {
            throw new \InvalidArgumentException('A rubric needs at least one criterion.');
        }

        $sum = 0.0;
        foreach ($criteria as $criterion) {
            if (empty(trim((string) ($criterion['label'] ?? '')))) {
                throw new \InvalidArgumentException('Every rubric criterion needs a label.');
            }
            if (!isset($criterion['max_points']) || (float) $criterion['max_points'] <= 0) {
                throw new \InvalidArgumentException('Every rubric criterion needs a positive point value.');
            }
            $sum += (float) $criterion['max_points'];
        }

        if (abs($sum - (float) $question->marks) > 0.01) {
            throw new \InvalidArgumentException(
                "Rubric points must total the question's marks ({$question->marks}); the criteria you sent total {$sum}."
            );
        }

        $rubric = $this->rubrics->upsertRubric($question->id, $academicStaffId, $criteria);
        return $this->rubricToArray($rubric);
    }

    public function deleteRubric(Question $question): void
    {
        $this->rubrics->deleteForQuestion($question->id);
    }

    private function rubricToArray($rubric): array
    {
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

    // ---------------------------------------------------------------
    // Question Pools — Round 7 (Phase 6)
    // ---------------------------------------------------------------

    /** بنوك الأسئلة الفرعية (pools) التابعة لبنك واحد، مع عدد أسئلة كل واحد. */
    public function listPools($bankId): array
    {
        return array_map(fn (QuestionPool $pool) => [
            'id'               => $pool->id,
            'question_bank_id' => $pool->question_bank_id,
            'name'             => $pool->name,
            'description'      => $pool->description,
            'questions_count'  => $pool->questions_count,
        ], $this->pools->forBank($bankId));
    }

    public function findOwnedPool($id, $academicStaffId): ?QuestionPool
    {
        return $this->pools->findOwned($id, $academicStaffId);
    }

    public function createPool(QuestionBank $bank, $academicStaffId, array $data): QuestionPool
    {
        return $this->pools->create([
            'question_bank_id'             => $bank->id,
            'created_by_academic_staff_id' => $academicStaffId,
            'name'                          => trim($data['name']),
            'description'                   => $data['description'] ?? null,
        ]);
    }

    public function updatePool(QuestionPool $pool, array $data): QuestionPool
    {
        $pool->fill(array_intersect_key($data, array_flip(['name', 'description'])));
        $pool->save();
        return $pool;
    }

    public function deletePool(QuestionPool $pool): void
    {
        $this->pools->delete($pool);
    }

    /**
     * تفاصيل الـ pool + أسئلته الحالية (id/prompt/difficulty/topic بس —
     * الشكل الخفيف، زي ما الطالب مش هيشوفهم أبدًا، ده سطح المدرس بس).
     */
    public function poolDetail(QuestionPool $pool): array
    {
        return [
            'id'               => $pool->id,
            'question_bank_id' => $pool->question_bank_id,
            'name'             => $pool->name,
            'description'      => $pool->description,
            'question_ids'     => $pool->questions()->pluck('questions.id')->all(),
            'questions'        => $pool->questions()->get(['questions.id', 'questions.prompt', 'questions.type', 'questions.difficulty', 'questions.topic', 'questions.marks'])->all(),
        ];
    }

    /**
     * استبدال كامل لعضوية الـ pool — كل الأسئلة المُمررة لازم تكون تابعة
     * لنفس بنك الـ pool نفسه (عضو هيئة تدريس مقدرش يحط سؤال من بنك تاني
     * جوه الـ pool، حتى لو الاتنين ملكه).
     * @throws \InvalidArgumentException
     */
    public function syncPoolQuestions(QuestionPool $pool, array $questionIds): array
    {
        $validIds = $this->questions->forBank($pool->question_bank_id);
        $validIdSet = array_map(fn ($q) => (int) $q->id, $validIds);
        $requested = array_map('intval', $questionIds);

        $invalid = array_diff($requested, $validIdSet);
        if (!empty($invalid)) {
            throw new \InvalidArgumentException('One or more questions do not belong to this pool\'s question bank: ' . implode(', ', $invalid) . '.');
        }

        $this->pools->syncQuestions($pool, $requested);
        return $this->poolDetail($pool->fresh());
    }

    // ---------------------------------------------------------------
    // Question Pool configs on an exam — Round 7 (Phases 6-7)
    // ---------------------------------------------------------------

    public function examPoolConfigs(Exam $exam): array
    {
        return array_map(fn (ExamQuestionPool $c) => $this->poolConfigToArray($c), $this->exams->poolConfigsFor($exam->id));
    }

    public function findOwnedPoolConfig(Exam $exam, $configId): ?ExamQuestionPool
    {
        return $this->exams->findPoolConfig($exam->id, $configId);
    }

    /**
     * @param array{questions_to_select:int,marks_per_question:float,difficulty_distribution?:array,topic_distribution?:array} $data
     * @throws \InvalidArgumentException
     */
    public function attachPoolToExam(Exam $exam, QuestionPool $pool, array $data): array
    {
        if ($this->exams->poolConfigExists($exam->id, $pool->id)) {
            throw new \InvalidArgumentException('This pool is already attached to the exam.');
        }

        $normalized = $this->validatePoolConfigData($pool, $data);
        $config = $this->exams->attachPoolConfig($exam->id, $pool->id, $normalized);
        $this->recalculateTotalMarks($exam);

        return $this->poolConfigToArray($config->fresh('pool'));
    }

    /** @throws \InvalidArgumentException */
    public function updatePoolOnExam(Exam $exam, ExamQuestionPool $config, array $data): array
    {
        $pool = $config->pool ?: $this->pools->find($config->question_pool_id);
        $normalized = $this->validatePoolConfigData($pool, array_merge([
            'questions_to_select'       => $config->questions_to_select,
            'marks_per_question'        => $config->marks_per_question,
            'difficulty_distribution'   => $config->difficulty_distribution,
            'topic_distribution'        => $config->topic_distribution,
        ], $data));

        $config = $this->exams->updatePoolConfig($config, $normalized);
        $this->recalculateTotalMarks($exam);

        return $this->poolConfigToArray($config->fresh('pool'));
    }

    public function detachPoolFromExam(Exam $exam, $configId): bool
    {
        $ok = $this->exams->detachPoolConfig($exam->id, $configId);
        if ($ok) {
            $this->recalculateTotalMarks($exam);
        }
        return $ok;
    }

    /**
     * فحوصات وقت الحفظ (مش وقت السحب الفعلي — ده QuestionSelectionService::
     * draw()، وقتها لأي طالب بعينه): distribution (لو موجود) لازم مجموعه
     * يساوي questions_to_select بالظبط، وpool محتاج على الأقل questions_to_select
     * سؤال (فحص تقريبي — مش ضامن كفاية كل مجموعة difficulty/topic بالذات،
     * راجع docblock migration 2026_08_28_210000).
     * @throws \InvalidArgumentException
     */
    private function validatePoolConfigData(QuestionPool $pool, array $data): array
    {
        $count = (int) ($data['questions_to_select'] ?? 0);
        if ($count < 1) {
            throw new \InvalidArgumentException('questions_to_select must be at least 1.');
        }

        $marksPerQuestion = (float) ($data['marks_per_question'] ?? 0);
        if ($marksPerQuestion <= 0) {
            throw new \InvalidArgumentException('marks_per_question must be greater than 0.');
        }

        $available = $this->pools->questionCount($pool->id);
        if ($available < $count) {
            throw new \InvalidArgumentException("This pool only has {$available} question(s), but questions_to_select is {$count}.");
        }

        $difficulty = $data['difficulty_distribution'] ?? null;
        $topic = $data['topic_distribution'] ?? null;

        if (!empty($difficulty)) {
            $this->assertDistributionSums($difficulty, $count, 'difficulty_distribution');
        } elseif (!empty($topic)) {
            $this->assertDistributionSums($topic, $count, 'topic_distribution');
        }

        return [
            'questions_to_select'      => $count,
            'marks_per_question'       => $marksPerQuestion,
            'difficulty_distribution'  => !empty($difficulty) ? $difficulty : null,
            'topic_distribution'       => !empty($topic) && empty($difficulty) ? $topic : null,
        ];
    }

    /** @throws \InvalidArgumentException */
    private function assertDistributionSums(array $distribution, int $expectedTotal, string $label): void
    {
        $sum = array_sum(array_map('intval', $distribution));
        if ($sum !== $expectedTotal) {
            throw new \InvalidArgumentException("{$label} must add up to questions_to_select ({$expectedTotal}); it currently totals {$sum}.");
        }
    }

    private function poolConfigToArray(ExamQuestionPool $config): array
    {
        return [
            'id'                        => $config->id,
            'exam_id'                   => $config->exam_id,
            'question_pool_id'          => $config->question_pool_id,
            'pool_name'                 => $config->pool->name ?? null,
            'pool_question_count'       => $config->pool ? $this->pools->questionCount($config->pool->id) : null,
            'questions_to_select'       => (int) $config->questions_to_select,
            'marks_per_question'        => (float) $config->marks_per_question,
            'subtotal_marks'            => round((int) $config->questions_to_select * (float) $config->marks_per_question, 2),
            'difficulty_distribution'   => $config->difficulty_distribution,
            'topic_distribution'        => $config->topic_distribution,
            'sort_order'                => $config->sort_order,
        ];
    }

    // ---------------------------------------------------------------
    // Exams
    // ---------------------------------------------------------------

    public function listExams($academicStaffId): array
    {
        return $this->exams->forCreator($academicStaffId);
    }

    /**
     * Exam list for the staff workspace: every exam + students_count (eligible students),
     * attempts_count, submitted_count and pending_grading_count (finished, not graded yet).
     * One grouped query for all attempt counters, so the list stays a single request.
     */
    public function listExamsWithMeta($academicStaffId): array
    {
        $exams = $this->exams->forCreator($academicStaffId);
        if (!$exams) {
            return [];
        }

        $ids = array_map(fn (Exam $e) => $e->id, $exams);
        $rows = ExamAttempt::whereIn('exam_id', $ids)
            ->selectRaw('exam_id, status, COUNT(*) as c')
            ->groupBy('exam_id', 'status')
            ->get();

        $meta = [];
        foreach ($rows as $r) {
            $m = &$meta[$r->exam_id];
            $m ??= ['attempts' => 0, 'submitted' => 0, 'pending' => 0];
            $n = (int) $r->c;
            $m['attempts'] += $n;
            if (in_array($r->status, ['submitted', 'auto_submitted', 'grading', 'graded'], true)) {
                $m['submitted'] += $n;
            }
            if (in_array($r->status, ['submitted', 'auto_submitted', 'grading'], true)) {
                $m['pending'] += $n;
            }
            unset($m);
        }

        return array_map(function (Exam $e) use ($meta) {
            $m = $meta[$e->id] ?? ['attempts' => 0, 'submitted' => 0, 'pending' => 0];
            $row = $e->toArray();
            $row['students_count'] = $this->targets->countForExamSaved($e->id, $e->university_id);
            $row['attempts_count'] = $m['attempts'];
            $row['submitted_count'] = $m['submitted'];
            $row['pending_grading_count'] = $m['pending'];
            return $row;
        }, $exams);
    }

    public function findOwnedExam($id, $academicStaffId): ?Exam
    {
        return $this->exams->findOwned($id, $academicStaffId);
    }

    /** @param array{university_id:int,faculty_id:?int,department_id:?int} $scope */
    public function createExam($academicStaffId, array $scope, array $data): Exam
    {
        $course = $this->resolveCourse($data['course_id'] ?? null, $scope['university_id']);

        return $this->exams->create([
            'university_id'                => $scope['university_id'],
            'faculty_id'                    => $scope['faculty_id'] ?? null,
            'department_id'                 => $scope['department_id'] ?? null,
            'created_by_academic_staff_id'  => $academicStaffId,
            'title'                         => trim($data['title']),
            'description'                   => $data['description'] ?? null,
            'subject'                       => $course ? $course->name_en : ($data['subject'] ?? null),
            'course_id'                     => $course?->id,
            'exam_type'                     => $data['exam_type'] ?? 'midterm',
            'academic_year'                 => $data['academic_year'] ?? null,
            'semester'                      => $data['semester'] ?? null,
            'duration_minutes'              => $data['duration_minutes'],
            'start_at'                      => $this->toAppTimezone($data['start_at'] ?? null),
            'end_at'                        => $this->toAppTimezone($data['end_at'] ?? null),
            'max_attempts'                  => $data['max_attempts'] ?? 1,
            'passing_score'                 => $data['passing_score'] ?? null,
            'instructions'                  => $data['instructions'] ?? null,
            'randomize_questions'           => $data['randomize_questions'] ?? false,
            'randomize_options'             => $data['randomize_options'] ?? false,
            'result_visibility'             => $data['result_visibility'] ?? 'after_close',
            // Round 5 — Phases 13-16. secure_mode_enabled=true بشكل افتراضي
            // (كل امتحان بيدخل Secure Exam Mode إلا لو المدرس عطّلها صراحة)،
            // max_violations=3 نفس مثال السبك بالظبط (Phase 13).
            'secure_mode_enabled'           => $data['secure_mode_enabled'] ?? true,
            'max_violations'                => array_key_exists('max_violations', $data) ? $data['max_violations'] : 3,
            'auto_submit_on_timeout'        => $data['auto_submit_on_timeout'] ?? true,
            'allow_back_navigation'         => $data['allow_back_navigation'] ?? false,
            'show_answer_review'            => $data['show_answer_review'] ?? false,
            'show_score_only'               => $data['show_score_only'] ?? false,
            'late_grace_minutes'            => $graceMinutes = (int) ($data['late_grace_minutes'] ?? 0),
            // خصم من غير فترة سماح ملوش معنى (الامتحان بيقفل فجأة)، فبيتصفّر.
            'late_penalty_percent'          => $graceMinutes > 0 ? (float) ($data['late_penalty_percent'] ?? 0) : 0,
            // جلسة واحدة + كاميرا/هوية. تحقق الهوية بيرفع وضع الكاميرا لـ required.
            'single_session_enabled'        => $data['single_session_enabled'] ?? true,
            'identity_check_required'       => $identity = (bool) ($data['identity_check_required'] ?? false),
            'proctoring_mode'               => $identity ? 'required' : $this->normalizeProctoringMode($data['proctoring_mode'] ?? 'off'),
            'snapshot_interval_seconds'     => $this->normalizeSnapshotInterval($data['snapshot_interval_seconds'] ?? 60),
            'access_password'               => $this->hashAccessPassword($data['access_password'] ?? null),
            'status'                        => 'draft',
        ]);
    }

    public function updateExam(Exam $exam, array $data): Exam
    {
        $fillable = array_intersect_key($data, array_flip([
            'title', 'description', 'subject', 'exam_type', 'academic_year', 'semester',
            'duration_minutes', 'start_at', 'end_at', 'max_attempts', 'passing_score',
            'instructions', 'randomize_questions', 'randomize_options', 'result_visibility',
            'secure_mode_enabled', 'max_violations',
            'auto_submit_on_timeout', 'allow_back_navigation', 'show_answer_review', 'show_score_only',
            'late_grace_minutes', 'late_penalty_percent',
            'single_session_enabled', 'proctoring_mode', 'identity_check_required', 'snapshot_interval_seconds',
        ]));
        if (array_key_exists('proctoring_mode', $fillable)) {
            $fillable['proctoring_mode'] = $this->normalizeProctoringMode($fillable['proctoring_mode']);
        }
        if (array_key_exists('snapshot_interval_seconds', $fillable)) {
            $fillable['snapshot_interval_seconds'] = $this->normalizeSnapshotInterval($fillable['snapshot_interval_seconds']);
        }
        $effectiveIdentity = array_key_exists('identity_check_required', $fillable)
            ? (bool) $fillable['identity_check_required'] : (bool) $exam->identity_check_required;
        if ($effectiveIdentity) {
            $fillable['proctoring_mode'] = 'required';
        }
        // الخصم مرتبط بفترة السماح: لو فترة السماح بقت صفر (جديدة أو موجودة أصلًا) الخصم بيتصفّر.
        $effectiveGrace = array_key_exists('late_grace_minutes', $fillable) ? (int) $fillable['late_grace_minutes'] : (int) $exam->late_grace_minutes;
        if ($effectiveGrace <= 0) {
            $fillable['late_penalty_percent'] = 0;
        }
        // باسورد الدخول (اختياري): قيمة جديدة = تعيين/تغيير، remove_access_password=true = إلغاء، وغير كده بيفضل زي ما هو.
        if (!empty($data['remove_access_password'])) {
            $fillable['access_password'] = null;
        } elseif (isset($data['access_password']) && trim((string) $data['access_password']) !== '') {
            $fillable['access_password'] = $this->hashAccessPassword($data['access_password']);
        }
        foreach (['start_at', 'end_at'] as $k) {
            if (array_key_exists($k, $fillable)) {
                $fillable[$k] = $this->toAppTimezone($fillable[$k]);
            }
        }
        // Linking an exam to a course: the course name becomes the exam's subject label.
        if (array_key_exists('course_id', $data)) {
            $course = $this->resolveCourse($data['course_id'], $exam->university_id);
            $fillable['course_id'] = $course?->id;
            if ($course) {
                $fillable['subject'] = $course->name_en;
            }
        }
        $exam->fill($fillable);
        $exam->save();
        return $exam;
    }

    private function hashAccessPassword($plain): ?string
    {
        $plain = trim((string) $plain);
        return $plain === '' ? null : \Illuminate\Support\Facades\Hash::make($plain);
    }

    private function normalizeProctoringMode($mode): string
    {
        return in_array($mode, ExamProctoringService::MODES, true) ? $mode : 'off';
    }

    private function normalizeSnapshotInterval($value): int
    {
        return max(ExamProctoringService::MIN_INTERVAL, min(ExamProctoringService::MAX_INTERVAL, (int) ($value ?: 60)));
    }

    /** A course of this university (or null) — invalid / foreign ids are ignored. */
    private function resolveCourse($courseId, $universityId): ?\App\Models\Course
    {
        if ($courseId === null || $courseId === '') {
            return null;
        }
        return \App\Models\Course::where('university_id', $universityId)->find((int) $courseId);
    }

    /**
     * Exam times arrive as absolute instants (ISO-8601 with "Z"/offset) from the web app.
     * Convert to the app timezone before saving so the stored wall-clock value is correct
     * (Eloquent formats a Carbon in its own timezone and would otherwise drop the offset).
     * Naive strings (older clients) keep their previous behaviour.
     */
    private function toAppTimezone($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        return \Illuminate\Support\Carbon::parse($value)->setTimezone(config('app.timezone'));
    }

    public function deleteExam(Exam $exam): void
    {
        $exam->delete();
    }

    /**
     * تفاصيل الامتحان لسطح المدرس (وقت التأليف) — أسئلة متضافة يدويًا
     * (manual بس) + إعدادات الـ pools المربوطة، مع marks_override
     * وإجمالي الدرجات. Round 7: مش بيستخدم exams->questionsFor() (اللي
     * بقت كمان فيها صفوف source=pool اتسحبت فعليًا لطلاب — راجع docblock
     * ExamRepository::findOrCreateQuestionForPool()) عشان الشاشة دي "إعداد
     * الامتحان" مش "كل الأسئلة اللي أي طالب شافها" — القايمة الفعلية لكل
     * طالب في exam_attempt_questions (راجع ExamAttemptService::attemptDetail()).
     */
    public function examDetail(Exam $exam): array
    {
        $pivots = $this->exams->manualQuestionsFor($exam->id);

        $questions = array_map(function ($pivot) {
            $q = $pivot->question;
            return [
                'exam_question_id' => $pivot->id,
                'question_id'      => $q->id,
                'question_bank_id' => $q->question_bank_id,
                'type'             => $q->type,
                'prompt'           => $q->prompt,
                'image_url'        => $q->image_url,
                'difficulty'       => $q->difficulty,
                'marks'            => (float) ($pivot->marks_override ?? $q->marks),
                'marks_override'   => $pivot->marks_override !== null ? (float) $pivot->marks_override : null,
                'default_marks'    => (float) $q->marks,
                'sort_order'       => $pivot->sort_order,
                'options'          => $q->optionsForDisplay(),
            ];
        }, $pivots);

        $questionPools = $this->examPoolConfigs($exam);

        $row = $exam->toArray();
        $row['questions'] = $questions;
        $row['question_pools'] = $questionPools;
        $row['total_marks'] = round(
            array_sum(array_column($questions, 'marks')) + array_sum(array_column($questionPools, 'subtotal_marks')),
            2
        );

        return $row;
    }

    /**
     * @throws \InvalidArgumentException لو السؤال متضاف قبل كده أو مش تابع لبنك المستخدم نفسه.
     */
    public function addQuestionToExam(Exam $exam, Question $question, ?float $marksOverride = null): void
    {
        if ($this->exams->pivotExists($exam->id, $question->id)) {
            throw new \InvalidArgumentException('This question is already part of the exam.');
        }

        $sortOrder = $this->exams->nextSortOrder($exam->id);
        $this->exams->attachQuestion($exam->id, $question->id, $marksOverride, $sortOrder);
        $this->recalculateTotalMarks($exam);
    }

    public function removeQuestionFromExam(Exam $exam, $examQuestionId): bool
    {
        $ok = $this->exams->detachQuestion($exam->id, $examQuestionId);
        if ($ok) {
            $this->recalculateTotalMarks($exam);
        }
        return $ok;
    }

    /**
     * يشيل مجموعة أسئلة يدوية من الامتحان مرة واحدة (حذف جماعي من صفحة الامتحان).
     * بيفصل السؤال عن الامتحان بس — السؤال نفسه بيفضل في بنك الأسئلة.
     *
     * @param  int[] $examQuestionIds
     * @return int عدد الأسئلة اللي اتشالت فعليًا (الـ ids الغريبة عن الامتحان بتتجاهل)
     */
    public function removeQuestionsFromExam(Exam $exam, array $examQuestionIds): int
    {
        $removed = 0;
        foreach (array_unique(array_map('intval', $examQuestionIds)) as $eqId) {
            if ($this->exams->detachQuestion($exam->id, $eqId)) {
                $removed++;
            }
        }
        if ($removed > 0) {
            $this->recalculateTotalMarks($exam);
        }
        return $removed;
    }

    /** تعديل درجة سؤال معين (marks_override) — الإجمالي بيتحسب تاني تلقائيًا. */
    public function setQuestionMarks(Exam $exam, $examQuestionId, float $marks): bool
    {
        $this->assertMarksEditable($exam);
        if ($marks < 0.25) {
            throw new \InvalidArgumentException('Question marks must be at least 0.25.');
        }
        $ok = $this->exams->setMarksOverride($exam->id, $examQuestionId, $marks);
        if ($ok) {
            $this->recalculateTotalMarks($exam);
        }
        return $ok;
    }

    /**
     * يحدد إجمالي الدرجات ويوزّعه بالتساوي على الأسئلة اليدوية (بعد خصم
     * درجات أقسام الـ pools الثابتة). باقي القسمة بيروح لآخر سؤال عشان
     * مجموع الدرجات يطلع مطابق للإجمالي بالظبط. بعدها الموظف يقدر يعدّل
     * درجة أي سؤال لوحده (setQuestionMarks).
     */
    public function distributeTotalMarks(Exam $exam, float $total): void
    {
        $this->assertMarksEditable($exam);

        $manual = $this->exams->manualQuestionsFor($exam->id);
        $count = count($manual);
        if ($count === 0) {
            throw new \InvalidArgumentException('Add questions first, then set the total points.');
        }

        $poolTotal = 0.0;
        foreach ($this->exams->poolConfigsFor($exam->id) as $config) {
            $poolTotal += (float) $config->questions_to_select * (float) $config->marks_per_question;
        }

        $remaining = round($total - $poolTotal, 2);
        if ($remaining < 0.25 * $count) {
            throw new \InvalidArgumentException('Total points is too low: each question needs at least 0.25 points.');
        }

        $each = floor(($remaining / $count) * 100) / 100;
        $used = 0.0;
        foreach ($manual as $i => $pivot) {
            $marks = $i === $count - 1 ? round($remaining - $used, 2) : $each;
            $this->exams->setMarksOverride($exam->id, $pivot->id, $marks);
            $used += $marks;
        }

        $this->recalculateTotalMarks($exam);
    }

    /** بعد ما طالب يبدأ محاولة، تغيير الدرجات هيبوّظ النتايج اللي اتحسبت. */
    private function assertMarksEditable(Exam $exam): void
    {
        if (\App\Models\ExamAttempt::where('exam_id', $exam->id)->exists()) {
            throw new \InvalidArgumentException('Points cannot be changed after students have started attempts.');
        }
    }

    public function reorderExamQuestions(Exam $exam, array $orderedExamQuestionIds): void
    {
        $this->exams->reorder($exam->id, $orderedExamQuestionIds);
    }

    /** Round 7 — إجمالي الدرجات = مجموع الأسئلة اليدوية + مجموع (questions_to_select × marks_per_question) لكل pool config. */
    private function recalculateTotalMarks(Exam $exam): void
    {
        $total = 0;
        foreach ($this->exams->manualQuestionsFor($exam->id) as $pivot) {
            $total += (float) ($pivot->marks_override ?? $pivot->question->marks);
        }
        foreach ($this->exams->poolConfigsFor($exam->id) as $config) {
            $total += (float) $config->questions_to_select * (float) $config->marks_per_question;
        }
        $exam->total_marks = round($total, 2);
        $exam->save();
    }

    // ---------------------------------------------------------------
    // Targeting — Round 2 (Phase 8 في السبك)
    // ---------------------------------------------------------------

    /** صفوف الاستهداف الحالية للامتحان + عدد الطلاب المؤهلين دلوقتي. */
    public function examTargetsSummary(Exam $exam): array
    {
        return [
            'targets'          => array_map(fn ($t) => $t->toArray(), $this->targets->forExam($exam->id)),
            'matching_count'   => $this->targets->countForExamSaved($exam->id, $exam->university_id),
        ];
    }

    /** عينة (بحد أقصى 200) من الطلاب المؤهلين دلوقتي — لمراجعة المدرس قبل النشر. */
    public function examTargetsPreviewList(Exam $exam): array
    {
        return $this->targets->listForExamSaved($exam->id, $exam->university_id);
    }

    /**
     * Round 2 UX follow-up: typeahead بحث عن طالب بعينه لفورم الاستهداف
     * ("Add Specific Student") — محكوم بجامعة عضو هيئة التدريس نفسه، مش
     * أي جامعة تانية. أقل من حرفين = ملوش داعي نجيب روستر كامل الجامعة
     * (زي أي typeahead تاني)، فبنرجع مصفوفة فاضية بدل ما نستعلم.
     * @return array<int,array<string,mixed>>
     */
    public function searchStudentsForTargeting(int $universityId, string $q, int $limit = 20): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }

        return $this->students->searchForTargeting($universityId, $q, $limit);
    }

    /**
     * Round 2 UX follow-up: مجموعات جامعة عضو هيئة التدريس (مع عدد
     * الأعضاء الحي) — لقايمة "Group" في فورم الاستهداف بدل ما يكتب Group
     * ID رقمي أعمى.
     * @return array<int,array<string,mixed>>
     */
    public function groupsForTargeting(int $universityId): array
    {
        return $this->groups->forUniversityWithCounts($universityId);
    }

    /**
     * بيحفظ صفوف الاستهداف (يستبدل القديمة بالكامل) بعد ما يتأكد كل صف
     * منها بيشاور على كيان (كلية/قسم/برنامج/مجموعة/طالب) بتاع نفس جامعة
     * الامتحان — عضو هيئة تدريس مقدرش يستهدف كلية/طالب من جامعة تانية.
     * @throws \InvalidArgumentException
     */
    public function replaceExamTargets(Exam $exam, array $rows): array
    {
        $normalized = array_map(fn ($row) => $this->validateAndNormalizeTargetRow($row, (int) $exam->university_id), $rows);

        // Phase 29 — لازم نلقط "مين كان مؤهل قبل كده" قبل الحفظ، وبعدين
        // نقارن بعد الحفظ، عشان نعرف مين "الطلاب الجداد" بس (مش الكل تاني).
        $wasAlreadyVisible = in_array($exam->status, ['published', 'scheduled'], true);
        $beforeUserIds = $wasAlreadyVisible ? $this->targets->userIdsForExamSaved($exam->id, $exam->university_id) : [];

        $this->targets->replaceForExam($exam->id, $normalized);

        if ($wasAlreadyVisible) {
            $afterUserIds = $this->targets->userIdsForExamSaved($exam->id, $exam->university_id);
            $newlyAdded = array_diff($afterUserIds, $beforeUserIds);
            foreach ($newlyAdded as $userId) {
                $this->notifications->notify(
                    $userId,
                    'exam_assigned',
                    'New exam assigned: ' . $exam->title,
                    'You have been added to the list of students for "' . $exam->title . '".',
                    null,
                    'normal'
                );
            }
        }

        return $this->examTargetsSummary($exam->fresh());
    }

    /** بيحسب عدد الطلاب المؤهلين لصفوف استهداف مُقترحة من غير ما يحفظهم — للـ live preview وقت التعديل في الفورم. */
    public function previewTargetCount(Exam $exam, array $rows): int
    {
        $normalized = array_map(fn ($row) => $this->validateAndNormalizeTargetRow($row, (int) $exam->university_id), $rows);
        return $this->targets->countMatching($exam->university_id, $normalized);
    }

    /**
     * @throws \InvalidArgumentException لو الصف فاضي تمامًا أو بيشاور على
     *   كيان مش موجود/مش تابع لجامعة الامتحان.
     */
    private function validateAndNormalizeTargetRow(array $row, int $universityId): array
    {
        $studentId = !empty($row['student_id']) ? (int) $row['student_id'] : null;
        if ($studentId !== null) {
            if (!$this->students->findOwned($studentId, $universityId)) {
                throw new \InvalidArgumentException("Student #{$studentId} was not found in your university.");
            }
            return ['student_id' => $studentId];
        }

        $facultyId = !empty($row['faculty_id']) ? (int) $row['faculty_id'] : null;
        if ($facultyId !== null && !$this->faculties->findOwned($facultyId, $universityId)) {
            throw new \InvalidArgumentException("Faculty #{$facultyId} was not found in your university.");
        }

        $departmentId = !empty($row['department_id']) ? (int) $row['department_id'] : null;
        if ($departmentId !== null && !$this->departments->findOwnedByUniversity($departmentId, $universityId)) {
            throw new \InvalidArgumentException("Department #{$departmentId} was not found in your university.");
        }

        $groupId = !empty($row['group_id']) ? (int) $row['group_id'] : null;
        if ($groupId !== null && !$this->groups->findOwned($groupId, $universityId)) {
            throw new \InvalidArgumentException("Group #{$groupId} was not found in your university.");
        }

        $academicYear = isset($row['academic_year']) && $row['academic_year'] !== '' && $row['academic_year'] !== null
            ? (int) $row['academic_year']
            : null;
        if ($academicYear !== null && ($academicYear < 1 || $academicYear > 8)) {
            throw new \InvalidArgumentException('academic_year must be between 1 and 8.');
        }

        return [
            'faculty_id'    => $facultyId,
            'department_id' => $departmentId,
            'academic_year' => $academicYear,
            'group_id'      => $groupId,
        ];
    }

    /**
     * draft/scheduled -> published أو scheduled (لو start_at لسه في
     * المستقبل). محتاج على الأقل سؤال واحد وقاعدة استهداف واحدة —
     * زي ما السبك قال: "المدرس لازم يقدر يحدد بالظبط مين هيقدر يدخل".
     * Round 7 — "سؤال واحد" هنا معناه سؤال يدوي واحد *أو* pool config
     * واحد على الأقل (مش لازم الاتنين مع بعض)، عشان امتحان مبني بالكامل
     * على pools (من غير أي سؤال يدوي) يبقى قابل للنشر برضه.
     * @throws \InvalidArgumentException
     */
    public function publishExam(Exam $exam): Exam
    {
        if ($exam->is_template) {
            throw new \InvalidArgumentException('A template cannot be published. Duplicate it to create an exam from it.');
        }
        if (!in_array($exam->status, ['draft', 'scheduled'], true)) {
            throw new \InvalidArgumentException('Only a draft or scheduled exam can be published.');
        }
        if ($this->exams->manualQuestionsFor($exam->id) === [] && $this->exams->poolConfigsFor($exam->id) === []) {
            throw new \InvalidArgumentException('Add at least one question (or question pool) before publishing.');
        }
        if ($this->targets->countForExam($exam->id) === 0) {
            throw new \InvalidArgumentException('Define at least one target audience before publishing.');
        }

        $exam->status = ($exam->start_at && $exam->start_at->isFuture()) ? 'scheduled' : 'published';
        $exam->save();

        $isScheduled = $exam->status === 'scheduled';
        $body = $isScheduled
            ? ('"' . $exam->title . '" has been scheduled' . ($exam->start_at ? ' for ' . $exam->start_at->format('Y-m-d H:i') : '') . '.')
            : ('"' . $exam->title . '" is now available for you to take.');

        foreach ($this->targets->userIdsForExamSaved($exam->id, $exam->university_id) as $userId) {
            $this->notifications->notify(
                $userId,
                $isScheduled ? 'exam_scheduled' : 'exam_available',
                $isScheduled ? ('Exam scheduled: ' . $exam->title) : ('Exam available: ' . $exam->title),
                $body,
                null,
                'normal'
            );
        }

        return $exam;
    }

    /** بيرجع الامتحان لـ draft — Round 2 مفيهوش exam_attempts لسه، فالعملية دي مفتوحة من غير قيد إضافي دلوقتي. */
    public function unpublishExam(Exam $exam): Exam
    {
        if (!in_array($exam->status, ['scheduled', 'published'], true)) {
            throw new \InvalidArgumentException('Only a scheduled or published exam can be unpublished.');
        }
        $exam->status = 'draft';
        $exam->save();

        return $exam;
    }
}
