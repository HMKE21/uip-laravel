<?php

namespace App\Services;

use App\Repositories\SettingRepository;
use App\Services\Ai\AIClient;
use App\Services\Concerns\ConfiguresAIClient;

/**
 * Exam & Assessment System — "Add Questions" AI fallback (Round 9 UX
 * follow-up). "Use the project's existing AI integration if available.
 * Do not create a second unrelated AI architecture" — same pattern as
 * AiExamGradingService (ConfiguresAIClient + Services\Ai\AIClient +
 * completeJson()), no new transport, driven by the same admin-configured
 * provider (Admin → AI Controls) as everything else under App\Services\Ai*.
 *
 * The bulk "Add Questions" box (AcademicStaffQuestionBankDetail.jsx,
 * AddQuestionsPanel) already has a fast, free, zero-latency regex parser
 * (src/utils/questionParsing.js — a near-duplicate copy also lives
 * locally in that page file) that recognizes a handful of common paste
 * shapes: lettered/numbered options, "Answer: X" lines, "---" block
 * separators. This service is the fallback for text that parser can't
 * make sense of — free-form notes, a paragraph per question with no
 * markers, mixed formats in one paste, etc. — asking the AI model to do
 * the same segmentation/classification job the regex parser does, but
 * with real language understanding instead of pattern matching.
 *
 * Never invents questions/options/answers that aren't in the source
 * text (same "don't fabricate on failure" contract as the rest of
 * App\Services\Ai*) — an mcq/true_false found in the text but whose
 * correct answer can't be confidently determined is downgraded to essay
 * (see the exactly-one-correct-option check below) rather than guessing,
 * so a wrong auto-selected "correct" answer never silently reaches the
 * question bank. The instructor still reviews every parsed question in
 * the existing AddQuestionsPanel UI before anything is saved.
 */
class AiQuestionParsingService
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
     * @return array<int, array{type:string, prompt:string, options:array<int,array{option_text:string,is_correct:bool}>, correct_answer:?string}>
     * @throws \RuntimeException لو الـ AI مش مُعد أو النداء/فك الرد فشل
     */
    public function parse(string $rawText): array
    {
        $system = 'You split raw, messily-formatted exam-question text into a clean, structured list of individual '
            . 'questions. The instructor may paste anything: numbered questions, a paragraph per question with no '
            . 'markers, mixed languages (Arabic/English), inconsistent option markers, or several unrelated formats '
            . 'in the same paste. For each question you find, decide its type: "mcq" (3+ distinct options with '
            . 'exactly one correct), "true_false" (a true/false or صح/خطأ question), "short_answer" (expects a '
            . 'brief factual one-line answer), or "essay" (expects a longer free-form written answer with no single '
            . 'correct string). Never invent questions, options, or answers that are not present or clearly implied '
            . 'in the source text — if you cannot confidently determine the correct answer for an mcq/true_false/'
            . 'short_answer question, leave "correct_answer" null (or leave every option\'s "is_correct" false for '
            . 'mcq) rather than guessing. Preserve the original language of each question — do not translate. '
            . 'Respond as JSON: {"questions": [{"type": "mcq"|"true_false"|"short_answer"|"essay", '
            . '"prompt": string, '
            . '"options": [{"option_text": string, "is_correct": boolean}] (mcq only; empty array otherwise), '
            . '"correct_answer": string|null (for true_false: "true" or "false"; for short_answer: the expected '
            . 'answer text; null for mcq — correctness lives on the option itself — and for essay)}]}.';

        $user = "Split and classify the following into individual questions:\n\n" . $rawText;

        $data = $this->completeJson($this->client, $system, $user);

        $questions = $data['questions'] ?? null;
        if (!is_array($questions)) {
            throw new \RuntimeException('AI provider response did not include a "questions" array.');
        }

        $result = [];
        foreach ($questions as $q) {
            if (!is_array($q) || empty($q['prompt'])) {
                continue;
            }

            $type = in_array($q['type'] ?? null, ['mcq', 'true_false', 'short_answer', 'essay'], true)
                ? $q['type']
                : 'essay';

            $options = [];
            if ($type === 'mcq' && !empty($q['options']) && is_array($q['options'])) {
                foreach ($q['options'] as $opt) {
                    if (!is_array($opt) || empty($opt['option_text'])) {
                        continue;
                    }
                    $options[] = [
                        'option_text' => trim((string) $opt['option_text']),
                        'is_correct'  => (bool) ($opt['is_correct'] ?? false),
                    ];
                }
                // مفيش صح واحد محدد بالظبط -> مش موثوق فيه كـ mcq، رجّعه
                // essay بدل ما نسيب/نخمّن إجابة صحيحة (نفس مبدأ "متختلقش" فوق).
                if (count(array_filter($options, static fn (array $o) => $o['is_correct'])) !== 1) {
                    $type = 'essay';
                    $options = [];
                }
            }

            $correctAnswer = null;
            if ($type === 'true_false') {
                $correctAnswer = \App\Support\TrueFalse::normalize($q['correct_answer'] ?? null);
            } elseif ($type === 'short_answer') {
                $correctAnswer = isset($q['correct_answer']) && $q['correct_answer'] !== ''
                    ? trim((string) $q['correct_answer'])
                    : null;
            }

            $result[] = [
                'type'           => $type,
                'prompt'         => trim((string) $q['prompt']),
                'options'        => $options,
                'correct_answer' => $correctAnswer,
            ];
        }

        return $result;
    }

    /**
     * يعدّل أسئلة موجودة بتعليمات الدكتور (مثلًا: صحّح الإملاء، خلّي الأسئلة
     * أصعب، اقترح الإجابة الصحيحة، ترجم للإنجليزي). مبيحفظش حاجة — الأسئلة
     * بترجع بنفس الشكل والترتيب والعدد، والدكتور يراجعها ويضغط Add All.
     * بيحافظ على نوع السؤال إلا لو التعليمات طلبت غير كده صراحةً، ومش
     * بيخترع إجابات صحيحة إلا لو التعليمات طلبت اقتراحها.
     *
     * @param array<int, array{type:string, prompt:string, options?:array, correct_answer?:?string}> $questions
     * @return array<int, array{type:string, prompt:string, options:array, correct_answer:?string}>
     * @throws \RuntimeException
     */
    public function revise(array $questions, string $instruction): array
    {
        $system = 'You are an assistant helping an instructor edit exam questions. You receive a JSON list of '
            . 'questions and an editing instruction. Apply the instruction to EVERY question in the list and return '
            . 'the revised list in the SAME ORDER with the SAME NUMBER of items (never drop, merge, split or add '
            . 'questions). Keep each question\'s type unless the instruction explicitly asks to change it. Preserve '
            . 'the original language of each question unless the instruction asks to translate. Do not invent a '
            . 'correct answer unless the instruction asks you to suggest or fix it; when you do suggest one, mark '
            . 'exactly one option as correct for mcq. Respond as JSON: {"questions": [{"type": "mcq"|"true_false"|'
            . '"short_answer"|"essay", "prompt": string, "options": [{"option_text": string, "is_correct": boolean}] '
            . '(mcq only; empty array otherwise), "correct_answer": string|null (true_false: "true" or "false"; '
            . 'short_answer: the expected answer; null otherwise)}]}.';

        $user = "Instruction: " . trim($instruction) . "\n\nQuestions (JSON):\n"
            . json_encode(array_values($questions), JSON_UNESCAPED_UNICODE);

        $data = $this->completeJson($this->client, $system, $user);

        $revised = $data['questions'] ?? null;
        if (!is_array($revised) || count($revised) !== count($questions)) {
            throw new \RuntimeException('AI provider did not return one revised question per input question.');
        }

        $result = [];
        foreach (array_values($revised) as $i => $q) {
            $original = array_values($questions)[$i];
            if (!is_array($q) || trim((string) ($q['prompt'] ?? '')) === '') {
                $result[] = $original + ['options' => [], 'correct_answer' => null];
                continue;
            }

            $type = in_array($q['type'] ?? null, ['mcq', 'true_false', 'short_answer', 'essay'], true)
                ? $q['type']
                : ($original['type'] ?? 'essay');

            $options = [];
            if ($type === 'mcq' && is_array($q['options'] ?? null)) {
                foreach ($q['options'] as $opt) {
                    if (!is_array($opt) || trim((string) ($opt['option_text'] ?? '')) === '') {
                        continue;
                    }
                    $options[] = ['option_text' => trim((string) $opt['option_text']), 'is_correct' => (bool) ($opt['is_correct'] ?? false)];
                }
            }

            $correctAnswer = null;
            if ($type === 'true_false') {
                $correctAnswer = \App\Support\TrueFalse::normalize($q['correct_answer'] ?? null);
            } elseif ($type === 'short_answer' && trim((string) ($q['correct_answer'] ?? '')) !== '') {
                $correctAnswer = trim((string) $q['correct_answer']);
            }

            $result[] = [
                'type'           => $type,
                'prompt'         => trim((string) $q['prompt']),
                'options'        => $options,
                'correct_answer' => $correctAnswer,
            ];
        }

        return $result;
    }
}
