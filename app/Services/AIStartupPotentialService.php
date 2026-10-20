<?php

namespace App\Services;

use App\Models\Project;
use App\Models\StartupPotential;
use App\Repositories\AIAnalysisRepository;
use App\Repositories\SettingRepository;
use App\Services\Ai\AIClient;
use App\Services\Concerns\ConfiguresAIClient;

/**
 * منقولة من app/Services/AIStartupPotentialService.php القديمة — بند 21.
 * بتقيّم القيمة العملية والتطبيقية لمشروع (مش جدوى استثمارية — مشروع الطالب مش منتج بيتباع للمستثمرين): درجة 0-100، تقدير حجم السوق، الميزة
 * التنافسية، وعوامل الخطر الرئيسية.
 */
class AIStartupPotentialService
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
    public function assess(Project $project): StartupPotential
    {
        // NOTE: the table/field names still say "startup potential" for backward compatibility, but the
        // meaning is now "practical & academic value". Student graduation projects are NOT judged as investments.
        $system = 'You assess the PRACTICAL and ACADEMIC VALUE of a university student project — how useful, complete and applicable it is in the real world. '
            . 'Do NOT assess investment, funding, valuation, ROI or startup viability; this is a student project, not a venture. '
            . 'Use the written description and the real repository evidence provided (code, README, structure, tests). '
            . 'potential_score (0-100) = practical value: does it solve a real problem for identifiable users, how complete/working is it, could it be used or extended in practice, and what skills does it demonstrate. '
            . 'Calibrate honestly: a tutorial-level CRUD app is 25-45; a working, documented, well-structured solution to a real need is 65-85. '
            . 'Respond as JSON: {"potential_score": number (0-100), '
            . '"market_size_estimate": string (short phrase for the intended audience/scope, e.g. "Academic / learning" or "Small businesses" or "Public / large user base"), '
            . '"competitive_edge": string (2-3 sentences: what is actually strong or distinctive in THIS project, citing concrete evidence from the code/README), '
            . '"risk_factors": string[] (3-5 concrete limitations or gaps that reduce its practical value, each specific to this project)}.'
            . $this->languageDirective();

        $user = $this->projectContext($project);

        $data = $this->completeJson($this->client, $system, $user);

        return $this->repo->upsertStartupPotential((int) $project->id, [
            'potential_score'      => max(0.0, min(100.0, (float) ($data['potential_score'] ?? 0))),
            'market_size_estimate' => mb_substr((string) ($data['market_size_estimate'] ?? ''), 0, 100),
            'competitive_edge'     => (string) ($data['competitive_edge'] ?? ''),
            'risk_factors'         => array_values(array_map('strval', (array) ($data['risk_factors'] ?? []))),
        ]);
    }
}
