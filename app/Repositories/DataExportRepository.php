<?php

namespace App\Repositories;

use App\Models\DataExport;
use Illuminate\Support\Facades\DB;

/**
 * منقولة كاملة من app/Repositories/DataExportRepository.php القديمة —
 * forUser() كانت جت مع بند 24 (Data Analysis Dashboard)، وباقي
 * الميثودز دي هنا دلوقتي مع بند 24 batch 2 (Export Center — تنفيذ
 * فعلي). Data access لـ `data_exports` (migration 042) — سجل تدقيق كل
 * تصدير CSV/Excel/PDF من بورتال Data Analysis.
 */
class DataExportRepository
{
    public function create(array $data): DataExport
    {
        if (isset($data['filters']) && is_array($data['filters'])) {
            $data['filters'] = json_encode($data['filters'], JSON_UNESCAPED_UNICODE);
        }
        return DataExport::create($data);
    }

    public function markCompleted($id, string $filePath): bool
    {
        $export = DataExport::find($id);
        if (!$export) {
            return false;
        }
        $export->fill(['status' => 'completed', 'file_path' => $filePath, 'completed_at' => now()]);
        return $export->save();
    }

    /** آخر عمليات تصدير المستخدم، الأحدث أولًا — منقولة من DataExportRepository::forUser() القديمة. */
    public function forUser($userId, int $limit = 50): array
    {
        return DB::table('data_exports')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    /** تاريخ تنفيذ جدولة تصدير واحدة، الأحدث أولًا — يستخدمه بانل Scheduled Exports. */
    public function forSchedule($scheduleId, int $limit = 10): array
    {
        return DB::table('data_exports')
            ->where('schedule_id', $scheduleId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    public function findOwned($id, $userId): ?DataExport
    {
        $e = DataExport::find($id);
        if ($e && (string) $e->user_id === (string) $userId) {
            return $e;
        }
        return null;
    }

    /** صفوف مملوكة من ضمن الـ ids المعطاة — تستخدم لحل file_path قبل الحذف من الديسك، ولحصر حذف جماعي على المستخدم ده. */
    public function forUserByIds($userId, array $ids): array
    {
        if (!$ids) {
            return [];
        }
        return DB::table('data_exports')
            ->where('user_id', $userId)
            ->whereIn('id', $ids)
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    public function delete($id, $userId): bool
    {
        $e = $this->findOwned($id, $userId);
        if (!$e) {
            return false;
        }
        return (bool) $e->delete();
    }

    /** بتحذف كل صف مملوك من ضمن الـ ids المعطاة. بترجع عدد اللي اتحذف فعلًا. */
    public function deleteByIds(array $ids, $userId): int
    {
        $count = 0;
        foreach ($this->forUserByIds($userId, $ids) as $row) {
            if (DataExport::find($row['id'])?->delete()) {
                $count++;
            }
        }
        return $count;
    }

    /** بتحذف كل تصدير مملوك لهذا المستخدم. بترجع عدد اللي اتحذف. */
    public function deleteAllForUser($userId): int
    {
        $count = 0;
        foreach ($this->forUser($userId, 1000) as $row) {
            if (DataExport::find($row['id'])?->delete()) {
                $count++;
            }
        }
        return $count;
    }
}