<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\UserSessionRepository;
use App\Services\AuditLogService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/SecuritySessionsApiController.php
 * القديمة — بند 25 batch 1 (Security Portal — Sessions). طبقة JSON
 * رفيعة فوق App\Repositories\UserSessionRepository، نفس الريبو اللي
 * الكنترولر القديم بيستخدمه — مفيش منطق أعمال جديد، مفيش استعلامات
 * جديدة.
 *
 * RBAC: security_admin أو security_officer أو admin.
 *
 * فرق شكلي فقط عن القديمة: Session::userRole()/userId() -> $request
 * ->attributes->get('uip_role')/'uip_user_id'.
 */
class SecuritySessionsApiController extends Controller
{
    public function __construct(
        private UserSessionRepository $sessions,
        private AuditLogService $auditLog
    ) {
    }

    /** GET /api/v1/security/sessions */
    public function index(Request $request)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal accounts can view active sessions.', null, 403);
        }

        return $this->apiSuccess(
            $this->sessions->activeWithUser(200),
            'Active sessions retrieved successfully.',
            200,
            [
                'active_count'   => $this->sessions->countActive(),
                'distinct_users' => $this->sessions->countDistinctActiveUsers(),
            ]
        );
    }

    /** POST /api/v1/security/sessions/{id}/revoke */
    public function revoke(Request $request, $id)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal accounts can revoke sessions.', null, 403);
        }

        $ok = $this->sessions->revoke($id, $request->attributes->get('uip_user_id'));

        if ($ok) {
            $this->auditLog->record(
                $request->attributes->get('uip_user_id'),
                'security.session.revoke',
                'user_session',
                $id,
                null,
                null,
                $request->ip()
            );
        }

        return $this->apiSuccess(null, $ok ? 'Session revoked successfully.' : 'Session could not be revoked.');
    }

    private function isSecurityStaff(Request $request): bool
    {
        return in_array($request->attributes->get('uip_role'), ['security_admin', 'security_officer', 'admin'], true);
    }
}
