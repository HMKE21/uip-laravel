<?php

namespace App\Services;

use App\Models\Question;
use App\Repositories\SettingRepository;
use App\Services\Ai\AIClient;
use App\Services\Concerns\ConfiguresAIClient;

/**
 * Exam & Assessment System — Round 6 (Phase 18). "Use the project's
 * existing AI integration if available. Do not create a second unrelated
 * AI architecture" — نفس نمط AIClassificationService بالظبط
 * (ConfiguresAIClient + Services\Ai\AIClient + completeJson()، مفيش أي
 * transport جديد). الكلاس ده عمدًا بلا أي معرفة بـ exam_grades/attempts —
 * بياخد سؤال + إجابة نص + الدرجة القصوى + rubric (اختياري)، وبيرجّع
 * array متحقق منه أو يرمي RuntimeException. ExamGradingService هو اللي
 * بيربطه بباقي دورة التصحيح (persistence، exam_grade_history، إلخ).
 *
 * IMPORTANT (Phase 18): الـ system prompt تحت بيمنع صراحة كشف أي
 * chain-of-thought — reasoning_summary لازم يكون ملخص قصير بس، مناسب
 * إنه يتعرض للمدرس/الطالب زي ما هو.
 */
class AiExamGradingService
{
    use ConfiguresAIClient;

    private AIClient $client;

    public function __construct(AIClient $client, SettingRepository $settings)
    {
        $this->client = $client;
        $this->applySettingsOverrides($this->client, $settings);
    }

    public function isAvailable(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * الـ AI بيكتب إجابة نموذجية مختصرة ودقيقة للسؤال (للمدرس يراجعها أو
     * يعتمد عليها التصحيح التلقائي لو المدرس مدخلش إجابة).
     *
     * @throws \RuntimeException لو الـ AI مش مُعد أو النداء/فك الرد فشل
     */
    public function generateModelAnswer(string $prompt, float $maxMarks = 0, ?string $type = null): string
    {
        $system = 'You are an expert university instructor. Write the ideal model answer for the exam question you '
            . 'are given: accurate, complete but concise, covering the key points a full-marks answer must contain. '
            . 'Write the answer in the SAME language as the question. Scale the length to the marks '
            . '(more marks = more points), without padding. '
            . 'Respond as JSON: {"model_answer": string}.';

        $user = 'Question: ' . $prompt
            . ($type ? "\nQuestion type: " . $type : '')
            . ($maxMarks > 0 ? "\nMaximum marks: " . $maxMarks : '');

        $data = $this->completeJson($this->client, $system, $user);
        $answer = trim((string) ($data['model_answer'] ?? ''));
        if ($answer === '') {
            throw new \RuntimeException('AI returned an empty model answer.');
        }
        return mb_substr($answer, 0, 10000);
    }

    /**
     * @param \App\Models\ExamRubricCriterion[] $rubricCriteria
     * @return array{marks_awarded:float,confidence:float,feedback:string,strengths:string[],missing_concepts:string[],reasoning_summary:string}
     * @throws \RuntimeException لو الـ AI مش مُعد أو النداء/فك الرد فشل
     */
    public function grade(Question $question, string $studentAnswer, float $maxMarks, array $rubricCriteria = []): array
    {
        // لو المدرس مدخلش إجابة نموذجية ولا تعليمات، الـ AI يكتب الإجابة بنفسه (ويتخزن للمرات الجاية).
        $this->ensureReferenceAnswer($question, $maxMarks);

        $system = 'You are an impartial, consistent university exam grader. Judge the student answer by its MEANING, '
            . 'not its wording: a correct answer phrased differently, in different order, or in another language '
            . '(Arabic/English) than the model answer must get full credit; never require word-for-word matching. '
            . 'Mark proportionally: full marks if all key points are covered correctly, about half if roughly half '
            . 'are, and 0 if the answer is empty, irrelevant, or wrong. Evaluate the student answer against the model answer, accepted answers, keywords, expected concepts, grading instructions, and '
            . 'rubric supplied to you (whichever are present) — do not use outside knowledge to invent additional '
            . 'requirements the instructor did not specify. Award partial credit where the answer is partially '
            . 'correct. IMPORTANT: never reveal step-by-step reasoning or internal chain-of-thought — '
            . '"reasoning_summary" must be at most two short sentences explaining the grade at a level suitable to '
            . 'show the student directly. '
            . 'Respond as JSON: {"marks_awarded": number (0 to max_marks, decimals allowed), '
            . '"confidence": number (0 to 1), '
            . '"feedback": string (2-4 constructive sentences addressed to the student), '
            . '"strengths": string[] (0-5 short phrases on what the answer got right), '
            . '"missing_concepts": string[] (0-5 short phrases on what was missing or wrong), '
            . '"reasoning_summary": string (max 2 sentences, no chain-of-thought, no hidden notes)}.';

        $user = $this->buildUserPrompt($question, $studentAnswer, $maxMarks, $rubricCriteria);

        $data = $this->completeJson($this->client, $system, $user);

        $marks = max(0.0, min($maxMarks, (float) ($data['marks_awarded'] ?? 0)));
        $confidence = max(0.0, min(1.0, (float) ($data['confidence'] ?? 0)));

        return [
            'marks_awarded'     => round($marks, 2),
            'confidence'        => round($confidence, 2),
            'feedback'          => trim((string) ($data['feedback'] ?? '')),
            'strengths'         => $this->stringList($data['strengths'] ?? []),
            'missing_concepts'  => $this->stringList($data['missing_concepts'] ?? []),
            'reasoning_summary' => trim((string) ($data['reasoning_summary'] ?? '')),
        ];
    }

    private function ensureReferenceAnswer(Question $question, float $maxMarks): void
    {
        if (!empty(trim((string) $question->model_answer)) || !empty(trim((string) $question->ai_model_answer))
            || !empty(trim((string) $question->grading_instructions))) {
            return;
        }
        $question->ai_model_answer = $this->generateModelAnswer((string) $question->prompt, $maxMarks, $question->type);
        $question->save();
    }

    /** @param \App\Models\ExamRubricCriterion[] $rubricCriteria */
    private function buildUserPrompt(Question $question, string $studentAnswer, float $maxMarks, array $rubricCriteria): string
    {
        $lines = [];
        $lines[] = 'Question: ' . $question->prompt;
        $lines[] = 'Maximum marks: ' . $maxMarks;

        $reference = !empty($question->model_answer) ? $question->model_answer : ($question->ai_model_answer ?? null);
        if (!empty($reference)) {
            $lines[] = 'Model answer: ' . $reference;
        }
        if (!empty($question->accepted_answers)) {
            $lines[] = 'Accepted answers: ' . implode(', ', (array) $question->accepted_answers);
        }
        if (!empty($question->keywords)) {
            $lines[] = 'Expected keywords: ' . implode(', ', (array) $question->keywords);
        }
        if (!empty($question->expected_concepts)) {
            $lines[] = 'Expected concepts: ' . implode(', ', (array) $question->expected_concepts);
        }
        if (!empty($question->grading_instructions)) {
            $lines[] = 'Grading instructions from the instructor: ' . $question->grading_instructions;
        }
        if (!empty($rubricCriteria)) {
            $lines[] = 'Rubric (award marks per criterion, total must not exceed the maximum):';
            foreach ($rubricCriteria as $criterion) {
                $lines[] = '- ' . $criterion->label . ': ' . $criterion->max_points . ' points';
            }
        }

        $lines[] = 'Student answer: ' . ($studentAnswer !== '' ? $studentAnswer : '(empty)');

        return implode("\n", $lines);
    }

    /** @return string[] */
    private function stringList($value): array
    {
        return array_values(array_filter(array_map(
            fn ($v) => is_string($v) ? trim($v) : null,
            (array) $value
        )));
    }
}
