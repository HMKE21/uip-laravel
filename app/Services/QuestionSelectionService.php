<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Repositories\ExamAttemptRepository;
use App\Repositories\ExamRepository;
use App\Repositories\QuestionPoolRepository;

/**
 * Exam & Assessment System — Round 7 (Phases 6-7: Question Pools +
 * Randomization). النقطة الوحيدة اللي بتحدد "الطالب ده هيشوف إيه بالظبط
 * جوه الامتحان ده" — بتتنادى مرة واحدة بس من ExamAttemptService::startAttempt()
 * فور ما صف exam_attempts يتعمل، وبتكتب النتيجة في exam_attempt_questions
 * (راجع docblock الموديل والـ migration). القرار ده نهائي وثابت طول عمر
 * المحاولة — resume/reload مبيعيدش السحب أو الخلط.
 *
 * منطق السحب (Phase 6 "Support: Pool size, Required number of questions,
 * Difficulty distribution, Topic distribution, Marks distribution"):
 * - مفيش أي distribution محدد -> سحب عشوائي بسيط من كل أعضاء الـ pool.
 * - difficulty_distribution محدد -> سحب منفصل لكل difficulty بعدده
 *   المطلوب (مجموعهم = questions_to_select، اتفحص وقت الحفظ في
 *   ExamSystemService::attachPoolToExam()).
 * - topic_distribution محدد (ومفيش difficulty_distribution) -> نفس
 *   الفكرة بس بالـ topic. لو الاتنين محددين مع بعض، الأولوية لـ
 *   difficulty_distribution فقط (قرار متعمّد لتجنّب قيدين متعارضين في
 *   نفس السحب — راجع docblock migration 2026_08_28_210000).
 * - marks distribution: مضمونة تلقائيًا لأن كل سؤال مسحوب من pool واحد
 *   بياخد نفس marks_per_question بالظبط (مش question.marks الأصلية) —
 *   فمجموع درجات الـ pool ده ثابت لكل الطلاب بصرف النظر عن الأسئلة
 *   المسحوبة بالذات (راجع ExamRepository::findOrCreateQuestionForPool()).
 *
 * usedQuestionIds بيتراكم عبر كل الـ pool configs على نفس الامتحان (+
 * الأسئلة المتضافة يدويًا) عشان طالب واحد ميشوفش نفس السؤال مرتين حتى
 * لو اتحط في أكتر من pool.
 */
class QuestionSelectionService
{
    public function __construct(
        private ExamRepository $exams,
        private ExamAttemptRepository $attempts,
        private QuestionPoolRepository $pools
    ) {
    }

    /** @throws \InvalidArgumentException لو أي pool مش قادر يوفي بعدد الأسئلة/التوزيع المطلوب وقت السحب الفعلي */
    public function resolveForAttempt(Exam $exam, ExamAttempt $attempt): void
    {
        $manual = $this->exams->manualQuestionsFor($exam->id);
        $orderedExamQuestionIds = array_map(fn ($eq) => $eq->id, $manual);
        $usedQuestionIds = array_map(fn ($eq) => (int) $eq->question_id, $manual);

        foreach ($this->exams->poolConfigsFor($exam->id) as $config) {
            $available = $this->pools->memberQuestionsLight($config->question_pool_id);
            $drawnQuestionIds = $this->draw($available, $config, $usedQuestionIds);

            foreach ($drawnQuestionIds as $questionId) {
                $examQuestion = $this->exams->findOrCreateQuestionForPool(
                    $exam->id,
                    $questionId,
                    $config->question_pool_id,
                    (float) $config->marks_per_question
                );
                $orderedExamQuestionIds[] = $examQuestion->id;
            }
        }

        if ($exam->randomize_questions) {
            shuffle($orderedExamQuestionIds);
        }

        $this->attempts->createAttemptQuestions($attempt->id, $orderedExamQuestionIds);
    }

    /**
     * @param array<int,object{id:int,difficulty:string,topic:?string}> $available
     * @param int[] $usedQuestionIds مُمرّرة بالمرجع — بتتحدث بكل سؤال يتسحب عشان الـ pool اللي بعده ميكررش نفس السؤال
     * @return int[] question_id المسحوبة من الـ pool ده
     * @throws \InvalidArgumentException
     */
    private function draw(array $available, $config, array &$usedQuestionIds): array
    {
        $poolName = $config->pool->name ?? ('#' . $config->question_pool_id);

        if (!empty($config->difficulty_distribution)) {
            return $this->drawByGroup($available, $config->difficulty_distribution, fn ($q) => $q->difficulty, $usedQuestionIds, $poolName, 'difficulty');
        }

        if (!empty($config->topic_distribution)) {
            return $this->drawByGroup($available, $config->topic_distribution, fn ($q) => $q->topic, $usedQuestionIds, $poolName, 'topic');
        }

        $candidates = $this->excludeUsed($available, $usedQuestionIds);
        return $this->takeRandom($candidates, (int) $config->questions_to_select, $usedQuestionIds, $poolName, null);
    }

    /** @param array<string,int> $distribution */
    private function drawByGroup(array $available, array $distribution, callable $groupOf, array &$usedQuestionIds, string $poolName, string $label): array
    {
        $drawn = [];
        foreach ($distribution as $group => $count) {
            $candidates = array_values(array_filter(
                $this->excludeUsed($available, $usedQuestionIds),
                fn ($q) => (string) $groupOf($q) === (string) $group
            ));
            $picked = $this->takeRandom($candidates, (int) $count, $usedQuestionIds, $poolName, "{$label}='{$group}'");
            $drawn = array_merge($drawn, $picked);
        }
        return $drawn;
    }

    /** @return int[] question_id المسحوبة، وبتضيفهم لـ usedQuestionIds بالمرجع فورًا */
    private function takeRandom(array $candidates, int $count, array &$usedQuestionIds, string $poolName, ?string $groupLabel): array
    {
        if (count($candidates) < $count) {
            $suffix = $groupLabel ? " ({$groupLabel})" : '';
            throw new \InvalidArgumentException(
                "Question pool '{$poolName}'{$suffix} does not have enough available questions: needs {$count}, has " . count($candidates) . '.'
            );
        }

        shuffle($candidates);
        $picked = array_slice($candidates, 0, $count);
        $ids = array_map(fn ($q) => (int) $q->id, $picked);
        foreach ($ids as $id) {
            $usedQuestionIds[] = $id;
        }
        return $ids;
    }

    private function excludeUsed(array $available, array $usedQuestionIds): array
    {
        return array_values(array_filter($available, fn ($q) => !in_array((int) $q->id, $usedQuestionIds, true)));
    }
}
