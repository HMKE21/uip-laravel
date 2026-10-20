<?php

namespace App\Repositories;

use App\Models\Report;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/ReportRepository.php القديمة (Database::
 * connection() الخام -> DB facade، Report::find/create -> Eloquent).
 * مطابقة سطر بسطر للمنطق القديم.
 */
class ReportRepository
{
    public function create(array $data): Report
    {
        return Report::create($data);
    }

    /** كل تقرير اتعمل، الأحدث الأول، مع اسم الأدمن المولّد. @return array<int,array<string,mixed>> */
    public function allWithGenerator(int $limit = 50): array
    {
        return DB::table('reports as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.generated_by')
            ->select('r.*', 'u.full_name as generated_by_name')
            ->orderByDesc('r.created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * تاريخ تقارير مفلتر + مُرقّم صفحات لشاشة Admin > Reports.
     * @param array{q?:string,report_type?:string,format?:string,status?:string,date_from?:string,date_to?:string} $filters
     * @return array{rows: array<int,array<string,mixed>>, total: int}
     */
    public function searchAdmin(array $filters, int $page, int $perPage): array
    {
        $query = DB::table('reports as r')->leftJoin('users as u', 'u.id', '=', 'r.generated_by');

        if (!empty($filters['q'])) {
            $q = $filters['q'];
            $query->where(function ($w) use ($q) {
                $w->where('r.report_type', 'like', "%{$q}%")->orWhere('u.full_name', 'like', "%{$q}%");
            });
        }
        if (!empty($filters['report_type'])) {
            $query->where('r.report_type', $filters['report_type']);
        }
        if (!empty($filters['format'])) {
            $query->where('r.format', $filters['format']);
        }
        if (!empty($filters['status'])) {
            $query->where('r.status', $filters['status']);
        }
        if (!empty($filters['date_from'])) {
            $query->where('r.created_at', '>=', $filters['date_from'] . ' 00:00:00');
        }
        if (!empty($filters['date_to'])) {
            $query->where('r.created_at', '<=', $filters['date_to'] . ' 23:59:59');
        }

        $total = (clone $query)->count();

        $offset = max(0, ($page - 1) * $perPage);
        $rows = $query
            ->select('r.*', 'u.full_name as generated_by_name')
            ->orderByDesc('r.created_at')
            ->offset($offset)
            ->limit($perPage)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        return ['rows' => $rows, 'total' => $total];
    }

    /** تقارير مستخدم واحد بس، الأحدث الأول — لبورتالات University/إلخ. @return array<int,array<string,mixed>> */
    public function forUser($userId, int $limit = 50): array
    {
        return DB::table('reports')
            ->where('generated_by', $userId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    public function findOwned($id, $userId): ?Report
    {
        $r = Report::find($id);
        if ($r && (string) $r->generated_by === (string) $userId) {
            return $r;
        }
        return null;
    }

    /** تاريخ تنفيذ حقيقي لجدولة واحدة (reports.schedule_id) — الأحدث الأول. @return array<int,array<string,mixed>> */
    public function forSchedule($scheduleId, int $limit = 10): array
    {
        return DB::table('reports')
            ->where('schedule_id', $scheduleId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /** الصفوف المملوكة من بين الـ ids المُمررة — لحل file_path قبل الحذف من الديسك، ولتحديد نطاق حذف جماعي لهذا المستخدم. @return array<int,array<string,mixed>> */
    public function forUserByIds($userId, array $ids): array
    {
        if (!$ids) {
            return [];
        }
        return DB::table('reports')
            ->where('generated_by', $userId)
            ->whereIn('id', $ids)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    public function delete($id, $userId): bool
    {
        $r = $this->findOwned($id, $userId);
        if (!$r) {
            return false;
        }
        return (bool) $r->delete();
    }

    /** بيحذف كل صف مملوك من بين الـ ids. بترجع عدد اللي فعلًا اتحذف. */
    public function deleteByIds(array $ids, $userId): int
    {
        $count = 0;
        foreach ($this->forUserByIds($userId, $ids) as $row) {
            if (Report::find($row['id'])?->delete()) {
                $count++;
            }
        }
        return $count;
    }

    /** بيحذف كل تقرير مملوك لهذا المستخدم. بترجع عدد اللي اتحذف. */
    public function deleteAllForUser($userId): int
    {
        $count = 0;
        foreach ($this->forUser($userId, 1000) as $row) {
            if (Report::find($row['id'])?->delete()) {
                $count++;
            }
        }
        return $count;
    }
}
