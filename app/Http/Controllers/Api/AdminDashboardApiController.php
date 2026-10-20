<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\University;
use App\Models\User;
use App\Repositories\AIAnalysisRepository;
use App\Repositories\ProjectRepository;
use App\Services\AuditLogService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/AdminDashboardApiController.php القديمة —
 * بند 25. سطح /api/v1/admin/dashboard JSON واحد، بيعيد استخدام نفس
 * repository/service calls بالظبط اللي Admin\AdminDashboardController
 * القديمة كانت بتعملها للـ Blade view (app/Views/admin/dashboard.php):
 * User::count()، University::where(verification_status=pending)،
 * ProjectRepository::countByStatus()، AuditLogService::recentForApi()،
 * AIAnalysisRepository::platformOverview(). مفيش حاجة مختلَقة هنا.
 *
 * حاجة واحدة اتسابت عمدًا برا: widget "Latest Security Alerts" بتاع
 * الـ Blade view القديم كان بيقرا من demo-data (docblockها بيقول كده) —
 * فمش موجودة هنا برضه؛ الفرونت بيربط لصفحة Security Logs الحقيقية بدل
 * أرقام مختلَقة.
 *
 * فرق شكلي فقط عن القديمة: Session::hasRole('admin')/userId() ->
 * $request->attributes->get('uip_role'|'uip_user_id').
 */
class AdminDashboardApiController extends Controller
{
    public function __construct(
        private ProjectRepository $projects,
        private AuditLogService $auditLog,
        private AIAnalysisRepository $aiAnalysis
    ) {
    }

    /** GET /api/v1/admin/dashboard */
    public function index(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'admin') {
            return $this->apiError('Only admin accounts can view the platform dashboard.', null, 403);
        }

        $pendingUniversities = University::where('verification_status', 'pending')
            ->orderByDesc('created_at')
            ->get();

        return $this->apiSuccess([
            'total_users'            => User::count(),
            'suspended_users'        => User::where('status', 'suspended')->count(),
            'verified_universities'  => University::where('verification_status', 'verified')->count(),
            'pending_universities'   => $pendingUniversities->values()->all(),
            'pending_approvals'      => $this->projects->countByStatus('submitted'),
            'recent_activity'        => $this->auditLog->recentForApi(4),
            'ai_overview'            => $this->aiAnalysis->platformOverview(),
            'published_projects'     => $this->projects->countByStatus('published'),
        ], 'Dashboard data retrieved successfully.');
    }
}
