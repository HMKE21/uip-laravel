<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * منقولة من app/Controllers/Api/AdminAuditLogsApiController.php القديمة —
 * بند 25 batch 11 (Admin > Audit Logs، آخر باتش في البند). سطح
 * /api/v1/admin/audit-logs/* — قراءة فقط بالظبط زي Admin\
 * AdminAuditLogController القديمة (audit trail بيتكتب مرة واحدة عبر
 * AuditLogService::record() من كل تعديل تاني في المشروع، مبيتعدلش أو
 * يتمسح من هنا). البحث المفلتر عبر AuditLogService::searchPaginated()/
 * searchAll()؛ الافتراضي من غير فلاتر هو نفس "أحدث 100" اللي
 * AdminAuditLogController كانت بترجعله. export() بيدفّق نفس الـ CSV اللي
 * زرار "Export" في الويب بيطلعه، بنفس الأعمدة وBOM UTF-8 للنص العربي.
 *
 * RBAC: middleware uip.auth + uip.admin بيقفلوا المجموعة كلها (routes/api.php).
 */
class AdminAuditLogsApiController extends Controller
{
    private const PER_PAGE_DEFAULT = 25;
    private const PER_PAGE_MAX = 100;

    public function __construct(private AuditLogService $auditLog)
    {
    }

    private function requireAdmin(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'admin') {
            return $this->apiError('Only admin accounts can view the audit log.', null, 403);
        }
        return null;
    }

    /** GET /api/v1/admin/audit-logs — spec §7 pagination + the same filter set the web page exposes. */
    public function index(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $filters = $this->filtersFromRequest($request);
        $page = max(1, (int) $request->input('page', 1));
        $perPage = max(1, min(self::PER_PAGE_MAX, (int) $request->input('per_page', self::PER_PAGE_DEFAULT)));

        if ($filters) {
            $result = $this->auditLog->searchPaginated($filters, $page, $perPage);
            $rows = $result['rows'];
            $total = $result['total'];
        } else {
            $rows = $this->auditLog->recent(100);
            $total = count($rows);
            $rows = array_slice($rows, ($page - 1) * $perPage, $perPage);
        }

        return $this->apiSuccess($rows, 'Audit logs retrieved successfully.', 200, [
            'page' => $page, 'perPage' => $perPage, 'total' => $total, 'filters' => $filters,
        ]);
    }

    /** GET /api/v1/admin/audit-logs/export — streams every row matching the current filters as a downloadable CSV. */
    public function export(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $filters = $this->filtersFromRequest($request);
        $rows = $filters ? $this->auditLog->searchAll($filters, 5000) : $this->auditLog->recent(1000);

        $stream = fopen('php://temp', 'w+');
        // UTF-8 BOM عشان Excel يفتح النص العربي صح.
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['Actor', 'Email', 'Role', 'Action', 'Target', 'IP', 'Time']);

        foreach ($rows as $row) {
            $isSearchRow = array_key_exists('source', $row);
            fputcsv($stream, [
                $isSearchRow ? ($row['actor'] ?? '') : ($row['actor']['en'] ?? ''),
                $row['email'] ?? '',
                $row['role_label'] ?? '',
                $isSearchRow ? ($row['action'] ?? '') : ($row['action']['en'] ?? ''),
                $row['target'] ?? '',
                $row['ip'] ?? '',
                $row['time'] ?? '',
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        $filename = 'audit-log-' . date('Y-m-d_His') . '.csv';

        return new Response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    // -- helpers --------------------------------------------------------------

    private function filtersFromRequest(Request $request): array
    {
        return array_filter([
            'q'          => trim((string) $request->input('q', '')),
            'user'       => trim((string) $request->input('user', '')),
            'role'       => trim((string) $request->input('role', '')),
            'university' => trim((string) $request->input('university', '')),
            'ip'         => trim((string) $request->input('ip', '')),
            'module'     => trim((string) $request->input('module', '')),
            'action'     => trim((string) $request->input('action', '')),
            'date_from'  => trim((string) $request->input('date_from', '')),
            'date_to'    => trim((string) $request->input('date_to', '')),
        ], fn ($v) => $v !== '');
    }
}
