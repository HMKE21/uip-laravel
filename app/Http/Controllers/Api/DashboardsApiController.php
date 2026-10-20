<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\PollableSnapshot;
use App\Repositories\AcademicStaffRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\ProjectAnalyticsRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\StaffAssignmentRepository;
use App\Repositories\StudentRepository;
use App\Repositories\SupervisorAssignmentRepository;
use App\Repositories\SupervisorRepository;
use App\Repositories\UniversityRepository;
use App\Services\ProjectApprovalService;
use Illuminate\Http\Request;

/**
 * منقولة جزئيًا من app/Controllers/Api/DashboardsApiController.php
 * القديمة (431 سطر، 12 فرع دور) — بس فرعي faculty/academic_staff هنا
 * (بند 10). الفرونت React بينادي GET /api/v1/dashboards/overview واحد
 * لكل البورتالات (لوحة faculty/university كلهم بيستخدموه
 * فعلًا)، لكن باقي الأفرع (admin/university/supervisor/
 * data_analyst/security) محتاجة خدمات لسه ماتعملتش
 * (DataAnalysisDashboardService, SecurityDashboardService, ...) اللي مش
 * شغل بند 10 — هتتضاف مع بندها.
 * أي role تاني هنا بيرجعله رسالة "لسه مش متاح" واضحة بدل خطأ غامض، عشان
 * الفرونت يقدر يتعامل معاها بدل ما يكسر بالكامل.
 *
 * facultyOverview()/academicStaffOverview() هنا نفس منطق القديمة حرفيًا
 * (ProjectRepository::forFacultyWithOwner()/departmentBreakdownForFaculty()/
 * .../ProjectApprovalService::queueForFaculty()، وAcademicStaffRepository::
 * findByUserId() + StaffAssignmentRepository::historyForStaff() على
 * الترتيب) — القديمة كانت بتنادي نفس الـ repositories/services دي بالظبط
 * من جوه *DashboardController::index() القديمة (web)، هنا JSON بس.
 *
 * فرق شكلي فقط عن القديمة: Session::userRole()/userId() -> uip_role/
 * uip_user_id من الـ request attributes (نفس نمط كل بند سابق).
 *
 * RBAC: uip.auth بتغطي الروت (routes/api.php)؛ كل فرع بيحل نطاقه بنفسه من
 * uip_user_id — عمرها ما تاخد id من العميل.
 */
class DashboardsApiController extends Controller
{
    use PollableSnapshot;

    private const MONTH_LABELS = [
        1 => ['en' => 'Jan', 'ar' => 'يناير'], 2 => ['en' => 'Feb', 'ar' => 'فبراير'],
        3 => ['en' => 'Mar', 'ar' => 'مارس'], 4 => ['en' => 'Apr', 'ar' => 'أبريل'],
        5 => ['en' => 'May', 'ar' => 'مايو'], 6 => ['en' => 'Jun', 'ar' => 'يونيو'],
        7 => ['en' => 'Jul', 'ar' => 'يوليو'], 8 => ['en' => 'Aug', 'ar' => 'أغسطس'],
        9 => ['en' => 'Sep', 'ar' => 'سبتمبر'], 10 => ['en' => 'Oct', 'ar' => 'أكتوبر'],
        11 => ['en' => 'Nov', 'ar' => 'نوفمبر'], 12 => ['en' => 'Dec', 'ar' => 'ديسمبر'],
    ];

    public function __construct(
        private UniversityRepository $universities,
        private FacultyRepository $faculties,
        private AcademicStaffRepository $academicStaff,
        private StaffAssignmentRepository $staffAssignments,
        private SupervisorRepository $supervisors,
        private SupervisorAssignmentRepository $supervisorAssignments,
        private StudentRepository $students,
        private ProjectRepository $projects,
        private ProjectAnalyticsRepository $analyticsEvents,
        private ProjectApprovalService $approvals
    ) {
    }

    /** GET /api/v1/dashboards/overview */
    public function overview(Request $request)
    {
        $role = (string) $request->attributes->get('uip_role');

        return match ($role) {
            'faculty'        => $this->facultyOverview($request),
            'academic_staff' => $this->academicStaffOverview($request),
            'university'     => $this->universityOverview($request),
            'supervisor'     => $this->supervisorOverview($request),
            'student' => $this->apiError(
                'Students should use GET /api/v1/students/dashboard-stats for their dashboard.',
                null,
                403
            ),
            default => $this->apiError(
                'This dashboard is not available yet for this account type — check back soon.',
                null,
                501
            ),
        };
    }

    /**
     * GET /api/v1/dashboards/live?since_hash=<hash> — نفس overview() فوق
     * بس polling بـ hash diffing (spec section 12)، مطابق منطق القديمة.
     */
    public function live(Request $request)
    {
        return $this->pollSnapshot($request, fn () => $this->overview($request));
    }

    // -- Faculty (FacultyDashboardController القديمة) --------------------------

    private function facultyOverview(Request $request)
    {
        $faculty = $this->faculties->findByUserId((int) $request->attributes->get('uip_user_id'));
        $facultyId = $faculty?->id;

        $projectRows = $facultyId ? $this->projects->forFacultyWithOwner($facultyId) : [];
        $published = count(array_filter($projectRows, fn ($r) => in_array($r['status'], ['approved', 'published'], true))); // معتمد (منشور أو لا)
        $rejected = count(array_filter($projectRows, fn ($r) => $r['status'] === 'rejected'));
        $submitted = count(array_filter($projectRows, fn ($r) => $r['status'] === 'submitted'));
        $decided = $published + $rejected;

        $queue = $facultyId ? $this->approvals->queueForFaculty($facultyId) : [];

        return $this->apiSuccess([
            'faculty'              => $faculty ? $faculty->toArray() : null,
            'total_students'       => $facultyId ? count($this->students->forFacultyWithStats($facultyId)) : 0,
            'under_review'         => $submitted,
            'approval_rate'        => $decided > 0 ? round(($published / $decided) * 100) : 0,
            'total_projects'       => count($projectRows),
            'pending_queue'        => array_slice(array_values(array_filter($queue, fn ($p) => $p['status'] === 'pending')), 0, 5),
            'department_breakdown' => $facultyId ? $this->projects->departmentBreakdownForFaculty($facultyId) : [],
            'category_breakdown'   => $facultyId ? $this->projects->categoryBreakdownForFaculty($facultyId) : [],
            'year_breakdown'       => $facultyId ? $this->projects->academicYearBreakdownForFaculty($facultyId) : [],
            'most_viewed'          => $facultyId ? $this->projects->mostViewedForFaculty($facultyId, 5) : [],
            'recent_projects'      => $facultyId ? $this->projects->recentForFaculty($facultyId, 5) : [],
        ], 'Faculty dashboard overview retrieved successfully.');
    }

    // -- Academic Staff (AcademicStaffDashboardController القديمة) -------------

    private function academicStaffOverview(Request $request)
    {
        $staff = $this->academicStaff->findByUserId((int) $request->attributes->get('uip_user_id'));
        if (!$staff) {
            return $this->apiSuccess(['staff' => null, 'leadership' => []], 'Academic staff dashboard overview retrieved successfully.');
        }

        return $this->apiSuccess([
            'staff'      => $staff->toArray(),
            'leadership' => $this->staffAssignments->historyForStaff($staff->id),
        ], 'Academic staff dashboard overview retrieved successfully.');
    }

    // -- University (UniversityDashboardController القديمة) --------------------

    private function universityOverview(Request $request)
    {
        $university = $this->universities->findByUserId((int) $request->attributes->get('uip_user_id'));
        $universityId = $university?->id;

        $counts = $universityId ? $this->projects->countByStatusForUniversity($universityId) : [];
        $published = ($counts['published'] ?? 0) + ($counts['approved'] ?? 0); // معتمد (منشور أو لا)
        $rejected = $counts['rejected'] ?? 0;
        $decided = $published + $rejected;

        $queue = $universityId ? $this->approvals->queueForUniversity($universityId) : [];

        $activity = $universityId ? $this->projects->monthlyActivityForUniversity($universityId) : [];
        $realActivity = array_map(fn ($point) => [
            'label' => self::MONTH_LABELS[(int) substr($point['month'], 5, 2)] ?? ['en' => $point['month'], 'ar' => $point['month']],
            'value' => $point['total'],
        ], $activity);

        return $this->apiSuccess([
            'university'            => $university?->toArray(),
            'total_students'        => $universityId ? count($this->students->forUniversity($universityId)) : 0,
            'under_review'          => $counts['submitted'] ?? 0,
            'approval_rate'         => $decided > 0 ? round(($published / $decided) * 100) : 0,
            'activity'              => $realActivity,
            'pending_queue'         => array_slice(array_values(array_filter($queue, fn ($p) => $p['status'] === 'pending')), 0, 3),
            'discipline_trends'     => $universityId ? $this->projects->facultyCategoryBreakdownForUniversity($universityId) : [],
            'faculty_breakdown'     => $universityId ? $this->projects->facultyBreakdownForUniversity($universityId) : [],
            'department_breakdown'  => $universityId ? $this->projects->departmentBreakdownForUniversity($universityId) : [],
            'year_breakdown'        => $universityId ? $this->projects->academicYearBreakdownForUniversity($universityId) : [],
            'most_viewed'           => $universityId ? $this->projects->mostViewedForUniversity($universityId, 5) : [],
            'most_interacted'       => $universityId ? $this->analyticsEvents->mostInteractedForUniversity($universityId, 5) : [],
            'recent_projects'       => $universityId ? $this->projects->recentForUniversity($universityId, 5) : [],
        ], 'University dashboard overview retrieved successfully.');
    }

    // -- Supervisor (SupervisorDashboardController القديمة) --------------------

    private function supervisorOverview(Request $request)
    {
        $supervisor = $this->supervisors->findActiveByUserId((int) $request->attributes->get('uip_user_id'));
        if (!$supervisor) {
            return $this->apiSuccess([
                'supervisor' => null, 'students' => [], 'projects' => [], 'scopes' => [],
            ], 'Supervisor dashboard overview retrieved successfully.');
        }

        $students = $this->supervisorAssignments->scopedStudents($supervisor->id, $supervisor->university_id);
        $projects = $this->supervisorAssignments->scopedProjects($supervisor->id, $supervisor->university_id);

        return $this->apiSuccess([
            'supervisor'  => $supervisor->toArray(),
            'students'    => $students,
            'projects'    => $projects,
            'pending'     => array_values(array_filter($projects, fn ($p) => (is_array($p) ? ($p['status'] ?? null) : ($p->status ?? null)) === 'submitted')),
            'scopes'      => $this->supervisorAssignments->forSupervisorWithLabels($supervisor->id),
            'permissions' => $supervisor->permissions !== '' && $supervisor->permissions !== null
                ? explode(',', $supervisor->permissions)
                : [],
        ], 'Supervisor dashboard overview retrieved successfully.');
    }
}
