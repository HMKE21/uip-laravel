<?php

namespace App\Services;

use App\Models\ExportSchedule;
use App\Repositories\ExportScheduleRepository;
use Illuminate\Support\Facades\Log;

/**
 * منقولة من app/Services/ExportSchedulerService.php القديمة — بند 24
 * batch 2 (Data Analysis Portal — Export Center، enhancement spec
 * section 7). جدولة متكررة حقيقية لتصديرات Export Center، بنفس شكل
 * ReportSchedulerService تمامًا، بس بتشغّل DataExportService (كتالوج
 * أنواع Export Center الخاص بيه) بدل ReportService، وبتكتب في
 * `data_exports`/`export_schedules` (migration 078) بدل
 * `reports`/`report_schedules`.
 *
 * ⚠️ نفس الفجوة الموثّقة في ReportSchedulerService بالظبط: cron
 * entrypoint فعلي (Artisan command أو Task Scheduler entry بينادي
 * runDue()) لسه مش مسجّل — قرار "كل قد إيه" محتاج صاحب المشروع، مش
 * حاجة تتخمّن. الكلاس جاهز للاستخدام من أي entrypoint.
 */
class ExportSchedulerService
{
    public function __construct(
        private ExportScheduleRepository $schedules,
        private DataExportService $exportService
    ) {
    }

    /** جداول هذا المستخدم بس (صفحة Export Center). */
    public function forCreator($userId): array
    {
        return $this->schedules->forCreator($userId);
    }

    /**
     * @param int|null $customIntervalDays مطلوب (ومهم) بس لو $frequency === 'custom'.
     * @throws \InvalidArgumentException
     */
    public function create(
        string $exportType,
        string $frequency,
        string $recipientEmail,
        $createdBy,
        string $format = 'csv',
        ?int $customIntervalDays = null
    ): ExportSchedule {
        if (!in_array($exportType, DataExportService::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException('Unknown export type.');
        }
        if (!in_array($frequency, ['daily', 'weekly', 'monthly', 'custom'], true)) {
            throw new \InvalidArgumentException('Unknown frequency.');
        }
        if (!in_array($format, DataExportService::ALLOWED_FORMATS, true)) {
            throw new \InvalidArgumentException('Unknown attachment format.');
        }
        if ($frequency === 'custom' && (!$customIntervalDays || $customIntervalDays < 1 || $customIntervalDays > 365)) {
            throw new \InvalidArgumentException('Custom schedules need an interval between 1 and 365 days.');
        }
        $recipients = $this->validateRecipients($recipientEmail);

        return $this->schedules->create([
            'export_type'          => $exportType,
            'frequency'            => $frequency,
            'format'               => $format,
            'custom_interval_days' => $frequency === 'custom' ? $customIntervalDays : null,
            'recipient_email'      => $recipients,
            'is_active'            => 1,
            'next_run_at'          => $this->computeNextRun($frequency, $customIntervalDays),
            'created_by'           => $createdBy,
        ]);
    }

    /**
     * بتتحقق من قائمة (ممكن تكون مفصولة بفواصل) عناوين مستلمين وبترجعها
     * مجمّعة بـ ", " للتخزين. نفس عقد DataAnalysisExportsApiController
     * ::validateRecipients()/ReportSchedulerService — عنوان واحد على
     * الأقل، لحد 10، كل عنوان صحيح الصياغة.
     * @throws \InvalidArgumentException
     */
    private function validateRecipients(string $recipientEmail): string
    {
        $addresses = array_values(array_filter(array_map('trim', explode(',', $recipientEmail))));
        if (!$addresses) {
            throw new \InvalidArgumentException('Please provide at least one recipient email.');
        }
        if (count($addresses) > 10) {
            throw new \InvalidArgumentException('A schedule can have at most 10 recipients.');
        }
        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException("\"{$address}\" is not a valid email address.");
            }
        }
        return implode(', ', $addresses);
    }

    public function updateOwnedByCreator(
        $id,
        $userId,
        string $frequency,
        string $recipientEmail,
        string $format = 'csv',
        ?int $customIntervalDays = null
    ): bool {
        $schedule = $this->schedules->findOwnedByCreator($id, $userId);
        if (!$schedule) {
            return false;
        }
        if (!in_array($frequency, ['daily', 'weekly', 'monthly', 'custom'], true)) {
            return false;
        }
        if (!in_array($format, DataExportService::ALLOWED_FORMATS, true)) {
            return false;
        }
        if ($frequency === 'custom' && (!$customIntervalDays || $customIntervalDays < 1 || $customIntervalDays > 365)) {
            return false;
        }
        try {
            $recipients = $this->validateRecipients($recipientEmail);
        } catch (\InvalidArgumentException $e) {
            return false;
        }

        $this->schedules->update($schedule, [
            'frequency'            => $frequency,
            'format'               => $format,
            'custom_interval_days' => $frequency === 'custom' ? $customIntervalDays : null,
            'recipient_email'      => $recipients,
            'next_run_at'          => $this->computeNextRun($frequency, $customIntervalDays),
        ]);

        return true;
    }

    public function toggleOwnedByCreator($id, $userId): bool
    {
        return $this->schedules->findOwnedByCreator($id, $userId) !== null && $this->schedules->toggle($id) !== null;
    }

    public function deleteOwnedByCreator($id, $userId): bool
    {
        return $this->schedules->findOwnedByCreator($id, $userId) !== null && $this->schedules->delete($id);
    }

    public function runNowOwnedByCreator($id, $userId): bool
    {
        $schedule = $this->schedules->findOwnedByCreator($id, $userId);
        if (!$schedule) {
            return false;
        }
        $this->executeAndReschedule($schedule, $userId);
        return true;
    }

    /** بتشغّل جدولة واحدة فورًا بغض النظر عن next_run_at، وبعدين تعيد جدولتها بالظبط زي ما runDue() كانت هتعملها. */
    public function runNow($id, $userId): bool
    {
        $schedule = $this->schedules->find($id);
        if (!$schedule) {
            return false;
        }
        $this->executeAndReschedule($schedule, $userId);
        return true;
    }

    /**
     * Cron entrypoint: بتولّد + "تسلّم" كل جدولة وصل next_run_at بتاعها.
     * بترجع عدد اللي اشتغل، عشان الـ CLI script يطبعه.
     */
    public function runDue(): int
    {
        $due = $this->schedules->due();
        foreach ($due as $schedule) {
            $this->executeAndReschedule($schedule, $schedule->created_by);
        }
        return count($due);
    }

    private function executeAndReschedule(ExportSchedule $schedule, $userId): void
    {
        $recipients = array_values(array_filter(array_map('trim', explode(',', $schedule->recipient_email))));

        try {
            $export = $this->exportService->generate(
                $schedule->export_type,
                $schedule->format ?: 'csv',
                $userId,
                $recipients,
                null,
                (int) $schedule->id
            );
            $exportId = $export->id;
        } catch (\Throwable $e) {
            Log::error('Scheduled export generation failed', [
                'schedule_id' => $schedule->id,
                'export_type' => $schedule->export_type,
                'error'       => $e->getMessage(),
            ]);
            $exportId = null;
        }

        $this->schedules->markRun($schedule, $this->computeNextRun($schedule->frequency, $schedule->custom_interval_days ? (int) $schedule->custom_interval_days : null), $exportId);
    }

    private function computeNextRun(string $frequency, ?int $customIntervalDays = null): string
    {
        $interval = match ($frequency) {
            'daily'   => '+1 day',
            'monthly' => '+1 month',
            'custom'  => '+' . max(1, (int) $customIntervalDays) . ' days',
            default   => '+1 week',
        };
        return date('Y-m-d H:i:s', strtotime($interval));
    }
}