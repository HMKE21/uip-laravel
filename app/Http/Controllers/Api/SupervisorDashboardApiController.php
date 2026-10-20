<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\SupervisorAssignmentRepository;
use App\Repositories\SupervisorRepository;
use Illuminate\Http\Request;

/**
 * جديدة (مش منقولة حرفيًا) — بورت JSON لـ
 * Supervisor\SupervisorDashboardController::index() القديمة (كانت web
 * view بس، مفيهاش REST API قديمة — زي SupervisorProjectsApiController
 * بالظبط في بند 11 مرحلة 2). SupervisorDashboard.jsx كان مبني بالفعل
 * وبينادي GET /api/v1/supervisor/dashboard من الأساس (شوف الـ docblock
 * جوه الملف) لكن الروت ده معمول مسجّل خالص — نفس فجوة "بورتال مبني
 * والراوت ناقص" بتاعة.
 *
 * نفس منطق DashboardsApiController::supervisorOverview() بالظبط (نفس
 * SupervisorRepository::findActiveByUserId()/SupervisorAssignmentRepository
 * scope queries) — الفرق شكلي بس في شكل الـ response عشان يطابق العقد
 * اللي الفرونت مبني عليه فعلًا: students_count/projects_count/
 * pending_count بدل مصفوفات كاملة (الفرونت هنا بيعرض عدّادات بس مش
 * جداول)، وpermissions_list بدل permissions.
 *
 * RBAC: uip.auth بتغطي الروت؛ النطاق كله بيتحل من uip_user_id — عمرها
 * ما تاخد id من العميل.
 */
class SupervisorDashboardApiController extends Controller
{
    public function __construct(
        private SupervisorRepository $supervisors,
        private SupervisorAssignmentRepository $assignments
    ) {
    }

    /** GET /api/v1/supervisor/dashboard */
    public function index(Request $request)
    {
        $supervisor = $this->supervisors->findActiveByUserId((int) $request->attributes->get('uip_user_id'));

        if (!$supervisor) {
            return $this->apiSuccess([
                'supervisor'       => null,
                'students_count'   => 0,
                'projects_count'   => 0,
                'pending_count'    => 0,
                'scopes'           => [],
                'permissions_list' => [],
            ], 'Supervisor dashboard retrieved successfully.');
        }

        $projects = $this->assignments->scopedProjects($supervisor->id, $supervisor->university_id);
        $pending = array_filter($projects, fn ($p) => (is_array($p) ? ($p['status'] ?? null) : ($p->status ?? null)) === 'submitted');

        return $this->apiSuccess([
            'supervisor'       => $supervisor->toArray(),
            'students_count'   => count($this->assignments->scopedStudents($supervisor->id, $supervisor->university_id)),
            'projects_count'   => count($projects),
            'pending_count'    => count($pending),
            'scopes'           => $this->assignments->forSupervisorWithLabels($supervisor->id),
            'permissions_list' => $supervisor->permissions !== '' && $supervisor->permissions !== null
                ? explode(',', $supervisor->permissions)
                : [],
        ], 'Supervisor dashboard retrieved successfully.');
    }
}
