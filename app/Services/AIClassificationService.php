<?php

namespace App\Services;

use App\Models\AIClassification;
use App\Models\Project;
use App\Repositories\AIAnalysisRepository;
use App\Repositories\SettingRepository;
use App\Services\Ai\AIClient;
use App\Services\Concerns\ConfiguresAIClient;

/**
 * منقولة من app/Services/AIClassificationService.php القديمة — بند 21.
 * بتتوقع أنسب تصنيف لمشروع (+ بدائل باحتمالية)، باستخدام Services\Ai\AIClient.
 */
class AIClassificationService
{
    use ConfiguresAIClient;

    /** الفئات اللي باقي المنصة أصلًا بتفلتر/تتصفح المشاريع بيها. */
    private const CATEGORIES = [
        'Software & AI', 'Hardware & IoT', 'Biotech & Health', 'Sustainability & Energy',
        'FinTech', 'EdTech', 'Agriculture', 'Manufacturing & Materials', 'Social Impact', 'Other',
    ];

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
    public function classify(Project $project): AIClassification
    {
        $categories = implode(', ', self::CATEGORIES);
        $system = "You classify university innovation projects into ONE best-fit category from this fixed list: {$categories}. "
            . 'Respond as JSON: {"predicted_category": string (must be exactly one of the list), '
            . '"confidence": number (0-100), "alternative_categories": string[] (0-3 other plausible categories from the same list, excluding the predicted one)}.';

        $user = $this->projectContext($project);

        $data = $this->completeJson($this->client, $system, $user);

        $predicted = (string) ($data['predicted_category'] ?? 'Other');
        if (!in_array($predicted, self::CATEGORIES, true)) {
            $predicted = 'Other';
        }
        $alternatives = array_values(array_filter(
            (array) ($data['alternative_categories'] ?? []),
            fn ($c) => in_array($c, self::CATEGORIES, true) && $c !== $predicted
        ));

        return $this->repo->upsertClassification((int) $project->id, [
            'predicted_category'     => $predicted,
            'confidence'             => max(0.0, min(100.0, (float) ($data['confidence'] ?? 0))),
            'alternative_categories' => $alternatives,
        ]);
    }
}
