<?php

namespace App\Repositories;

use App\Models\Kpi;
use App\Models\KpiHistory;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/KpiRepository.php القديمة — بند 24 batch 3
 * (KPI Management، enhancement spec section 10). تخزين حقيقي للـ KPIs
 * وتاريخ قيمها. كل رقم ظاهر في الواجهة (trend/growth rate/achievement%/
 * sparkline) متولّد هنا من صفوف kpi_history الحقيقية — مفيش حاجة مكتوبة
 * يدويًا أو محاكاة.
 */
class KpiRepository
{
    public const DIRECTIONS = ['higher_better', 'lower_better'];

    public const STATUSES = ['active', 'archived'];

    /** @return array<int,array<string,mixed>> كل الـ KPIs (لحالة معينة)، الأحدث أولًا */
    public function all(string $status = 'active'): array
    {
        $rows = DB::select(
            'SELECT k.*, u1.full_name AS created_by_name, u2.full_name AS assigned_to_name
               FROM kpis k
               LEFT JOIN users u1 ON u1.id = k.created_by
               LEFT JOIN users u2 ON u2.id = k.assigned_to
              WHERE k.status = ?
              ORDER BY k.created_at DESC',
            [$status]
        );
        return array_map(fn ($r) => (array) $r, $rows);
    }

    public function find(int $id): ?array
    {
        $row = DB::selectOne(
            'SELECT k.*, u1.full_name AS created_by_name, u2.full_name AS assigned_to_name
               FROM kpis k
               LEFT JOIN users u1 ON u1.id = k.created_by
               LEFT JOIN users u2 ON u2.id = k.assigned_to
              WHERE k.id = ? LIMIT 1',
            [$id]
        );
        return $row ? (array) $row : null;
    }

    public function create(array $attributes): int
    {
        $kpi = Kpi::create($attributes);
        $id = (int) $kpi->id;

        // بتزرع سجل التاريخ بالقيمة الابتدائية عشان الـ trend/الرسم
        // البياني يكون عنده نقطة بيانات حقيقية واحدة على الأقل من أول يوم.
        KpiHistory::create([
            'kpi_id'      => $id,
            'value'       => $attributes['current_value'],
            'note'        => 'Initial value',
            'recorded_by' => $attributes['created_by'] ?? null,
        ]);

        return $id;
    }

    public function update(int $id, array $attributes): bool
    {
        $kpi = Kpi::find($id);
        if (!$kpi) {
            return false;
        }
        $kpi->fill($attributes);
        return $kpi->save();
    }

    public function delete(int $id): bool
    {
        $kpi = Kpi::find($id);
        if (!$kpi) {
            return false;
        }
        return (bool) $kpi->delete(); // صفوف kpi_history بتتحذف تلقائي عبر FK cascade
    }

    /** تسجيل قيمة جديدة: بتضيف سطر في التاريخ وبتزامن kpis.current_value. */
    public function recordValue(int $kpiId, float $value, ?string $note, ?int $recordedBy): void
    {
        KpiHistory::create([
            'kpi_id'      => $kpiId,
            'value'       => $value,
            'note'        => $note,
            'recorded_by' => $recordedBy,
        ]);

        $kpi = Kpi::find($kpiId);
        if ($kpi) {
            $kpi->current_value = $value;
            $kpi->save();
        }
    }

    /** @return array<int,array{value:float,recorded_at:string,note:?string}> التاريخ كامل، الأقدم أولًا */
    public function history(int $kpiId, int $limit = 60): array
    {
        // recorded_at من نوع DATETIME (دقة الثانية) — قيمتين اتسجلوا في
        // نفس الثانية ممكن يتساووا، فـ id بتكسر التعادل وتضمن ترتيب
        // الإدراج الحقيقي.
        $rows = DB::select(
            'SELECT value, note, recorded_at
               FROM kpi_history
              WHERE kpi_id = ?
              ORDER BY recorded_at DESC, id DESC
              LIMIT ' . max(1, min(200, $limit)),
            [$kpiId]
        );
        return array_reverse(array_map(fn ($r) => (array) $r, $rows)); // الأقدم أولًا، للرسم من اليسار لليمين
    }

    /** @return array<int,array<string,mixed>> مستخدمين حقيقيين نشطين لقايمة "assign" */
    public function assignableUsers(int $limit = 200): array
    {
        $rows = DB::select(
            "SELECT id, full_name FROM users WHERE status = 'active' ORDER BY full_name ASC LIMIT " . max(1, min(500, $limit))
        );
        return array_map(fn ($r) => (array) $r, $rows);
    }
}
