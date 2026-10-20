<?php

namespace App\Services;

use App\Models\ReportSchedule;
use App\Repositories\ReportScheduleRepository;
use Illuminate\Support\Facades\Log;

/**
 * منقولة من app/Services/ReportSchedulerService.php القديمة — جدولة
 * تقارير متكررة حقيقية فوق ReportService (بتولّد CSV/PDF/xlsx حقيقية
 * فعلًا). computeNextRun() حساب تواريخ عادي بس. التوصيل عن طريق
 * MailService — إرسال SMTP حقيقي لو config('mail') فيه بيانات، وإلا
 * MailService نفسها بتسجّل إنها ماقدرتش (Log::warning)، عمرها ما ترجع
 * "اتبعت" وهمي.
 *
 * ⚠️ الجزء اللي مش هنا: cron entrypoint فعلي (schedule:run-reports
 * Artisan command أو Task Scheduler entry بينادي runDue()) — الكلاس ده
 * جاهز للاستخدام من أي entrypoint، بس تسجيله في app/Console/Kernel.php
 * (أو routes/console.php لو Laravel 11+) محتاج قرار من صاحب المشروع
 * (كل قد إيه، وقت إيه) مش حاجة أقدر أخمنها.
 *
 * ⚠️ MailService::sendScheduledReport($address, $label, $frequency,
 * $downloadUrl) لازم تكون موجودة (أو تتضاف) في MailService بتاعتكم —
 * مش من ملفات بند الـ Reports ده، افترضتها موجودة زي القديمة بالظبط.
 * @package UIP
 */
class ReportSchedulerService
{
    public function __construct(
        private ReportScheduleRepository $schedules,
        private ReportService $reportService,
        private MailService $mail
    ) {
    }

    public function all(): array
    {
        return $this->schedules->allWithCreator();
    }

    /** جداول هذا المستخدم بس (صفحة Reports مثلًا). */
    public function forCreator($userId): array
    {
        return $this->schedules->forCreator($userId);
    }

    /**
     * @param array|null $allowedTypes بتحدد الأنواع المسموحة لهذا الكولر؛
     *        افتراضيًا ReportService::TYPES (سلوك الأدمن الأصلي).
     * @param string $format 'csv'|'pdf'|'xlsx' — الصيغة اللي كل تنفيذ مجدول هيطلعها.
     * @param int|null $customIntervalDays مطلوب (وليها معنى) بس لو $frequency === 'custom'.
     * @throws \InvalidArgumentException
     */
    public function create(
        string $reportType,
        string $frequency,
        string $recipientEmail,
        $createdBy,
        ?int $scopeId = null,
        ?array $allowedTypes = null,
        string $format = 'csv',
        ?int $customIntervalDays = null
    ): ReportSchedule {
        $allowedTypes = $allowedTypes ?? ReportService::TYPES;
        if (!in_array($reportType, $allowedTypes, true)) {
            throw new \InvalidArgumentException('Unknown report type.');
        }
        if (!in_array($frequency, ['daily', 'weekly', 'monthly', 'custom'], true)) {
            throw new \InvalidArgumentException('Unknown frequency.');
        }
        if (!in_array($format, ['csv', 'pdf', 'xlsx'], true)) {
            throw new \InvalidArgumentException('Unknown attachment format.');
        }
        if ($frequency === 'custom' && (!$customIntervalDays || $customIntervalDays < 1 || $customIntervalDays > 365)) {
            throw new \InvalidArgumentException('Custom schedules need an interval between 1 and 365 days.');
        }
        $recipients = $this->validateRecipients($recipientEmail);

        return $this->schedules->create([
            'report_type'          => $reportType,
            'frequency'            => $frequency,
            'format'               => $format,
            'custom_interval_days' => $frequency === 'custom' ? $customIntervalDays : null,
            'recipient_email'      => $recipients,
            'scope_id'             => $scopeId,
            'is_active'            => 1,
            'next_run_at'          => $this->computeNextRun($frequency, $customIntervalDays),
            'created_by'           => $createdBy,
        ]);
    }

    /**
     * بتتحقق من قايمة عناوين (ممكن تكون مفصولة بفاصلة) وترجعها متجمّعة
     * بـ ", " للتخزين. كل جدولة محتاجة عنوان واحد ع الأقل؛ لحد 10.
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

    public function toggle($id): bool
    {
        return $this->schedules->toggle($id) !== null;
    }

    /** بتعدّل frequency/recipient/format/custom_interval_days لجدولة موجودة. النوع نفسه مش قابل للتعديل هنا. */
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
        if (!in_array($format, ['csv', 'pdf', 'xlsx'], true)) {
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

    public function delete($id): bool
    {
        return $this->schedules->delete($id);
    }

    /** نسخ ملكية-محكومة للبورتالات المُنطاقة إلخ — بس صاحب الجدولة يقدر يتصرف فيها. */
    public function toggleOwnedByCreator($id, $userId): bool
    {
        return $this->schedules->findOwnedByCreator($id, $userId) !== null && $this->toggle($id);
    }

    public function deleteOwnedByCreator($id, $userId): bool
    {
        return $this->schedules->findOwnedByCreator($id, $userId) !== null && $this->delete($id);
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

    /** بتشغّل جدولة واحدة فورًا (زر "Run Now")، وبعدين بتعيد جدولتها زي runDue() بالظبط. */
    public function runNow($id, $userId): bool
    {
        $schedule = $this->schedules->find($id);
        if (!$schedule) {
            return false;
        }
        $this->executeAndReschedule($schedule, $userId);
        return true;
    }

    /** Cron entrypoint: بتولّد + "توصّل" كل جدولة next_run_at بتاعها وصل. بترجع عدد اللي اشتغل. */
    public function runDue(): int
    {
        $due = $this->schedules->due();
        foreach ($due as $schedule) {
            $this->executeAndReschedule($schedule, $schedule->created_by);
        }
        return count($due);
    }

    private function executeAndReschedule(ReportSchedule $schedule, $userId): void
    {
        try {
            $report = $this->reportService->generate(
                $schedule->report_type,
                $userId,
                $schedule->scope_id ? (int) $schedule->scope_id : null,
                $schedule->format ?: 'csv',
                (int) $schedule->id
            );
            $this->deliver($schedule->recipient_email, $schedule->report_type, $report->file_path, $schedule->frequency);
            $reportId = $report->id;
        } catch (\Throwable $e) {
            Log::error('Scheduled report generation failed', [
                'schedule_id' => $schedule->id,
                'report_type' => $schedule->report_type,
                'error'       => $e->getMessage(),
            ]);
            $reportId = null;
        }

        $this->schedules->markRun($schedule, $this->computeNextRun($schedule->frequency, $schedule->custom_interval_days ? (int) $schedule->custom_interval_days : null), $reportId);
    }

    /**
     * توصيل عن طريق MailService — عمرها ما ترمي استثناء، فشل التوصيل
     * ميوقفش markRun() من جدولة الجري الجاي. recipientEmail ممكن تكون
     * قايمة مفصولة بفاصلة — كل عنوان بياخد إرسال منفصل عشان عنوان غلط
     * ميأثرش على الباقي.
     */
    private function deliver(string $recipientEmail, string $reportType, ?string $filePath, string $frequency): void
    {
        $label = ucwords(str_replace('_', ' ', $reportType));
        $downloadUrl = $filePath ? url(ltrim($filePath, '/')) : null;

        $addresses = array_filter(array_map('trim', explode(',', $recipientEmail)));
        foreach ($addresses as $address) {
            // لغة الإيميل = لغة المستلم المفضّلة لو هو مستخدم مسجّل (الافتراضي عربي زي باقي الإيميلات).
            $pref = null;
            try {
                $pref = \Illuminate\Support\Facades\DB::table('users')->where('email', $address)->value('preferred_language');
            } catch (\Throwable $e) {
                $pref = null;
            }
            $locale = in_array($pref, ['ar', 'en'], true) ? $pref : 'ar';
            $this->mail->sendScheduledReport($address, $label, $frequency, $downloadUrl, $locale);
        }
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
