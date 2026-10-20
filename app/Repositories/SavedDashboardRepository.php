<?php

namespace App\Repositories;

use App\Models\SavedDashboard;
use Illuminate\Support\Facades\DB;

/**
 * منقولة كاملة من app/Repositories/SavedDashboardRepository.php القديمة —
 * forUser() كانت جت مع بند 24 (Data Analysis Dashboard)، وباقي الميثودز
 * دي هنا دلوقتي مع بند 24 batch 1 (Saved Dashboards management، enhancement
 * spec section 9). Data access لـ `saved_dashboards` (migration 042,
 * +is_shared/+is_archived via migration 080). `layout` عمود JSON، بيتعامل
 * معاه هنا يدويًا encode/decode — بيحمل قائمة الـ widgets اللي الـ drag &
 * drop builder بناها: [{"type":"kpis","size":"lg"}, ...]، مفاتيح widget
 * حقيقية بس (اتفحصت قبل كده في SavedDashboardService::WIDGETS).
 */
class SavedDashboardRepository
{
    public function create($userId, string $name, array $layout, bool $isDefault = false, bool $isShared = false): SavedDashboard
    {
        if ($isDefault) {
            $this->clearDefault($userId);
        }

        return SavedDashboard::create([
            'user_id'     => $userId,
            'name'        => $name,
            'layout'      => json_encode($layout, JSON_UNESCAPED_UNICODE),
            'is_default'  => $isDefault ? 1 : 0,
            'is_shared'   => $isShared ? 1 : 0,
            'is_archived' => 0,
        ]);
    }

    /**
     * لوحات المستخدم المحفوظة، الافتراضية الأول ثم الأحدث — مع `layout`
     * مفكوك JSON. منقولة من SavedDashboardRepository::forUser() القديمة.
     * @return array<int,array<string,mixed>>
     */
    public function forUser($userId, bool $includeArchived = false): array
    {
        $rows = DB::table('saved_dashboards')
            ->where('user_id', $userId)
            ->when(!$includeArchived, fn ($q) => $q->where('is_archived', 0))
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get();

        return $rows->map(fn ($r) => $this->decode((array) $r))->all();
    }

    /** @return array<int,array<string,mixed>> */
    public function archivedForUser($userId): array
    {
        $rows = DB::table('saved_dashboards')
            ->where('user_id', $userId)
            ->where('is_archived', 1)
            ->orderByDesc('updated_at')
            ->get();

        return $rows->map(fn ($r) => $this->decode((array) $r))->all();
    }

    /** لوحات شاركها محللين تانيين، من غير لوحات المستخدم نفسه. */
    public function sharedByOthers($userId): array
    {
        $rows = DB::table('saved_dashboards as sd')
            ->join('users as u', 'u.id', '=', 'sd.user_id')
            ->where('sd.is_shared', 1)
            ->where('sd.is_archived', 0)
            ->where('sd.user_id', '!=', $userId)
            ->orderByDesc('sd.updated_at')
            ->select('sd.*', 'u.full_name as owner_name')
            ->get();

        return $rows->map(fn ($r) => $this->decode((array) $r))->all();
    }

    public function find($id): ?array
    {
        $d = SavedDashboard::find($id);
        if (!$d) {
            return null;
        }

        return $this->decode($d->toArray());
    }

    public function findOwned($id, $userId): ?SavedDashboard
    {
        $d = SavedDashboard::find($id);
        if ($d && (string) $d->user_id === (string) $userId) {
            return $d;
        }

        return null;
    }

    /** true لو المستخدم ده يقدر يشوف اللوحة دي (صاحبها، أو متشاركة ومش متأرشفة). */
    public function isViewableBy(array $dashboard, $userId): bool
    {
        if ((string) $dashboard['user_id'] === (string) $userId) {
            return true;
        }

        return (bool) $dashboard['is_shared'] && !$dashboard['is_archived'];
    }

    public function update($id, $userId, array $attributes): bool
    {
        $d = $this->findOwned($id, $userId);
        if (!$d) {
            return false;
        }

        if (!empty($attributes['is_default'])) {
            $this->clearDefault($userId);
        }

        if (array_key_exists('layout', $attributes) && is_array($attributes['layout'])) {
            $attributes['layout'] = json_encode($attributes['layout'], JSON_UNESCAPED_UNICODE);
        }

        return $d->fill($attributes)->save();
    }

    public function duplicate($id, $userId, string $newName): ?SavedDashboard
    {
        $source = $this->find($id);
        if (!$source || !$this->isViewableBy($source, $userId)) {
            return null;
        }

        return $this->create($userId, $newName, $source['layout'], false, false);
    }

    public function toggleShare($id, $userId): ?bool
    {
        $d = $this->findOwned($id, $userId);
        if (!$d) {
            return null;
        }

        $newValue = $d->is_shared ? 0 : 1;
        $d->fill(['is_shared' => $newValue])->save();

        return (bool) $newValue;
    }

    public function setArchived($id, $userId, bool $archived): bool
    {
        $d = $this->findOwned($id, $userId);
        if (!$d) {
            return false;
        }

        return $d->fill(['is_archived' => $archived ? 1 : 0])->save();
    }

    public function delete($id, $userId): bool
    {
        $d = $this->findOwned($id, $userId);
        if (!$d) {
            return false;
        }

        return (bool) $d->delete();
    }

    private function clearDefault($userId): void
    {
        DB::table('saved_dashboards')->where('user_id', $userId)->update(['is_default' => 0]);
    }

    /** @return array<string,mixed> صف واحد مع `layout` مفكوك JSON وأعلام bool حقيقية. */
    private function decode(array $row): array
    {
        $row['layout'] = json_decode($row['layout'] ?? '[]', true) ?: [];
        $row['is_default'] = (bool) ($row['is_default'] ?? false);
        $row['is_shared'] = (bool) ($row['is_shared'] ?? false);
        $row['is_archived'] = (bool) ($row['is_archived'] ?? false);
        return $row;
    }
}
