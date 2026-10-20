<?php

namespace App\Services;

use App\Repositories\DataExplorerRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Services/DataExplorerService.php القديمة — بند 24
 * batch 4 (Data Explorer، enhancement spec section 2). طبقة أوركستريشن
 * خفيفة فوق DataExplorerRepository لصفحتي التصفح (index) وworkspace
 * الـ dataset الواحد (show): بتدمج الكتالوج الثابت مع عدد صفوف حي +
 * حالة المفضّلة/آخر ما اتفتح بتاعة المستخدم، وبتبني حمولة الـ preview
 * لـ dataset واحد.
 */
class DataExplorerService
{
    /**
     * عدد الصفوف استعلام COUNT(*) حقيقي لكل جدول كتالوج (شوف rowCounts()
     * تحت) — رخيص لوحده، بس بيتكرر في كل مرة أي محلل يفتح صفحة Data
     * Explorer. متخزّن مؤقتًا على مستوى المنصة كلها (مش لكل مستخدم، لأن
     * العدد نفسه لكل الناس)؛ favorites/is_favorite فضلت استعلام حي لكل
     * مستخدم تحت، عمرها ما بتتخزن مؤقتًا، عشان تبديل المفضّلة ينعكس فورًا.
     */
    private const ROW_COUNT_TTL_SECONDS = 120;

    public function __construct(private DataExplorerRepository $repo)
    {
    }

    /** @return array<int,array<string,mixed>> صفوف الكتالوج، كل واحد مع row_count حي + علامة is_favorite */
    public function browse(int $userId): array
    {
        $favorites = $this->repo->favoriteKeys($userId);
        $rowCounts = $this->rowCounts();

        $out = [];
        foreach ($this->repo->catalog() as $key => $entry) {
            $dataset = array_merge(['key' => $key], $entry);
            $dataset['row_count'] = $rowCounts[$key] ?? 0;
            $dataset['is_favorite'] = in_array($key, $favorites, true);
            $out[] = $dataset;
        }
        return $out;
    }

    /** @return array<string,int> مفتاح الكتالوج => عدد الصفوف، متخزّن مؤقتًا على مستوى المنصة */
    private function rowCounts(): array
    {
        return Cache::remember('data_explorer:row_counts', self::ROW_COUNT_TTL_SECONDS, function () {
            $counts = [];
            foreach ($this->repo->catalog() as $key => $entry) {
                try {
                    $table = preg_replace('/[^A-Za-z0-9_]/', '', $entry['table']);
                    $counts[$key] = (int) (DB::selectOne('SELECT COUNT(*) AS c FROM `' . $table . '`' . (!empty($entry['soft_deletes']) ? ' WHERE `deleted_at` IS NULL' : ''))->c ?? 0);
                } catch (\Throwable $e) {
                    $counts[$key] = 0;
                }
            }
            return $counts;
        });
    }

    /** @return array<int,array<string,mixed>> لحد $limit dataset آخر ما المستخدم فتحهم، الأحدث أولًا */
    public function recentlyOpened(int $userId, int $limit = 6): array
    {
        $rows = $this->repo->recentlyOpened($userId, $limit);
        $out = [];
        foreach ($rows as $row) {
            $dataset = $this->repo->dataset($row['dataset_key']);
            if ($dataset) {
                $dataset['opened_at'] = $row['opened_at'];
                $out[] = $dataset;
            }
        }
        return $out;
    }

    public function dataset(string $key): ?array
    {
        return $this->repo->dataset($key);
    }

    public function columns(array $dataset): array
    {
        return $this->repo->columns($dataset['table']);
    }

    public function preview(array $dataset, array $opts): array
    {
        return $this->repo->preview($dataset, $opts);
    }

    public function metadata(array $dataset): array
    {
        return $this->repo->metadata($dataset);
    }

    public function relationships(array $dataset): array
    {
        return $dataset['relationships'] ?? [];
    }

    public function toggleFavorite(int $userId, string $key): bool
    {
        return $this->repo->toggleFavorite($userId, $key);
    }

    public function recordOpen(int $userId, string $key): void
    {
        $this->repo->recordOpen($userId, $key);
    }
}
