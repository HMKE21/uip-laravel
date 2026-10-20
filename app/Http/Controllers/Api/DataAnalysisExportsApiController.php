<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\DataExportRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\UniversityRepository;
use App\Services\AnalyticsService;
use App\Services\AuditLogService;
use App\Services\DataAnalysisExamsService;
use App\Services\Export\ExportLocalizer;
use App\Services\Export\PdfWriter;
use App\Services\Export\SpreadsheetWriter;
use App\Services\ExportSchedulerService;
use App\Services\MailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/DataAnalysisExportsApiController.php
 * القديمة — بند 24 batch 2 (Data Analysis Portal — Export Center،
 * enhancement spec section 7). سطح /api/v1/data-analysis/exports/* واحد
 * لتصديرات التحليلات (Export Data + Batch Export + Email Export +
 * Scheduled Exports)، بنفس الـ writers لكل نوع (CSV/XLSX/PDF) ونفس
 * نمط "اكتب على الديسك ثم سجّل" المتزامن اللي DataExportService بتعمله
 * (مفيش queue worker في المشروع ده).
 *
 * الكنترولر ده محتفظ بـ generateOne() بتاعته الخاصة (منقولة كمان من
 * القديمة بالظبط) لمسار "Export Now" التزامني، بدل ما ينادي
 * DataExportService::generate() — نفس قرار DataExportService's docblock
 * بالظبط (السيرفيس إضافي لـ ExportSchedulerService، مش بديل لمنطق
 * الكنترولر). النتيجتين بيكتبوا نفس شكل الملفات وبيسجلوا في نفس الجدول.
 *
 * RBAC: uip.auth بيغطي الجروب؛ كل ميثود كمان بتتأكد data_analyst أو
 * admin — نفس قاعدة DataAnalysisSegmentsApiController. كل صف مربوط بـ
 * $request->attributes->get('uip_user_id') كمنشئ، عمرها مش قيمة جاية من
 * العميل.
 */
class DataAnalysisExportsApiController extends Controller
{
    private const ALLOWED_TYPES = [
        'platform_kpis', 'user_growth', 'category_distribution', 'university_leaderboard', 'full_platform_export',
        // نظام الامتحانات — الصفوف من DataAnalysisExamsService::exportRows (نفس أرقام Exam Analytics)
        'exam_overview', 'exam_scores_detail', 'student_exam_performance', 'exam_summary', 'doctor_exam_performance',
    ];

    private const ALLOWED_FORMATS = ['csv', 'xlsx', 'pdf'];

    private const TYPE_LABELS = [
        'platform_kpis'           => 'Platform KPIs',
        'user_growth'             => 'User Growth (12 months)',
        'category_distribution'   => 'Category Distribution',
        'university_leaderboard'  => 'University Leaderboard',
        'full_platform_export'    => 'Everything (Projects + Universities)',
        'exam_overview'            => 'Exam Overview (KPIs)',
        'exam_scores_detail'       => 'Exam Scores (every graded attempt)',
        'student_exam_performance' => 'Student Exam Performance',
        'exam_summary'             => 'Exams Summary (per exam)',
        'doctor_exam_performance'  => 'Doctors Exam Performance',
    ];

    public function __construct(
        private DataExportRepository $exports,
        private AnalyticsService $analytics,
        private ProjectRepository $projects,
        private UniversityRepository $universities,
        private MailService $mail,
        private ExportSchedulerService $scheduler,
        private DataAnalysisExamsService $exams,
        private AuditLogService $auditLog
    ) {
    }

    /** GET /api/v1/data-analysis/exports — تصديرات المحلل + جدولاته (مع تاريخ التشغيل). */
    public function index(Request $request): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can view exports.', null, 403);
        }

        $userId = $request->attributes->get('uip_user_id');

        $schedules = $this->scheduler->forCreator($userId);
        foreach ($schedules as &$schedule) {
            $schedule['history'] = $this->exports->forSchedule($schedule['id']);
        }
        unset($schedule);

        return $this->apiSuccess([
            'exports'   => $this->exports->forUser($userId, 50),
            'schedules' => $schedules,
        ], 'Exports retrieved successfully.', 200, ['allowed_types' => self::ALLOWED_TYPES, 'allowed_formats' => self::ALLOWED_FORMATS, 'exam_types' => DataAnalysisExamsService::EXPORT_TYPES]);
    }

    /**
     * POST /api/v1/data-analysis/exports — بتغطي تصدير واحد وBatch Export
     * سوا (export_type ممكن تيجي سطر واحد أو array). كل نوع بياخد صف
     * data_exports خاص بيه وملف خاص بيه، مربوطين بـ batch_id مشترك. لو
     * recipient_email موجودة، كل ملف اتولد بيتبعت لكل مستلم.
     */
    public function store(Request $request): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can create an export.', null, 403);
        }

        $userId = $request->attributes->get('uip_user_id');

        $rawTypes = $request->input('export_type', []);
        $types = is_array($rawTypes) ? $rawTypes : [$rawTypes];
        $types = array_values(array_unique(array_filter($types, fn ($t) => in_array($t, self::ALLOWED_TYPES, true))));

        $format = (string) $request->input('format', 'csv');
        if (!$types || !in_array($format, self::ALLOWED_FORMATS, true)) {
            return $this->apiError('Unknown export type or format.', null, 422);
        }

        $recipients = [];
        $recipientInput = trim((string) $request->input('recipient_email', ''));
        if ($recipientInput !== '') {
            try {
                $recipients = $this->validateRecipients($recipientInput);
            } catch (\InvalidArgumentException $e) {
                return $this->apiError($e->getMessage(), null, 422);
            }
        }

        $filters = $this->examFiltersFrom($request);

        $batchId = count($types) > 1 ? bin2hex(random_bytes(8)) : null;
        $successCount = 0;
        $failCount = 0;
        $lastError = null;

        foreach ($types as $type) {
            try {
                $this->generateOne($type, $format, $userId, $recipients, $batchId, $filters);
                $successCount++;
            } catch (\Throwable $e) {
                $failCount++;
                $lastError = $e->getMessage();
            }
        }

        if ($failCount === 0) {
            return $this->apiSuccess(['success_count' => $successCount, 'batch_id' => $batchId], $successCount > 1
                ? "{$successCount} exports ready for download."
                : 'Export ready for download.', 201);
        }

        if ($successCount > 0) {
            return $this->apiSuccess(
                ['success_count' => $successCount, 'fail_count' => $failCount, 'batch_id' => $batchId],
                "{$successCount} export(s) succeeded, {$failCount} failed. Check the history for details.",
                207
            );
        }

        return $this->apiError('Export failed.' . ($lastError ? ' ' . $lastError : ''), null, 422);
    }

    /** بتولّد نوع تصدير واحد، تسجله، وتبعت إيميل لكل مستلم. بترمي استثناء عند الفشل. */
    private function generateOne(string $type, string $format, $userId, array $recipients, ?string $batchId, array $filters = []): void
    {
        $emailTo = $recipients ? implode(', ', $recipients) : null;
        $isExam = in_array($type, DataAnalysisExamsService::EXPORT_TYPES, true);
        $typeFilters = $isExam ? $filters : []; // الفلاتر بتخص تصديرات الامتحانات بس

        $export = $this->exports->create([
            'user_id'     => $userId,
            'export_type' => $type,
            'format'      => $format,
            'filters'     => $typeFilters,
            'status'      => 'pending',
            'email_to'    => $emailTo,
            'batch_id'    => $batchId,
        ]);

        [$header, $rows] = $this->buildRows($type, $typeFilters);
        // لغة الملف جاية من اختيار المستخدم (lang في الريكوست) — بتترجم العنوان وأسماء الأعمدة فقط.
        $ar = ExportLocalizer::isArabic();
        $header = ExportLocalizer::headers($header);
        $title = ExportLocalizer::text(self::TYPE_LABELS[$type] ?? $type);

        $dir = public_path(config('upload.paths.reports', 'uploads/reports'));
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not prepare the exports folder.');
        }

        $filename = 'export_' . $type . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes($isExam ? 16 : 4)) . '.' . $format;
        $fullPath = $dir . '/' . $filename;

        switch ($format) {
            case 'xlsx':
                SpreadsheetWriter::write($fullPath, $header, $rows, $ar);
                break;

            case 'pdf':
                PdfWriter::writeTable($fullPath, $title, $header, $rows, $ar);
                break;

            default: // csv
                $handle = fopen($fullPath, 'w');
                if (!$handle) {
                    throw new \RuntimeException('Could not write the export file.');
                }
                if ($ar) {
                    fwrite($handle, "\xEF\xBB\xBF"); // BOM: Excel يفتح الحروف العربية صح
                }
                fputcsv($handle, $header);
                foreach ($rows as $row) {
                    fputcsv($handle, $row);
                }
                fclose($handle);
        }

        $relativePath = rtrim(config('upload.paths.reports', 'uploads/reports'), '/') . '/' . $filename;
        $this->exports->markCompleted($export->id, $relativePath);

        if ($isExam) {
            // بيانات طلاب (أسماء/إيميلات/درجات) — بنسجّل كل تصدير في الـ audit log.
            $this->auditLog->record($userId, 'data_analysis.exam_exported', 'data_export', $export->id, null, [
                'export_type' => $type,
                'format'      => $format,
                'filters'     => $typeFilters,
                'emailed_to'  => $emailTo,
            ]);
        }

        if ($recipients) {
            $label = self::TYPE_LABELS[$type] ?? $type;
            // رابط تحميل مطلق حقيقي من APP_URL — نفس اللي DataExportService
            // بتعملها بالظبط، لأن الرابط ده بيروح جوّه إيميل مفيهوش
            // request context.
            $downloadUrl = rtrim((string) config('app.url', ''), '/') . '/' . ltrim($relativePath, '/');
            foreach ($recipients as $address) {
                $this->mail->sendDataExportReady($address, $label, $format, $downloadUrl);
            }
        }
    }

    /**
     * بتتحقق من قائمة (ممكن تكون مفصولة بفواصل) عناوين مستلمين — عنوان
     * واحد على الأقل، لحد 10، كل عنوان صحيح الصياغة.
     * @return string[]
     * @throws \InvalidArgumentException
     */
    private function validateRecipients(string $recipientEmail): array
    {
        $addresses = array_values(array_filter(array_map('trim', explode(',', $recipientEmail))));
        if (!$addresses) {
            throw new \InvalidArgumentException('Please provide at least one recipient email.');
        }
        if (count($addresses) > 10) {
            throw new \InvalidArgumentException('An export can be emailed to at most 10 recipients.');
        }
        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException("\"{$address}\" is not a valid email address.");
            }
        }
        return $addresses;
    }


    /**
     * GET /api/v1/data-analysis/exports/{id}/preview — أول PREVIEW_ROWS صف
     * من بيانات التصدير (للمعاينة في الموقع قبل التحميل). بيتبني من نفس
     * buildRows بتاع التصدير نفسه، فمعتمدش على قراءة CSV/XLSX/PDF.
     */
    private const PREVIEW_ROWS = 200;

    public function preview(Request $request, $id): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can preview an export.', null, 403);
        }

        $userId = $request->attributes->get('uip_user_id');
        $export = $this->exports->findOwned($id, $userId);
        if (!$export) {
            return $this->apiError('Export not found.', null, 404);
        }

        $type = (string) $export->export_type;
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            return $this->apiError('Unknown export type.', null, 422);
        }

        $savedFilters = is_string($export->filters) ? (json_decode($export->filters, true) ?: []) : (is_array($export->filters) ? $export->filters : []);
        [$header, $rows] = $this->buildRows($type, $this->sanitizeExamFilters($savedFilters));
        $total = count($rows);

        return $this->apiSuccess([
            'id'        => $export->id,
            'type'      => $type,
            'label'     => ExportLocalizer::text(self::TYPE_LABELS[$type] ?? $type),
            'format'    => $export->format,
            'header'    => array_values(ExportLocalizer::headers($header)),
            'rows'      => array_map('array_values', array_slice($rows, 0, self::PREVIEW_ROWS)),
            'total'     => $total,
            'truncated' => $total > self::PREVIEW_ROWS,
        ], 'Preview retrieved successfully.');
    }

    /** DELETE /api/v1/data-analysis/exports/{id} */
    public function destroy(Request $request, $id): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can delete an export.', null, 403);
        }

        $userId = $request->attributes->get('uip_user_id');
        $export = $this->exports->findOwned($id, $userId);
        if (!$export) {
            return $this->apiError('Export not found.', null, 404);
        }

        $this->unlinkExportFile($export->file_path);
        $this->exports->delete($export->id, $userId);

        return $this->apiSuccess(null, 'Export deleted.');
    }

    /** POST /api/v1/data-analysis/exports/delete-selected */
    public function destroySelected(Request $request): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can delete exports.', null, 403);
        }

        $userId = $request->attributes->get('uip_user_id');
        $ids = (array) $request->input('ids', []);
        $ids = array_values(array_filter($ids, fn ($id) => is_numeric($id)));

        if (!$ids) {
            return $this->apiError('No exports selected.', null, 422);
        }

        $rows = $this->exports->forUserByIds($userId, $ids);
        foreach ($rows as $row) {
            $this->unlinkExportFile($row['file_path'] ?? null);
        }

        $count = $this->exports->deleteByIds($ids, $userId);

        return $this->apiSuccess(['deleted' => $count], $count . ' export(s) deleted.');
    }

    /** POST /api/v1/data-analysis/exports/delete-all */
    public function destroyAll(Request $request): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can delete exports.', null, 403);
        }

        $userId = $request->attributes->get('uip_user_id');
        $rows = $this->exports->forUser($userId, 1000);
        foreach ($rows as $row) {
            $this->unlinkExportFile($row['file_path'] ?? null);
        }

        $count = $this->exports->deleteAllForUser($userId);

        return $this->apiSuccess(['deleted' => $count], 'All exports deleted (' . $count . ').');
    }

    /** POST /api/v1/data-analysis/exports/schedules */
    public function scheduleCreate(Request $request): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can schedule an export.', null, 403);
        }

        $type = (string) $request->input('export_type', '');
        $frequency = (string) $request->input('frequency', '');
        $format = (string) $request->input('format', 'csv');
        $recipientEmail = (string) $request->input('recipient_email', '');
        $customIntervalDays = $request->input('custom_interval_days');
        $customIntervalDays = $customIntervalDays !== null && $customIntervalDays !== '' ? (int) $customIntervalDays : null;

        try {
            $this->scheduler->create($type, $frequency, $recipientEmail, $request->attributes->get('uip_user_id'), $format, $customIntervalDays);
            return $this->apiSuccess(null, 'Schedule created.', 201);
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }
    }

    /** PATCH /api/v1/data-analysis/exports/schedules/{id} */
    public function scheduleUpdate(Request $request, $id): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can update a schedule.', null, 403);
        }

        $frequency = (string) $request->input('frequency', '');
        $format = (string) $request->input('format', 'csv');
        $recipientEmail = (string) $request->input('recipient_email', '');
        $customIntervalDays = $request->input('custom_interval_days');
        $customIntervalDays = $customIntervalDays !== null && $customIntervalDays !== '' ? (int) $customIntervalDays : null;

        $ok = $this->scheduler->updateOwnedByCreator($id, $request->attributes->get('uip_user_id'), $frequency, $recipientEmail, $format, $customIntervalDays);

        return $ok
            ? $this->apiSuccess(null, 'Schedule updated.')
            : $this->apiError('Could not update that schedule.', null, 422);
    }

    /** POST /api/v1/data-analysis/exports/schedules/{id}/toggle */
    public function scheduleToggle(Request $request, $id): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can toggle a schedule.', null, 403);
        }

        $ok = $this->scheduler->toggleOwnedByCreator($id, $request->attributes->get('uip_user_id'));

        return $ok
            ? $this->apiSuccess(null, 'Schedule updated.')
            : $this->apiError('Could not update that schedule.', null, 422);
    }

    /** DELETE /api/v1/data-analysis/exports/schedules/{id} */
    public function scheduleDelete(Request $request, $id): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can delete a schedule.', null, 403);
        }

        $ok = $this->scheduler->deleteOwnedByCreator($id, $request->attributes->get('uip_user_id'));

        return $ok
            ? $this->apiSuccess(null, 'Schedule deleted.')
            : $this->apiError('Could not delete that schedule.', null, 422);
    }

    /** POST /api/v1/data-analysis/exports/schedules/{id}/run-now */
    public function scheduleRunNow(Request $request, $id): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal staff can run a schedule.', null, 403);
        }

        $ok = $this->scheduler->runNowOwnedByCreator($id, $request->attributes->get('uip_user_id'));

        return $ok
            ? $this->apiSuccess(null, 'Schedule run.')
            : $this->apiError('Could not run that schedule.', null, 422);
    }

    // -- helpers --------------------------------------------------------------

    /** فلاتر تصديرات الامتحانات من body الطلب: filters = {university_id, faculty_id, course_id, exam_type, from, to}. */
    private function examFiltersFrom(Request $request): array
    {
        $raw = $request->input('filters', []);
        return $this->sanitizeExamFilters(is_array($raw) ? $raw : []);
    }

    /** نفس تنضيف DataAnalysisExamsApiController::filtersFrom — القيم بترجع null لو مش صالحة. */
    private function sanitizeExamFilters(array $in): array
    {
        $date = fn ($v) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : null;
        $examType = $in['exam_type'] ?? null;

        return [
            'university_id' => (int) ($in['university_id'] ?? 0) ?: null,
            'faculty_id'    => (int) ($in['faculty_id'] ?? 0) ?: null,
            'course_id'     => (int) ($in['course_id'] ?? 0) ?: null,
            'exam_type'     => (is_string($examType) && preg_match('/^[a-z_]{1,20}$/', $examType)) ? $examType : null,
            'from'          => $date($in['from'] ?? null),
            'to'            => $date($in['to'] ?? null),
        ];
    }

    private function isDataAnalyst(Request $request): bool
    {
        // 'admin' متضمنة: نفس قاعدة DataAnalysisSegmentsApiController.
        return in_array($request->attributes->get('uip_role'), ['data_analyst', 'admin'], true);
    }

    /** تنظيف ديسك best-effort — ملف مفقود/متمسوح قبل كده عمره ميوقفش حذف صف قاعدة البيانات. */
    private function unlinkExportFile(?string $relativePath): void
    {
        if (!$relativePath) {
            return;
        }
        $fullPath = public_path($relativePath);
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }

    /** @return array{0:string[],1:array<int,array<int,mixed>>} */
    private function buildRows(string $type, array $filters = []): array
    {
        if (in_array($type, DataAnalysisExamsService::EXPORT_TYPES, true)) {
            return $this->exams->exportRows($type, $filters);
        }

        switch ($type) {
            case 'platform_kpis':
                $overview = $this->analytics->platformOverview();
                $rows = [];
                foreach ($overview as $metric => $value) {
                    $rows[] = [$metric, $value];
                }
                return [['metric', 'value'], $rows];

            case 'user_growth':
                $series = $this->analytics->userGrowthSeries(12);
                $rows = array_map(fn ($p) => [$p['label']['en'] ?? $p['label'], $p['value']], $series);
                return [['month', 'new_users'], $rows];

            case 'category_distribution':
                $dist = $this->analytics->categoryDistribution(20);
                $rows = array_map(fn ($p) => [$p['label']['en'] ?? $p['label'], $p['value']], $dist);
                return [['category', 'projects'], $rows];

            case 'university_leaderboard':
                $board = $this->analytics->universityLeaderboard(50);
                $rows = array_map(fn ($u) => [$u['university']['en'] ?? $u['university'], $u['projects']], $board);
                return [['university', 'projects'], $rows];

            case 'full_platform_export':
                return $this->buildFullPlatformRows();

            default:
                return [[], []];
        }
    }

    /**
     * تصدير "الكل" المجمّع: كل مشروع وشركة وجامعة على المنصة في ملف
     * واحد. الجداول التلاتة شكلها مختلف، فالصفوف بتتشارك مجموعة أعمدة
     * موحدة (Type/Name/Category-Country/Owner-Stats/Status/Created At) بدل
     * ما تتلزق كجداول منفصلة مش قابلة للمحاذاة.
     * @return array{0:string[],1:array<int,array<int,mixed>>}
     */
    private function buildFullPlatformRows(): array
    {
        $rows = [];

        foreach ($this->projects->allWithOwners() as $p) {
            $rows[] = [
                'project',
                $p['title_en'] ?: $p['title_ar'],
                $p['category'] ?? '',
                $p['owner_name'] ?? '',
                $p['status'] ?? '',
                $p['created_at'] ?? '',
            ];
        }

        foreach ($this->universities->allWithStats() as $u) {
            $rows[] = [
                'university',
                $u['official_name_en'] ?: $u['official_name_ar'],
                $u['country'] ?? '',
                $u['students_count'] . ' students / ' . $u['projects_count'] . ' projects',
                $u['verification_status'] ?? '',
                $u['created_at'] ?? '',
            ];
        }

        return [['Type', 'Name', 'Category / Country', 'Owner / Stats', 'Status', 'Created At'], $rows];
    }
}
