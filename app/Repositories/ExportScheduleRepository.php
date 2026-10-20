<?php

namespace App\Repositories;

use App\Models\ExportSchedule;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/ExportScheduleRepository.php القديمة — بند
 * 24 batch 2 (Export Center — Scheduled Exports). Data access لـ
 * `export_schedules` (migration 078). منطاقة على المحلل المُنشئ بس —
 * Export Center مفيهوش صفحة overview شاملة على مستوى المنصة، على عكس
 * ReportScheduleRepository الخاص بالـ Scheduled Reports.
 */
class ExportScheduleRepository
{
    /** جداول هذا المستخدم بس، الأحدث أولًا. */
    public function forCreator($userId): array
    {
        return DB::table('export_schedules')
            ->where('created_by', $userId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    public function findOwnedByCreator($id, $userId): ?ExportSchedule
    {
        $schedule = ExportSchedule::find($id);
        return ($schedule && (int) $schedule->created_by === (int) $userId) ? $schedule : null;
    }

    public function find($id): ?ExportSchedule
    {
        return ExportSchedule::find($id);
    }

    public function create(array $data): ExportSchedule
    {
        return ExportSchedule::create($data);
    }

    public function toggle($id): ?ExportSchedule
    {
        $schedule = ExportSchedule::find($id);
        if (!$schedule) {
            return null;
        }
        $schedule->fill(['is_active' => $schedule->is_active ? 0 : 1]);
        $schedule->save();
        return $schedule;
    }

    public function update(ExportSchedule $schedule, array $data): ExportSchedule
    {
        $schedule->fill($data);
        $schedule->save();
        return $schedule;
    }

    public function delete($id): bool
    {
        $schedule = ExportSchedule::find($id);
        return $schedule ? (bool) $schedule->delete() : false;
    }

    /** الجداول الشغّالة اللي next_run_at بتاعها وصل — بيستهلكها الـ cron entrypoint. @return ExportSchedule[] */
    public function due(): array
    {
        return ExportSchedule::where('is_active', 1)
            ->where('next_run_at', '<=', now())
            ->orderBy('next_run_at')
            ->get()
            ->all();
    }

    public function markRun(ExportSchedule $schedule, string $nextRunAt, ?int $exportId): void
    {
        $schedule->fill([
            'last_run_at'    => now(),
            'last_export_id' => $exportId,
            'next_run_at'    => $nextRunAt,
        ]);
        $schedule->save();
    }
}