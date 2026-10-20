<?php

namespace App\Services;

use App\Models\Project;
use App\Repositories\ProjectRepository;
use App\Repositories\SettingRepository;
use App\Services\Ai\AIClient;
use App\Services\Concerns\ConfiguresAIClient;

/**
 * منقولة من app/Services/AISemanticSearchService.php القديمة — بند 21
 * (كانت متعطّلة مؤقتًا بند 20). بتلاقي مشاريع منشورة قريبة بالمعنى من
 * مشروع معين — بشكل المشكلة/الحل/المجال، مش تطابق كلمات. مش متخزّنة
 * (مفيش analysis_type ليها، والنتيجة هتبوظ أول ما مشروع جديد يتنشر)،
 * فدايمًا نداء حي عند الطلب عبر Services\Ai\AIClient.
 */
class AISemanticSearchService
{
    use ConfiguresAIClient;

    /** كام مرشح منشور نديه للموديل — بيحدد الـ prompt على catalogs كبيرة. */
    private const MAX_CANDIDATES = 60;

    private AIClient $client;
    private ProjectRepository $projects;

    public function __construct(AIClient $client, SettingRepository $settings, ProjectRepository $projects)
    {
        $this->client = $client;
        $this->applySettingsOverrides($this->client, $settings);
        $this->projects = $projects;
    }

    public function isAvailable(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * @return array<int,array{uuid:string,title:string,category:string,owner_name:string,reason:string}>
     * @throws \RuntimeException لو غير مُعد أو نداء/فك الـ AI فشل
     */
    public function findSimilar(Project $project, int $limit = 5): array
    {
        $candidates = $this->candidatePool($project->uuid);
        if (!$candidates) {
            return [];
        }

        $system = 'You find semantically similar projects — matched by underlying problem, approach, or domain, '
            . 'not just shared keywords. Given a target project and a numbered candidate list, return up to '
            . $limit . ' candidates that are genuinely similar in meaning. Respond as JSON: '
            . '{"matches": [{"index": number (from the candidate list), "reason": string (1 short sentence, specific to why it is similar)}]}. '
            . 'If fewer than ' . $limit . ' candidates are genuinely similar, return fewer — never pad with weak matches.';

        $user = "Target project:\n" . $this->projectContext($project)
            . "\n\nCandidates:\n" . $this->candidateList($candidates);

        return $this->parseMatches($this->completeJson($this->client, $system, $user), $candidates, $limit);
    }

    /** حوض مرشحين من المشاريع المنشورة، مع استبعاد uuid واحد اختياريًا (مشروع ميقدرش يطابق نفسه). */
    private function candidatePool(?string $excludeUuid = null): array
    {
        $rows = $this->projects->published();
        $candidates = [];
        foreach ($rows as $row) {
            if ($excludeUuid !== null && ($row['uuid'] ?? null) === $excludeUuid) {
                continue;
            }
            $candidates[] = $row;
            if (count($candidates) >= self::MAX_CANDIDATES) {
                break;
            }
        }
        return $candidates;
    }

    private function candidateList(array $candidates): string
    {
        $list = [];
        foreach ($candidates as $i => $row) {
            $title = $row['title_en'] ?: $row['title_ar'];
            $summary = mb_substr((string) ($row['summary'] ?? ''), 0, 200);
            $list[] = "[{$i}] {$title} — category: " . ($row['category'] ?: 'Unspecified') . " — {$summary}";
        }
        return implode("\n", $list);
    }

    private function parseMatches(array $data, array $candidates, int $limit): array
    {
        $matches = (array) ($data['matches'] ?? []);

        $results = [];
        $seen = [];
        foreach ($matches as $m) {
            if (!is_array($m) || !isset($m['index'])) {
                continue;
            }
            $idx = (int) $m['index'];
            if (!isset($candidates[$idx]) || isset($seen[$idx])) {
                continue;
            }
            $seen[$idx] = true;
            $row = $candidates[$idx];
            $results[] = [
                'uuid'       => $row['uuid'],
                'title'      => $row['title_en'] ?: $row['title_ar'],
                'category'   => $row['category'] ?: 'Unspecified',
                'owner_name' => $row['owner_name'] ?? '',
                'reason'     => mb_substr((string) ($m['reason'] ?? ''), 0, 300),
            ];
            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }
}
