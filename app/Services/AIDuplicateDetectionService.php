<?php

namespace App\Services;

use App\Models\Project;
use App\Repositories\ProjectRepository;
use App\Repositories\SettingRepository;
use App\Services\Ai\AIClient;
use App\Services\Concerns\ConfiguresAIClient;

/**
 * منقولة من app/Services/AIDuplicateDetectionService.php القديمة — بند 21.
 * بتلاقي مرشحين محتمل يكونوا نفس فكرة مشروع الهدف — إعادة تقديم، فريق
 * متداخل، أو مفهوم مطابق تقريبًا بصياغة مختلفة — عكس "قريب بالمجال" بس
 * (AISemanticSearchService::findSimilar()). نفس شكل محرك المطابقة (prompt
 * قائمة مرشحين مرقّمة، Services\Ai\AIClient، trait ConfiguresAIClient) لكن:
 *   - حوض المرشحين هنا كل مشروع submitted فأعلى على مستوى المنصة كلها
 *     (ProjectRepository::candidatesForDuplicateCheck())، مش منشور بس —
 *     التكرار مهم من لحظة ما يدخل المراجعة؛
 *   - كل match معاه "likelihood" رقم من 0-100 احتمال إنهم تكرار، مش سبب
 *     نصي بس — السؤال الحقيقي هنا "يستاهل يتبلّغ للمراجع ولا لأ".
 * مش متخزّنة (نفس سبب AISemanticSearchService: حوض المرشحين بيتغير أول ما
 * حاجة جديدة تتقدّم) — دايمًا نداء حي عند الطلب.
 */
class AIDuplicateDetectionService
{
    use ConfiguresAIClient;

    /** كام مرشح نديه للموديل — بيحدد الـ prompt على catalogs كبيرة. */
    private const MAX_CANDIDATES = 60;

    /** تحت الرقم ده، التطابق "قريب بالمجال" بالكتير — مش يستاهل يتبلّغ كتكرار محتمل. */
    private const MIN_LIKELIHOOD = 50.0;

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
     * @return array<int,array{uuid:string,title:string,category:string,owner_name:string,likelihood:float,reason:string}>
     *         مرتبة بـ likelihood تنازليًا.
     * @throws \RuntimeException لو غير مُعد أو نداء/فك الـ AI فشل
     */
    public function detect(Project $project, int $limit = 5): array
    {
        $candidates = $this->candidatePool($project->uuid);
        if (!$candidates) {
            return [];
        }

        $system = 'You detect potential DUPLICATE research projects on a university innovation platform — candidates that '
            . 'likely represent the SAME underlying idea, hypothesis, or solution as the target (e.g. a resubmission, an '
            . 'overlapping team, or a near-identical concept worded differently) — not merely projects in the same general '
            . 'topic or domain (that is similarity, not duplication). Given a target project and a numbered candidate list, '
            . 'identify only candidates that are plausibly duplicates. Respond as JSON: '
            . '{"duplicates": [{"index": number (from the candidate list), "likelihood": number (0-100, confidence this is '
            . 'a duplicate rather than merely similar), "reason": string (1 short sentence, specific to the overlap)}]}. '
            . 'Only include candidates with likelihood 50 or higher. If none qualify, return an empty array — never pad with weak matches.';

        $user = "Target project:\n" . $this->projectContext($project)
            . "\n\nCandidates:\n" . $this->candidateList($candidates);

        $data = $this->completeJson($this->client, $system, $user);

        return $this->parseDuplicates($data, $candidates, $limit);
    }

    /** @return array<int,array<string,mixed>> صفوف مرشح خام، بمفتاح index عشان [n] refs في الـ prompt. */
    private function candidatePool(string $excludeUuid): array
    {
        return array_values($this->projects->candidatesForDuplicateCheck($excludeUuid, self::MAX_CANDIDATES));
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

    private function parseDuplicates(array $data, array $candidates, int $limit): array
    {
        $matches = (array) ($data['duplicates'] ?? []);

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
            $likelihood = max(0.0, min(100.0, (float) ($m['likelihood'] ?? 0)));
            if ($likelihood < self::MIN_LIKELIHOOD) {
                continue;
            }
            $seen[$idx] = true;
            $row = $candidates[$idx];
            $results[] = [
                'uuid'       => $row['uuid'],
                'title'      => $row['title_en'] ?: $row['title_ar'],
                'category'   => $row['category'] ?: 'Unspecified',
                'owner_name' => $row['owner_name'] ?? '',
                'likelihood' => $likelihood,
                'reason'     => mb_substr((string) ($m['reason'] ?? ''), 0, 300),
            ];
        }

        usort($results, fn ($a, $b) => $b['likelihood'] <=> $a['likelihood']);

        return array_slice($results, 0, $limit);
    }
}
