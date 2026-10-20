<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ProjectApprovalService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/AdminApprovalsApiController.php القديمة —
 * بند 11 مرحلة 2. سطح /api/v1/admin/approvals JSON واحد، بيعيد استخدام
 * ProjectApprovalService::platformOversightPaginated()/
 * platformOversightCounts()/override() بالظبط زي ما
 * Admin\AdminProjectApprovalController القديمة كانت بتعمل للـ view —
 * إشراف قراءة-فقط على مستوى المنصة كلها عبر كل الجامعات، زائد الفعل
 * الوحيد المسموح للأدمن: override مسجّل ومحتاج سبب مكتوب. مسار
 * approve/reject/request-changes العادي فاضل عند الجامعة/الكلية زي ما هو،
 * مش بيتكرر هنا.
 *
 * فرق شكلي فقط عن القديمة (نفس نمط باقي البنود):
 *  - Session::hasRole('admin')/userId() -> $request->attributes->get('uip_role'|'uip_user_id').
 *  - $this->input()/$this->param() -> $request->input()/route parameter.
 *
 * RBAC: uip.auth بتغطي الجروب (routes/api.php)؛ role='admin' بتتفحص جوه
 * كل ميثود — إشراف على مستوى المنصة كلها، عمره ما بيتقيّد بجامعة/كلية
 * الكولر لأنه أصلاً مفيش له واحدة.
 */
class AdminApprovalsApiController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(private ProjectApprovalService $approvals)
    {
    }

    private function requireAdmin(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'admin') {
            return $this->apiError('Only admin accounts can view the approval queue.', null, 403);
        }
        return null;
    }

    /** GET /api/v1/admin/approvals */
    public function index(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $q = trim((string) $request->input('q', ''));
        $page = max(1, (int) $request->input('page', 1));

        $result = $this->approvals->platformOversightPaginated($q, $page, self::PER_PAGE);

        return $this->apiSuccess($result['rows'], 'Approval queue retrieved successfully.', 200, [
            'page' => $page, 'perPage' => self::PER_PAGE, 'total' => $result['total'],
            'counts' => $this->approvals->platformOversightCounts(),
        ]);
    }

    /** POST /api/v1/admin/approvals/{id}/override — {id} هو uuid المشروع. */
    public function override(Request $request, string $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $status = (string) $request->input('status');
        $comments = (string) $request->input('comments');

        $ok = $this->approvals->override($id, (int) $request->attributes->get('uip_user_id'), $status, $comments);

        return $ok
            ? $this->apiSuccess(null, 'Project status overridden.')
            : $this->apiError('Override failed — a documented reason is required, and the status must be one this override supports.', null, 422);
    }
}
