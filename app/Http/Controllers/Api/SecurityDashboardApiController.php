<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SecurityDashboardService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/SecurityDashboardApiController.php
 * القديمة — سطح /api/v1/security/dashboard واحد، بند 25 batch 1
 * (Security Portal — أول endpoint فيه، مدخل البورتال). بيعيد استخدام
 * SecurityDashboardService::overview()/recentIncidents()/topRisks()/
 * notificationsFeed()/recentEvents()/incidentsBySeverity() بالظبط —
 * مفيش منطق جديد هنا، بس طبقة JSON زي القديمة.
 *
 * RBAC: security_admin أو security_officer أو admin (الأدمن عنده وصول
 * كامل لكل بورتال — نفس قاعدة كل كنترولرز التانية).
 *
 * فرق شكلي فقط عن القديمة: Session::userRole() -> $request->attributes
 * ->get('uip_role').
 */
class SecurityDashboardApiController extends Controller
{
    public function __construct(private SecurityDashboardService $dashboard)
    {
    }

    /** GET /api/v1/security/dashboard */
    public function index(Request $request)
    {
        $role = $request->attributes->get('uip_role');

        if (!in_array($role, ['security_admin', 'security_officer', 'admin'], true)) {
            return $this->apiError('Only Security Portal accounts can view this dashboard.', null, 403);
        }

        return $this->apiSuccess([
            'overview'           => $this->dashboard->overview(),
            'recent_incidents'   => $this->dashboard->recentIncidents(6),
            'top_risks'          => $this->dashboard->topRisks(5),
            'notifications'      => $this->dashboard->notificationsFeed($role ?? 'security_officer', 8),
            'recent_events'      => $this->dashboard->recentEvents(8),
            'severity_breakdown' => $this->dashboard->incidentsBySeverity(),
            'activity'           => $this->dashboard->activity((int) $request->query('days', 7)),
        ], 'Dashboard data retrieved successfully.');
    }
}
