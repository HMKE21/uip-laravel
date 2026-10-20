<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\Paginates;
use App\Http\Controllers\Controller;
use App\Repositories\IncidentEvidenceRepository;
use App\Repositories\SecurityIncidentRepository;
use App\Repositories\UserRepository;
use App\Services\AuditLogService;
use App\Services\FileUploadService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/SecurityIncidentsApiController.php
 * القديمة — سطح /api/v1/security/incidents/* بالكامل، بند 25 batch 2
 * (Incidents). List/filter مع pagination، تفاصيل مع timeline، إنشاء،
 * تعيين، تغيير حالة، تعليقات timeline، رفع/تنزيل/حذف أدلة، وتصدير تقرير
 * الحادثة — بتعيد استخدام SecurityIncidentRepository/
 * IncidentEvidenceRepository/FileUploadService بالظبط زي القديمة، بما
 * فيها نمط الـ reference-code + timeline event (كل تعديل بيضيف حدث
 * timeline، زي الويب بالظبط).
 *
 * RBAC: security_admin أو security_officer أو admin.
 *
 * فرق شكلي فقط عن القديمة: Session::userId()/userRole() -> $request
 * ->attributes->get('uip_user_id')/'uip_role'؛ Core\Request::file() ->
 * $request->file(); base_path('public/'...) -> public_path(...).
 */
class SecurityIncidentsApiController extends Controller
{
    use Paginates;

    public function __construct(
        private SecurityIncidentRepository $incidents,
        private UserRepository $users,
        private IncidentEvidenceRepository $evidence,
        private FileUploadService $uploader,
        private AuditLogService $auditLog
    ) {
    }

    /**
     * GET /api/v1/security/incidents — `search`/`q` بيدوّر في
     * title/description؛ `page`/`per_page` مدعومين (Concerns\Paginates).
     */
    public function index(Request $request)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can view incidents.', null, 403);
        }

        $status = $request->input('status') ?: null;
        $severity = $request->input('severity') ?: null;

        $rows = $this->filterBySearch($request, $this->incidents->allWithAssignee(500, $status, $severity), ['title', 'description']);
        [$page, $perPage, $total, $items] = $this->paginateArray($request, $rows);

        return $this->apiSuccess(
            $items,
            'Incidents retrieved successfully.',
            200,
            array_merge($this->meta($page, $perPage, $total), ['filters' => ['status' => $status, 'severity' => $severity]])
        );
    }

    /** GET /api/v1/security/incidents/{id} — تفاصيل + timeline + أدلة. */
    public function show(Request $request, $id)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can view an incident.', null, 403);
        }

        $incident = $this->incidents->find($id);
        if (!$incident) {
            return $this->apiError('Incident not found.', null, 404);
        }

        return $this->apiSuccess([
            'incident'  => $incident,
            'timeline'  => $this->incidents->timeline($incident->id),
            'evidence'  => $this->evidence->forIncident($incident->id),
            'assignees' => array_merge(
                $this->users->allWithRoles(['role' => 'security_admin']),
                $this->users->allWithRoles(['role' => 'security_officer'])
            ),
        ], 'Incident retrieved successfully.');
    }

    /** POST /api/v1/security/incidents */
    public function store(Request $request)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can create an incident.', null, 403);
        }

        $data = $request->validate([
            'title'    => 'required',
            'category' => 'required',
            'severity' => 'required',
        ]);

        $userId = $request->attributes->get('uip_user_id');

        $incident = $this->incidents->create([
            'title'       => $data['title'],
            'description' => $request->input('description'),
            'category'    => $data['category'],
            'severity'    => $data['severity'],
            'source_ip'   => $request->input('source_ip') ?: null,
            'created_by'  => $userId,
        ]);

        $this->incidents->addEvent($incident->id, $userId, 'created', 'Incident reported.');

        return $this->apiSuccess($incident->toArray(), 'Incident created successfully.', 201);
    }

    /** PATCH /api/v1/security/incidents/{id}/status */
    public function updateStatus(Request $request, $id)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can change an incident\'s status.', null, 403);
        }

        $status = (string) $request->input('status', '');

        if (!in_array($status, ['open', 'investigating', 'contained', 'resolved', 'closed'], true)) {
            return $this->apiError('Invalid status.', ['status' => 'Must be one of open, investigating, contained, resolved, closed.'], 422);
        }

        $this->incidents->updateStatus($id, $status);
        $this->incidents->addEvent($id, $request->attributes->get('uip_user_id'), 'status_change', 'Status changed to ' . $status . '.');

        return $this->apiSuccess(null, 'Incident status updated.');
    }

    /** PATCH /api/v1/security/incidents/{id}/assign */
    public function assign(Request $request, $id)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can assign an incident.', null, 403);
        }

        $userId = $request->input('assigned_to') ?: null;

        $this->incidents->assign($id, $userId);
        $this->incidents->addEvent($id, $request->attributes->get('uip_user_id'), 'assignment', 'Reassigned.');

        return $this->apiSuccess(null, 'Incident assigned.');
    }

    /** POST /api/v1/security/incidents/{id}/comment — تعليق timeline. */
    public function comment(Request $request, $id)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can comment on an incident.', null, 403);
        }

        $note = trim((string) $request->input('note', ''));

        if ($note === '') {
            return $this->apiError('A comment note is required.', ['note' => 'Required.'], 422);
        }

        $this->incidents->addEvent($id, $request->attributes->get('uip_user_id'), 'comment', $note);

        return $this->apiSuccess(null, 'Comment added.', 201);
    }

    /** POST /api/v1/security/incidents/{id}/evidence — رفع ملف multipart. */
    public function uploadEvidence(Request $request, $id)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can upload evidence.', null, 403);
        }

        $incident = $this->incidents->find($id);
        if (!$incident) {
            return $this->apiError('Incident not found.', null, 404);
        }

        $userId = $request->attributes->get('uip_user_id');

        try {
            $stored = $this->uploader->store($request->file('file'), 'incident_evidence', 'incident-' . $id);

            $row = $this->evidence->create([
                'incident_id'       => $id,
                'uploaded_by'       => $userId,
                'original_filename' => $stored['original_name'],
                'stored_path'       => $stored['stored_path'],
                'mime_type'         => $stored['mime_type'],
                'file_size_bytes'   => $stored['size_bytes'],
                'note'              => trim((string) $request->input('note', '')) ?: null,
            ]);

            $this->incidents->addEvent($id, $userId, 'evidence_upload', 'Evidence attached: ' . $stored['original_name']);
            $this->auditLog->record($userId, 'incident.evidence.upload', 'security_incident', $id, null, [
                'filename' => $stored['original_name'], 'evidence_id' => $row->id,
            ], $request->ip());

            return $this->apiSuccess($row->toArray(), 'Evidence uploaded.', 201);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }
    }

    /** GET /api/v1/security/incidents/{id}/evidence/{evidenceId}/download */
    public function downloadEvidence(Request $request, $id, $evidenceId)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can download evidence.', null, 403);
        }

        $row = $this->evidence->find($evidenceId);

        if (!$row || (string) $row->incident_id !== (string) $id) {
            return $this->apiError('Evidence not found.', null, 404);
        }

        $fullPath = public_path(ltrim((string) $row->stored_path, '/'));
        if (!is_file($fullPath)) {
            return $this->apiError('File not found on disk.', null, 404);
        }

        $this->auditLog->record($request->attributes->get('uip_user_id'), 'incident.evidence.download', 'security_incident', $id, null, [
            'filename' => $row->original_filename,
        ], $request->ip());

        return response(file_get_contents($fullPath), 200, [
            'Content-Type'        => $row->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . basename((string) $row->original_filename) . '"',
            'Content-Length'      => (string) filesize($fullPath),
        ]);
    }

    /** DELETE /api/v1/security/incidents/{id}/evidence/{evidenceId} */
    public function deleteEvidence(Request $request, $id, $evidenceId)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can delete evidence.', null, 403);
        }

        $row = $this->evidence->find($evidenceId);

        if (!$row || (string) $row->incident_id !== (string) $id) {
            return $this->apiError('Evidence not found.', null, 404);
        }

        $userId = $request->attributes->get('uip_user_id');

        $this->uploader->delete((string) $row->stored_path);
        $this->evidence->delete($evidenceId);
        $this->incidents->addEvent($id, $userId, 'evidence_delete', 'Evidence removed: ' . $row->original_filename);
        $this->auditLog->record($userId, 'incident.evidence.delete', 'security_incident', $id, [
            'filename' => $row->original_filename,
        ], null, $request->ip());

        return $this->apiSuccess(null, 'Evidence deleted.');
    }

    /** GET /api/v1/security/incidents/{id}/export — ملف الحالة الكامل لحادثة واحدة، كـ CSV. */
    public function exportReport(Request $request, $id)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can export an incident report.', null, 403);
        }

        $incident = $this->incidents->find($id);
        if (!$incident) {
            return $this->apiError('Incident not found.', null, 404);
        }

        $timeline = $this->incidents->timeline($id);
        $evidenceRows = $this->evidence->forIncident($id);

        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");

        fputcsv($stream, ['Incident Report']);
        fputcsv($stream, ['Reference', $incident->reference_code]);
        fputcsv($stream, ['Title', $incident->title]);
        fputcsv($stream, ['Category', $incident->category]);
        fputcsv($stream, ['Severity', $incident->severity]);
        fputcsv($stream, ['Status', $incident->status]);
        fputcsv($stream, ['Source IP', $incident->source_ip ?: '—']);
        fputcsv($stream, ['Detected At', $incident->detected_at]);
        fputcsv($stream, ['Resolved At', $incident->resolved_at ?: '—']);
        fputcsv($stream, ['Description', $incident->description ?: '—']);
        fputcsv($stream, []);

        fputcsv($stream, ['Timeline']);
        fputcsv($stream, ['Time', 'Actor', 'Event', 'Note']);
        foreach ($timeline as $ev) {
            fputcsv($stream, [$ev['created_at'], $ev['actor_name'] ?? 'System', $ev['event_type'], $ev['note'] ?? '']);
        }
        fputcsv($stream, []);

        fputcsv($stream, ['Evidence']);
        fputcsv($stream, ['Filename', 'Uploaded By', 'Uploaded At', 'Size (bytes)', 'Note']);
        foreach ($evidenceRows as $ev) {
            fputcsv($stream, [$ev['original_filename'], $ev['uploader_name'] ?? '—', $ev['created_at'], $ev['file_size_bytes'], $ev['note'] ?? '']);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        $this->auditLog->record($request->attributes->get('uip_user_id'), 'incident.report.export', 'security_incident', $id, null, null, $request->ip());

        $filename = 'incident-report-' . $incident->reference_code . '-' . date('Y-m-d_His') . '.csv';
        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    // -- helpers --------------------------------------------------------------

    private function isSecurityStaff(Request $request): bool
    {
        return in_array($request->attributes->get('uip_role'), ['security_admin', 'security_officer', 'admin'], true);
    }
}
