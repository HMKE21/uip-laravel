<?php

namespace App\Services;

use App\Models\Project;
use App\Repositories\AIAnalysisRepository;
use App\Repositories\SettingRepository;
use App\Services\Ai\AIClient;
use App\Services\Concerns\ConfiguresAIClient;

/**
 * منقولة من app/Services/AIImprovementSuggestionService.php القديمة —
 * بند 21. بتولّد اقتراحات تحسين ملموسة وخاصة بالمشروع، كل واحدة بفئة
 * وأولوية. كل run جديد بيستبدل الدفعة القديمة بالكامل (شوف
 * AIAnalysisRepository::replaceSuggestions()).
 */
class AIImprovementSuggestionService
{
    use ConfiguresAIClient;

    private const CATEGORIES = ['technical', 'presentation', 'market_fit', 'documentation', 'other'];
    private const PRIORITIES = ['low', 'medium', 'high'];

    private AIClient $client;
    private AIAnalysisRepository $repo;

    public function __construct(AIClient $client, SettingRepository $settings, AIAnalysisRepository $repo)
    {
        $this->client = $client;
        $this->applySettingsOverrides($this->client, $settings);
        $this->repo = $repo;
    }

    public function isAvailable(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * @return \App\Models\ImprovementSuggestion[]
     * @throws \RuntimeException لو غير مُعد أو نداء/فك الـ AI فشل
     */
    public function suggest(Project $project): array
    {
        $system = 'You are a senior engineer mentoring a student. Using the real repository evidence provided (README, structure, tests/CI signals, manifest, actual code samples), '
            . 'give 6-8 concrete, prioritised, actionable improvements, ordered most important first. '
            . 'Every suggestion MUST be grounded in what you actually see: name the specific file, folder, library or missing artifact (e.g. "AuthController.cs builds SQL by string concatenation — switch to parameterised queries", '
            . '"no tests directory exists — add xUnit tests for the grade calculation service", "README has no setup steps"). '
            . 'Cover a mix of: security, architecture/code quality, testing & CI, documentation, features/UX, deployment. '
            . 'Never give generic advice that would fit any project, and never suggest something the repository already has. '
            . 'Respond as JSON: {"suggestions": [{"suggestion": string (1-2 sentences, max 400 chars: the problem found + the exact fix), '
            . '"category": string (one of: technical, presentation, market_fit, documentation, other), '
            . '"priority": string (one of: low, medium, high — high only for real security issues, broken/missing core functionality, or missing basics like tests/README)}]}.'
            . $this->languageDirective();

        $user = $this->projectContext($project);

        $data = $this->completeJson($this->client, $system, $user);
        $raw = (array) ($data['suggestions'] ?? []);

        $clean = [];
        foreach ($raw as $s) {
            if (!is_array($s) || empty($s['suggestion'])) {
                continue;
            }
            $category = in_array($s['category'] ?? '', self::CATEGORIES, true) ? $s['category'] : 'other';
            $priority = in_array($s['priority'] ?? '', self::PRIORITIES, true) ? $s['priority'] : 'medium';
            $clean[] = [
                'suggestion' => mb_substr((string) $s['suggestion'], 0, 500),
                'category'   => $category,
                'priority'   => $priority,
            ];
        }

        if (!$clean) {
            throw new \RuntimeException('AI provider returned no usable suggestions.');
        }

        return $this->repo->replaceSuggestions((int) $project->id, $clean)->all();
    }
}
