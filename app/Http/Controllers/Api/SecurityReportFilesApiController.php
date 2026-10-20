<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SecurityReportFile;
use App\Repositories\SecurityReportFileRepository;
use App\Services\AuditLogService;
use App\Services\SecurityReportFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * منقولة من app/Controllers/Api/SecurityReportFilesApiController.php
 * القديمة — سطح /api/v1/security/report-files/* بالكامل، بند 25 batch
 * 5 (Report Management: Upload / View / Preview / Download / Replace /
 * Archive / Securely Delete + Version History, Categories, Tags).
 * مش مقيّد لصاحب الرفع — أي عضو في فريق الأمن المشترك (security_admin/
 * security_officer/admin) له نفس الصلاحيات، زي الكنترولر القديم بالظبط.
 * مفيش تعليقات/تعاون هنا — القديمة معندهاش endpoints تعليقات، فمفيش
 * حاجة مختلقة (بعكس DataAnalysisReportFilesApiController اللي فيها
 * collaboration فعلي).
 *
 * RBAC: security_admin أو security_officer أو admin.
 *
 * فرق شكلي فقط عن القديمة: Session::userId()/userRole() -> $request
 * ->attributes->get('uip_user_id')/'uip_role'؛ base_path('public/'...) ->
 * public_path(...).
 */
class SecurityReportFilesApiController extends Controller
{
    public function __construct(
        private SecurityReportFileRepository $files,
        private SecurityReportFileService $service,
        private AuditLogService $auditLog
    ) {
    }

    /** GET /api/v1/security/report-files */
    public function index(Request $request): JsonResponse
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal accounts can view report files.', null, 403);
        }

        $filters = [
            'q'           => trim((string) $request->input('q', '')),
            'category_id' => (int) $request->input('category_id', 0) ?: null,
            'tag_id'      => (int) $request->input('tag_id', 0) ?: null,
            'extension'   => $request->input('extension') ?: null,
            'view'        => in_array($request->input('view', 'active'), ['active', 'archived'], true)
                              ? $request->input('view', 'active') : 'active',
            'sort'        => $request->input('sort', 'newest'),
            'page'        => (int) $request->input('page', 1),
            'per_page'    => (int) $request->input('per_page', 20),
        ];

        $result = $this->files->paginate($filters);

        return $this->apiSuccess($result['rows'], 'Report files retrieved successfully.', 200, [
            'page'       => $result['page'],
            'perPage'    => $result['per_page'],
            'total'      => $result['total'],
            'totalPages' => $result['per_page'] > 0 ? (int) ceil($result['total'] / $result['per_page']) : 0,
            'categories' => $this->files->categories(),
            'tags'       => $this->files->tags(),
        ]);
    }

    /** POST /api/v1/security/report-files — multipart/form-data: file, title, description, category_id, tags (comma-separated) */
    public function store(Request $request): JsonResponse
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal accounts can upload report files.', null, 403);
        }

        try {
            $reportFile = $this->service->upload(
                (int) $request->attributes->get('uip_user_id'),
                $request->file('file'),
                [
                    'title'       => $request->input('title'),
                    'description' => $request->input('description'),
                    'category_id' => $request->input('category_id') ?: null,
                    'tags'        => array_filter(array_map('trim', explode(',', (string) $request->input('tags', '')))),
                ],
                $request->ip()
            );
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess($this->files->findDetailed((int) $reportFile->id), 'Report file uploaded successfully.', 201);
    }

    /** GET /api/v1/security/report-files/{id} */
    public function show(Request $request, $id): JsonResponse
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal accounts can view this report file.', null, 403);
        }

        $id = (int) $id;
        $row = $this->files->findDetailed($id);
        if (!$row) {
            return $this->apiError('Report file not found.', null, 404);
        }

        $reportFile = SecurityReportFile::find($id);
        if ($reportFile) {
            $this->service->recordView($reportFile);
        }

        return $this->apiSuccess([
            'file'        => $row,
            'versions'    => $this->files->versionsForFile($id),
            'can_preview' => $this->service->previewable((string) $row['file_extension']),
        ], 'Report file retrieved successfully.');
    }

    /** GET /api/v1/security/report-files/{id}/download — بيبث الملف (attachment) */
    public function download(Request $request, $id)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal accounts can download report files.', null, 403);
        }

        $id = (int) $id;
        $row = $this->files->findDetailed($id);
        if (!$row || empty($row['storage_path'])) {
            return $this->apiError('File not found.', null, 404);
        }

        $fullPath = public_path(ltrim((string) $row['storage_path'], '/'));
        if (!is_file($fullPath)) {
            return $this->apiError('File not found on disk.', null, 404);
        }

        $reportFile = SecurityReportFile::find($id);
        if ($reportFile) {
            $this->service->recordDownload($reportFile);
        }
        $this->auditLog->record($request->attributes->get('uip_user_id'), 'security.report_file.download', 'security_report_file', $id, null, [
            'filename' => $row['original_filename'],
        ], $request->ip());

        return new Response(file_get_contents($fullPath), 200, [
            'Content-Type'        => $row['mime_type'] ?: 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . basename((string) $row['original_filename']) . '"',
            'Content-Length'      => (string) filesize($fullPath),
        ]);
    }

    /** GET /api/v1/security/report-files/{id}/preview — بيبث الملف inline، بس للامتدادات القابلة للمعاينة (حاليًا PDF) */
    public function preview(Request $request, $id)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal accounts can preview report files.', null, 403);
        }

        $id = (int) $id;
        $row = $this->files->findDetailed($id);
        if (!$row || empty($row['storage_path']) || !$this->service->previewable((string) $row['file_extension'])) {
            return $this->apiError('Preview not available for this file.', null, 404);
        }

        $fullPath = public_path(ltrim((string) $row['storage_path'], '/'));
        if (!is_file($fullPath)) {
            return $this->apiError('File not found on disk.', null, 404);
        }

        return new Response(file_get_contents($fullPath), 200, [
            'Content-Type'        => $row['mime_type'] ?: 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . basename((string) $row['original_filename']) . '"',
            'Content-Length'      => (string) filesize($fullPath),
        ]);
    }

    /** POST /api/v1/security/report-files/{id}/replace — multipart/form-data: file, notes */
    public function replace(Request $request, $id): JsonResponse
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal accounts can replace report files.', null, 403);
        }

        $id = (int) $id;
        $reportFile = SecurityReportFile::find($id);
        if (!$reportFile) {
            return $this->apiError('Report file not found.', null, 404);
        }

        $file = $request->file('file');
        if (!$file) {
            return $this->apiError('Please choose a file to upload.', null, 422);
        }

        try {
            $this->service->replace((int) $request->attributes->get('uip_user_id'), $reportFile, $file, $request->input('notes'), $request->ip());
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess($this->files->findDetailed($id), 'New version uploaded successfully.');
    }

    /** POST /api/v1/security/report-files/{id}/archive */
    public function archive(Request $request, $id): JsonResponse
    {
        return $this->mutate($request, $id, 'archive', 'Report archived successfully.');
    }

    /** POST /api/v1/security/report-files/{id}/unarchive */
    public function unarchive(Request $request, $id): JsonResponse
    {
        return $this->mutate($request, $id, 'unarchive', 'Report restored from archive successfully.');
    }

    /** DELETE /api/v1/security/report-files/{id} — بتشيل صف الـ DB وكل ملف فعلي (الحالي + تاريخ النسخ) نهائيًا */
    public function destroy(Request $request, $id): JsonResponse
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal accounts can delete report files.', null, 403);
        }

        $id = (int) $id;
        $reportFile = SecurityReportFile::find($id);
        if (!$reportFile) {
            return $this->apiError('Report file not found.', null, 404);
        }

        $versions = $this->files->versionsForFile($id);
        $this->service->delete((int) $request->attributes->get('uip_user_id'), $reportFile, $versions, $request->ip());

        return $this->apiSuccess(null, 'Report file deleted successfully.');
    }

    private function mutate(Request $request, $id, string $action, string $successMessage): JsonResponse
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal accounts can manage report files.', null, 403);
        }

        $id = (int) $id;
        $reportFile = SecurityReportFile::find($id);
        if (!$reportFile) {
            return $this->apiError('Report file not found.', null, 404);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        if ($action === 'archive') {
            $this->service->archive($userId, $reportFile, $request->ip());
        } else {
            $this->service->unarchive($userId, $reportFile, $request->ip());
        }

        return $this->apiSuccess($this->files->findDetailed($id), $successMessage);
    }

    private function isSecurityStaff(Request $request): bool
    {
        return in_array($request->attributes->get('uip_role'), ['security_admin', 'security_officer', 'admin'], true);
    }
}
