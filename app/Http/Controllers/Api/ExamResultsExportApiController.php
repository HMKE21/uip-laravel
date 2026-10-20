<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\AcademicStaffRepository;
use App\Repositories\ExamAttemptRepository;
use App\Services\AuditLogService;
use App\Services\ExamSystemService;
use App\Services\Export\CsvWriter;
use App\Services\Export\JsonWriter;
use App\Services\Export\PdfWriter;
use App\Services\Export\SpreadsheetWriter;
use Illuminate\Http\Request;

/**
 * سطح /api/v1/exam-system/exams/{id}/results/export — Round 8 (Phase 43).
 * نفس اتفاقية التنزيل بتاعة AdminAiCodeReviewExportController بالظبط
 * (ملف مؤقت -> Response بـ Content-Disposition -> unlink)، أربع صيغ
 * (csv/xlsx/pdf/json) لأن دي بالظبط الصيغ المدعومة في المشروع (راجع
 * DataExportService::ALLOWED_FORMATS + AdminAiCodeReviewExportController —
 * xlsx مش excel، ومفيش صيغة خامسة في أي مكان تاني بالكودبيس). البيانات
 * المُصدّرة (Student/Exam/Score/Percentage/Status/Submission Time) هي
 * بالظبط نفس صفوف ExamAttemptRepository::forExamWithStudentInfo() اللي
 * ExamGradingService::listAttemptsForExam() (Round 4) بيستخدمها للمدرس —
 * مفيش استعلام جديد، تصدير لنفس البيانات الحقيقية المعروضة أصلًا.
 *
 * الملكية: findOwnedExam() زي أي مسار exams/{id}/* تاني — مدرس تاني عمره
 * ما يصدّر نتائج امتحان مش بتاعه.
 */
class ExamResultsExportApiController extends Controller
{
    private const FORMATS = ['csv', 'xlsx', 'pdf', 'json'];

    public function __construct(
        protected ExamSystemService $examSystem,
        protected ExamAttemptRepository $attempts,
        protected AcademicStaffRepository $staffRepo,
        protected AuditLogService $auditLog
    ) {
    }

    public function exportExamResults(Request $request, $examId)
    {
        if ($request->attributes->get('uip_role') !== 'academic_staff') {
            return $this->apiError('Only academic staff accounts can export exam results.', null, 403);
        }
        $staff = $this->staffRepo->findByUserId((int) $request->attributes->get('uip_user_id'));
        if (!$staff) {
            return $this->apiError('No academic staff profile found for this account.', null, 404);
        }
        $exam = $this->examSystem->findOwnedExam($examId, $staff->id);
        if (!$exam) {
            return $this->apiError('Exam not found.', null, 404);
        }

        $format = $this->resolvedFormat($request);
        [$title, $meta, $sections] = $this->examResultsPayload($exam);

        return $this->respondExport($request, $format, 'exam_results_' . $exam->id, $title, $meta, $sections, [
            'action'       => 'academic_staff.exam_system.results_exported',
            'subject_type' => 'Exam',
            'subject_id'   => $exam->id,
        ]);
    }

    /**
     * عنوان + meta + sections لنتائج امتحان واحد — مشتركة بين التنزيل المباشر
     * ومركز التصدير (ExamExportsApiController) عشان الاتنين يطلعوا نفس الأرقام.
     *
     * @return array{0:string,1:array<string,string>,2:array}
     */
    protected function examResultsPayload($exam): array
    {
        $rows = $this->attempts->forExamWithStudentInfo($exam->id);

        $header = ['Student', 'Student Number', 'Email', 'Attempt #', 'Score', 'Max Marks', 'Percentage', 'Status', 'Submission Time'];
        $dataRows = array_map(fn ($r) => [
            $r->full_name,
            $r->student_number,
            $r->email,
            $r->attempt_number,
            $r->score !== null ? (float) $r->score : '-',
            (float) $exam->total_marks,
            $r->percentage !== null ? round((float) $r->percentage, 2) . '%' : '-',
            $r->status,
            $r->submitted_at ?? '-',
        ], $rows);

        $graded = array_filter($rows, fn ($r) => $r->status === 'graded');
        $percentages = array_values(array_filter(array_map(fn ($r) => $r->percentage !== null ? (float) $r->percentage : null, $graded), fn ($p) => $p !== null));

        $meta = [
            'Exam'           => (string) $exam->title,
            'Total Attempts' => (string) count($rows),
            'Graded'         => (string) count($graded),
            'Average Score'  => $percentages ? round(array_sum($percentages) / count($percentages), 2) . '%' : '-',
            'Generated At'   => date('Y-m-d H:i:s'),
        ];

        return ['Exam Results - ' . $exam->title, $meta, [['title' => 'Exam Results', 'header' => $header, 'rows' => $dataRows]]];
    }

    protected function resolvedFormat(Request $request): string
    {
        $format = strtolower((string) $request->input('format', 'csv'));
        return in_array($format, self::FORMATS, true) ? $format : 'csv';
    }

    /**
     * يكتب الملف على $path بالصيغة المطلوبة ويرجّع الـ Content-Type.
     * (اتفصلت من respondExport عشان مركز التصدير يحفظ الملف بدل ما يبعته مباشرة.)
     *
     * @throws \RuntimeException
     */
    protected function writeExportFile(string $format, string $path, string $title, array $meta, array $sections): string
    {
        // لغة الملف من lang في الريكوست — بتترجم العناوين وأسماء الأعمدة ومفاتيح الـ meta بس.
        [$title, $meta, $sections] = \App\Services\Export\ExportLocalizer::report($title, $meta, $sections);
        $ar = \App\Services\Export\ExportLocalizer::isArabic();

        switch ($format) {
            case 'xlsx':
                $header = \App\Services\Export\ExportLocalizer::headers(['Field', 'Value']);
                $rows = [];
                foreach ($meta as $k => $v) {
                    $rows[] = [$k, $v];
                }
                foreach ($sections as $section) {
                    $rows[] = ['', ''];
                    $rows[] = ['## ' . $section['title'], ''];
                    if ($section['header']) {
                        $rows[] = $section['header'];
                    }
                    foreach ($section['rows'] as $r) {
                        $rows[] = array_pad(array_values($r), count($header), '');
                    }
                }
                $maxCols = max(array_map('count', array_merge([$header], $rows)));
                $header = array_pad($header, $maxCols, '');
                SpreadsheetWriter::write($path, $header, $rows, $ar);
                return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

            case 'json':
                JsonWriter::write($path, ['title' => $title, 'meta' => $meta, 'sections' => $sections]);
                return 'application/json';

            case 'pdf':
                PdfWriter::writeReport($path, $title, $meta, $sections, null);
                return 'application/pdf';

            default: // csv
                $csvSections = array_map(fn ($s) => ['title' => $s['title'], 'header' => $s['header'], 'rows' => $s['rows']], $sections);
                array_unshift($csvSections, ['title' => $title, 'header' => array_keys($meta), 'rows' => [array_values($meta)]]);
                CsvWriter::writeSections($path, $csvSections);
                return 'text/csv';
        }
    }

    /**
     * @param array<string,string> $meta
     * @param array<int,array{title:string,header:string[],rows:array<int,array<int,mixed>>}> $sections
     * @param array{action:string,subject_type:string,subject_id:mixed} $audit
     */
    protected function respondExport(Request $request, string $format, string $filename, string $title, array $meta, array $sections, array $audit)
    {
        $tmpDir = storage_path('app/tmp');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }
        $path = $tmpDir . '/' . $filename . '_' . date('Ymd_His') . '.' . $format;

        try {
            $contentType = $this->writeExportFile($format, $path, $title, $meta, $sections);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        $this->auditLog->record(
            $request->attributes->get('uip_user_id'),
            $audit['action'],
            $audit['subject_type'],
            $audit['subject_id'],
            null,
            ['format' => $format],
            $request->ip()
        );

        $content = file_get_contents($path);
        @unlink($path);

        return response($content, 200, [
            'Content-Type'        => $contentType,
            'Content-Disposition' => 'attachment; filename="' . basename($path) . '"',
            'Content-Length'      => (string) strlen($content),
        ]);
    }
}
