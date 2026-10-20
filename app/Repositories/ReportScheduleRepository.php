<?php

namespace App\Repositories;

use App\Models\ReportSchedule;
use Illuminate\Support\Facades\DB;

/** منقولة من app/Repositories/ReportScheduleRepository.php القديمة. */
class ReportScheduleRepository
{
    /** كل الجداول مع اسم الأدمن اللي عملها، الأحدث الأول. */
    public function allWithCreator(): array
    {
        return DB::table('report_schedules as rs')
            ->leftJoin('users as u', 'u.id', '=', 'rs.created_by')
            ->select('rs.*', 'u.full_name as created_by_name')
            ->orderByDesc('rs.created_at')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /** جداول هذا المستخدم بس — لبورتالات مُنطاقة عشان محدش يشوف جدولة حد تاني. */
    public function forCreator($userId): array
    {
        return DB::table('report_schedules')
            ->where('created_by', $userId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    public function findOwnedByCreator($id, $userId): ?ReportSchedule
    {
        $schedule = ReportSchedule::find($id);
        return ($schedule && (int) $schedule->created_by === (int) $userId) ? $schedule : null;
    }

    public function find($id): ?ReportSchedule
    {
        return ReportSchedule::find($id);
    }

    public function create(array $data): ReportSchedule
    {
        return ReportSchedule::create($data);
    }

    public function toggle($id): ?ReportSchedule
    {
        $schedule = ReportSchedule::find($id);
        if (!$schedule) {
            return null;
        }
        $schedule->fill(['is_active' => $schedule->is_active ? 0 : 1]);
        $schedule->save();
        return $schedule;
    }

    /** بتعدّل frequency/recipient/next_run_at على صف اتجاب وتحقق ملكيته بالفعل. */
    public function update(ReportSchedule $schedule, array $data): ReportSchedule
    {
        $schedule->fill($data);
        $schedule->save();
        return $schedule;
    }

    public function delete($id): bool
    {
        $schedule = ReportSchedule::find($id);
        return $schedule ? (bool) $schedule->delete() : false;
    }

    /** الجداول الشغّالة اللي next_run_at بتاعها وصل — بيستهلكها الـ cron entrypoint. @return ReportSchedule[] */
    public function due(): array
    {
        return ReportSchedule::where('is_active', 1)
            ->where('next_run_at', '<=', now())
            ->orderBy('next_run_at')
            ->get()
            ->all();
    }

    public function markRun(ReportSchedule $schedule, string $nextRunAt, ?int $reportId): void
    {
        $schedule->fill([
            'last_run_at'    => now(),
            'last_report_id' => $reportId,
            'next_run_at'    => $nextRunAt,
        ]);
        $schedule->save();
    }
}
