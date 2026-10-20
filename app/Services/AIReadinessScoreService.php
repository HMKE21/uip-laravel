<?php

namespace App\Services;

use App\Models\AIReadinessScore;
use App\Models\Project;
use App\Repositories\AIAnalysisRepository;
use App\Repositories\SettingRepository;
use App\Services\Ai\AIClient;
use App\Services\Concerns\ConfiguresAIClient;

/**
 * منقولة من app/Services/AIReadinessScoreService.php القديمة — بند 21.
 * بتقيّم مشروع من 0-100 عبر 4 محاور (تقني/سوقي/ابتكار/عرض وتوثيق) باستخدام
 * Services\Ai\AIClient، ومتخزّنة عبر AIAnalysisRepository. أبدًا مابتختلقش
 * درجة: لو الـ AI client مش مُعد أو النداء فشل، بترمي — المستدعي
 * (AIAnalysisService) بيعرضها كـ "غير متاح"، مش رقم وهمي.
 */
class AIReadinessScoreService
{
    use ConfiguresAIClient;

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

    /** @throws \RuntimeException لو غير مُعد أو نداء/فك الـ AI فشل */
    public function analyze(Project $project): AIReadinessScore
    {
        $system = 'You are a rigorous, fair reviewer (university supervisor + senior engineer) scoring a student project for readiness. '
            . 'You are given the written description AND real evidence from its GitHub repository (README, languages, structure, tests/CI signals, commit history, manifest, real code samples). '
            . 'Base every score on that evidence and cite it mentally: a project with a strong description but thin/no code, no tests, no README, or few commits must score LOWER; '
            . 'a project with real architecture, working structure, tests, CI, docs and sustained commits scores HIGHER. If no repository is linked, score from the description only and keep technical_score conservative. '
            . 'Rubric per dimension (0-100): '
            . 'technical_score = code structure & separation of concerns, correct use of the stack, error handling, security hygiene, tests, CI, completeness of features visible in the code; '
            . 'market_score = clarity of the real problem, target users, demand, and how it compares to existing alternatives; '
            . 'innovation_score = what is genuinely new or non-trivial versus a standard tutorial/CRUD app; '
            . 'presentation_score = README quality, install/run instructions, screenshots/demo, documentation and how clearly the project is explained. '
            . 'Calibration: 90+ is exceptional and rare; 70-89 solid and near-deployable; 50-69 working but with clear gaps; 30-49 basic/tutorial-level; below 30 incomplete. A plain CRUD app with no tests and a default README is 35-50, not 70. '
            . 'Be discriminating — use the full range and never default to mid-range numbers. '
            . 'Respond as JSON: {"overall_score": number, "technical_score": number, "market_score": number, '
            . '"innovation_score": number, "presentation_score": number}. overall_score must reflect the weighted view: technical 35%, innovation 25%, market 20%, presentation 20%.';

        $user = $this->projectContext($project);

        $data = $this->completeJson($this->client, $system, $user);

        $clamp = fn ($v) => max(0.0, min(100.0, (float) ($v ?? 0)));

        return $this->repo->upsertReadiness((int) $project->id, [
            'overall_score'      => $clamp($data['overall_score'] ?? null),
            'technical_score'    => $clamp($data['technical_score'] ?? null),
            'market_score'       => $clamp($data['market_score'] ?? null),
            'innovation_score'   => $clamp($data['innovation_score'] ?? null),
            'presentation_score' => $clamp($data['presentation_score'] ?? null),
        ]);
    }
}
