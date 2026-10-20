<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\PollableSnapshot;
use App\Models\User;
use App\Repositories\AIAnalysisRepository;
use App\Repositories\AnnouncementRepository;
use App\Repositories\DepartmentRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\ProjectFileRepository;
use App\Repositories\ProjectTeamMemberRepository;
use App\Repositories\StudentRepository;
use App\Repositories\SupervisorAssignmentRepository;
use App\Repositories\UniversityRepository;
use App\Services\FileUploadService;
use App\Services\MessagingService;
use App\Services\NotificationService;
use App\Services\ProjectPublishingService;
use App\Services\StudentJoinRequestService;
use App\Services\StudentManagementService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/StudentsApiController.php القديمة — نفس
 * الـ 8 endpoint بالظبط (index/store/dashboard-stats/dashboard-stats-live/
 * show/update/delete/resend)، نفس شكل الـ JSON، نفس رسائل الأخطاء، نفس
 * قواعد الملكية (انظر docblock كل ميثود تحت لتفاصيل كل واحد — منقولة
 * حرفيًا من القديمة).
 *
 * فرق شكلي فقط عن القديمة (زي FacultyApiController بالظبط):
 * Session::userId()/hasRole() -> $request->attributes->get('uip_user_id')/
 * 'uip_role'، Session::get('locale','ar') -> $request->input('locale','ar')،
 * $this->param('id') -> $id مُمرر صراحة كـ route parameter، $this->input()/
 * $this->request->file() -> $request->input()/$request->file() مُمررة
 * صراحة، و$this->validate() الداخلية (Core\Controller) استُبدلت بتحقق
 * يدوي بنفس شكل validation الموجود فعلًا في FacultyApiController::store()
 * (trim + apiError 422) بدل الاعتماد على ميثود مش موجودة في الأساس
 * الجديد.
 *
 * RBAC: uip.auth بتغطي الجروب كله (routes/api.php). كل action كمان بتعيد
 * اشتقاق سكوب الكولر نفسه من $request->attributes (مش من client input
 * أبدًا) وبترجع 403 لأي role مالوش صلاحية وصول لسجلات طلاب (شركة/مستثمر/
 * باحث...) و404 (مش صف فاضي بصمت) لطالب برة سكوب الكولر — نفس اتفاقية
 * findOwned() الموجودة بالفعل.
 */
class StudentsApiController extends Controller
{
    use PollableSnapshot;

    public function __construct(
        private StudentRepository $students,
        private UniversityRepository $universities,
        private FacultyRepository $faculties,
        private DepartmentRepository $departments,
        private SupervisorAssignmentRepository $supervisorAssignments,
        private ProjectPublishingService $projects,
        private ProjectTeamMemberRepository $teamMembers,
        private ProjectFileRepository $projectFiles,
        private NotificationService $notifications,
        private MessagingService $messaging,
        private AIAnalysisRepository $aiAnalysis,
        private AnnouncementRepository $announcements,
        private \App\Repositories\FeedRepository $feedRepo,
        private StudentManagementService $management,
        private FileUploadService $uploads,
        private StudentJoinRequestService $joinRequests
    ) {
    }

    /**
     * GET /api/v1/students/me
     * Student's own profile page (mirrors Student\StudentProfileController::
     * index()) — same shape as show(), plus the latest university join
     * request status (null once a university is linked, or if none was
     * ever submitted) so the page can render "pending review" / "rejected,
     * try another university" without a second round trip.
     */
    public function me(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'student') {
            return $this->apiError('Only student accounts have this profile.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $self = $this->students->findByUserId($userId);
        if (!$self) {
            return $this->apiError('Student not found.', null, 404);
        }

        $details = $this->students->withProfileDetails($self->id);
        if (!$details) {
            return $this->apiError('Student not found.', null, 404);
        }

        $locale = (string) $request->input('locale', 'ar');
        $joinRequest = $self->university_id ? null : $this->joinRequests->statusForStudent((int) $self->id, $locale);

        return $this->apiSuccess([
            'profile'      => $this->toProfileRow($details, $request),
            'join_request' => $joinRequest,
        ], 'Profile retrieved successfully.');
    }

    /**
     * PATCH /api/v1/students/me
     * Same field set/rules as selfUpdate() below (full_name/bio/avatar) —
     * thin wrapper so the frontend never needs to know its own student id
     * just to edit its own profile, same convention as faculty/me,
     * universities/me, etc.
     */
    public function updateMe(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'student') {
            return $this->apiError('Only student accounts have this profile.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $self = $this->students->findByUserId($userId);
        if (!$self) {
            return $this->apiError('Student not found.', null, 404);
        }

        return $this->selfUpdate($request, $self->id);
    }

    /**
     * POST /api/v1/students/me/avatar
     * Same convention as faculty/me/avatar — a
     * dedicated multipart endpoint so the JSON PATCH /me
     * above never needs to carry a file. selfUpdate() below still accepts
     * an avatar on PATCH /{id} too (university/faculty-initiated edits),
     * this is just the student's own upload path.
     */
    public function uploadAvatar(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'student') {
            return $this->apiError('Only student accounts can update this profile photo.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $user = User::find($userId);
        if (!$user) {
            return $this->apiError('User not found.', null, 404);
        }

        $avatar = $request->file('avatar');
        if (!$avatar) {
            return $this->apiError('avatar file is required.', null, 422);
        }

        try {
            $stored = $this->uploads->store($avatar, 'avatars', (string) $user->id);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        if ($user->avatar_path) {
            $this->uploads->delete($user->avatar_path);
        }
        $user->fill(['avatar_path' => $stored['stored_path']]);
        $user->save();

        return $this->apiSuccess(['avatar_path' => $stored['stored_path']], 'Profile photo updated successfully.');
    }

    /**
     * POST /api/v1/students/me/join-request
     * Student\StudentProfileController::linkUniversity() equivalent — lets
     * an unlinked student send a join request to a university (picked
     * "decide later" at registration, or wants to retry after a rejection).
     * Delegates all the validation/ownership rules to
     * StudentJoinRequestService::submitRequest() (see its docblock).
     */
    public function joinRequest(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'student') {
            return $this->apiError('Only student accounts can send a university join request.', null, 403);
        }

        $universityId = $this->nullableInt($request, 'university_id');
        if (!$universityId) {
            return $this->apiError('university_id is required.', null, 422);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $result = $this->joinRequests->submitRequest(
            $userId,
            $universityId,
            $this->nullableInt($request, 'faculty_id'),
            $this->nullableInt($request, 'department_id')
        );

        return $result['success']
            ? $this->apiSuccess(['request_id' => $result['request_id']], $result['message'])
            : $this->apiError($result['message'], null, 422);
    }

    /**
     * GET /api/v1/students
     * University/Faculty roster listing (spec section 7: page, per_page,
     * sort, order, search, filters). Not available to a student caller —
     * they don't have a roster, only their own record (see show()/
     * dashboardStats()).
     */
    public function index(Request $request)
    {
        $scope = $this->resolveScope($request);
        if (!$scope) {
            return $this->apiError('Only university or faculty accounts can list students.', null, 403);
        }

        $rows = $scope['role'] === 'faculty'
            ? $this->students->forFacultyWithStats($scope['facultyId'])
            : $this->students->forUniversityWithStats($scope['universityId']);

        foreach ($rows as &$row) {
            $row['effective_invitation_status'] = StudentManagementService::effectiveInvitationStatus($row);
        }
        unset($row);

        $search = trim((string) $request->input('search', (string) $request->input('q', '')));
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = array_values(array_filter($rows, function ($s) use ($needle) {
                return str_contains(mb_strtolower((string) $s['full_name']), $needle)
                    || str_contains(mb_strtolower((string) ($s['name_ar'] ?? '')), $needle)
                    || str_contains(mb_strtolower((string) ($s['name_en'] ?? '')), $needle)
                    || str_contains(mb_strtolower((string) $s['student_number']), $needle)
                    || str_contains(mb_strtolower((string) $s['email']), $needle);
            }));
        }

        // University-only filter (faculty is redundant once scoped to one faculty).
        $facultyFilter = $request->input('faculty');
        if ($scope['role'] === 'university' && $facultyFilter !== null && $facultyFilter !== '') {
            $rows = array_values(array_filter($rows, fn ($s) => (string) $s['faculty'] === (string) $facultyFilter));
        }

        $groupFilter = $request->input('group_id');
        if ($groupFilter !== null && $groupFilter !== '') {
            $rows = array_values(array_filter($rows, fn ($s) => (string) $s['group_id'] === (string) $groupFilter));
        }

        // Department filter — mainly for the Faculty portal roster (its
        // own faculty is already fixed by resolveScope(), so Department is
        // the meaningful narrowing filter there instead of Faculty).
        // Matched by department_id when numeric, falling back to the
        // display name for the "All Departments" <select> built from
        // FacultyApiController::myTree(), same convention as $facultyFilter
        // above.
        $departmentFilter = $request->input('department');
        if ($departmentFilter !== null && $departmentFilter !== '') {
            $rows = array_values(array_filter($rows, function ($s) use ($departmentFilter) {
                if (is_numeric($departmentFilter)) {
                    return (int) ($s['department_id'] ?? 0) === (int) $departmentFilter;
                }
                return (string) $s['department'] === (string) $departmentFilter;
            }));
        }

        $statusFilter = $request->input('status');
        if ($statusFilter !== null && $statusFilter !== '') {
            $rows = array_values(array_filter($rows, fn ($s) => $s['account_status'] === $statusFilter));
        }

        $sort = (string) $request->input('sort', 'full_name');
        $allowedSorts = ['full_name', 'email', 'student_number', 'academic_year', 'created_at', 'projects_count'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'full_name';
        }
        $order = strtolower((string) $request->input('order', 'asc')) === 'desc' ? -1 : 1;
        usort($rows, function ($a, $b) use ($sort, $order) {
            return $order * ($a[$sort] <=> $b[$sort]);
        });

        $page = max(1, (int) $request->input('page', 1));
        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $total = count($rows);
        $items = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return $this->apiSuccess(array_map([$this, 'toRow'], $items), 'Students retrieved successfully.', 200, [
            'page'       => $page,
            'perPage'    => $perPage,
            'total'      => $total,
            'totalPages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        ]);
    }

    /**
     * POST /api/v1/students — invite a brand-new student account
     * (University or Faculty). Reuses StudentManagementService::invite()
     * exactly as UniversityStudentManagementController::store() /
     * FacultyStudentController::store() do for the Blade "Add Student"
     * form — same validation, same temp-password email flow. Faculty's
     * faculty_id is pinned to their own faculty (never client input),
     * matching every other write action in this controller.
     */
    public function store(Request $request)
    {
        $locale = (string) $request->input('locale', 'ar');
        $scope = $this->resolveScope($request);
        if (!$scope) {
            return $this->apiError('Only university or faculty accounts can add students.', null, 403);
        }

        $names = \App\Support\BilingualName::fromRequest($request, $locale);
        $email = trim((string) $request->input('email', ''));
        $errors = $names['errors'];
        if ($email === '' || mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Required, valid email, max 190 chars.';
        } elseif ($why = \App\Support\DisposableEmail::problem($email, $locale)) {
            $errors['email'] = $why;
        }
        if ($errors) {
            return $this->apiError('Validation failed.', $errors, 422);
        }
        $fullName = $names['full_name'];

        $facultyId = $scope['role'] === 'faculty' ? $scope['facultyId'] : $this->nullableInt($request, 'faculty_id');

        $result = $this->management->invite(
            $scope['universityId'],
            (int) $request->attributes->get('uip_user_id'),
            $fullName,
            $email,
            $request->input('student_number'),
            $facultyId,
            $this->nullableInt($request, 'department_id'),
            $this->nullableInt($request, 'academic_year'),
            $this->nullableInt($request, 'group_id'),
            $locale,
            $this->nullableInt($request, 'current_semester'),
            $request->input('study_start_date'),
            $request->input('password') !== null ? (string) $request->input('password') : null,
            $request->has('send_email') ? $request->boolean('send_email') : true,
            $names['name_ar'],
            $names['name_en']
        );

        if (!$result['success']) {
            return $this->apiError($result['message'], null, 422);
        }

        return $this->apiSuccess([
            'id'         => $result['student_id'] ?? null,
            'password'   => $result['password'] ?? null,
            'email_sent' => $result['email_sent'] ?? false,
        ], $result['message'], 201);
    }

    /**
     * GET /api/v1/students/dashboard-stats
     * The logged-in student's own dashboard widgets — same source-of-truth
     * list as Student\StudentDashboardController::index(), returned as
     * JSON. Student role only; a student has exactly one dashboard: their
     * own.
     */
    public function dashboardStats(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'student') {
            return $this->apiError('Only student accounts have a student dashboard.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $locale = (string) $request->input('locale', 'ar');
        $user = User::find($userId);
        $student = $this->students->getOrCreate($userId);

        $university = $student->university_id ? $this->universities->find($student->university_id) : null;
        $faculty = $student->faculty_id ? $this->faculties->find($student->faculty_id) : null;
        $department = $student->department_id ? $this->departments->find($student->department_id) : null;
        $supervisors = $this->supervisorAssignments->supervisorsForStudent($userId);

        $projectRows = $this->projects->listForOwner($userId);
        $projectIds = array_map(fn ($p) => (int) $p->id, $projectRows);
        $projectCards = array_map(fn ($p) => $p->toCardArray(), $projectRows);

        $statusCounts = ['published' => 0, 'pending' => 0, 'draft' => 0];
        foreach ($projectCards as $p) {
            if (isset($statusCounts[$p['status']])) {
                $statusCounts[$p['status']]++;
            }
        }

        $readinessByProject = $this->aiAnalysis->readinessForProjects($projectIds);
        $readinessScores = [];
        $innovationScores = [];
        $latestAnalysis = null;
        foreach ($readinessByProject as $projectId => $score) {
            if ($score->overall_score !== null) {
                $readinessScores[] = (float) $score->overall_score;
            }
            if ($score->innovation_score !== null) {
                $innovationScores[] = (float) $score->innovation_score;
            }
            if ($score->computed_at !== null && ($latestAnalysis === null || $score->computed_at > $latestAnalysis['computed_at'])) {
                $projectRow = null;
                foreach ($projectRows as $p) {
                    if ((int) $p->id === (int) $projectId) {
                        $projectRow = $p;
                        break;
                    }
                }
                $latestAnalysis = [
                    'computed_at'   => $score->computed_at,
                    'project_title' => $projectRow ? ($projectRow->title_en ?: $projectRow->title_ar) : null,
                ];
            }
        }

        $teamMembersCount = 0;
        $documentsCount = 0;
        foreach ($projectIds as $pid) {
            $teamMembersCount += $this->teamMembers->countAcceptedForProject($pid);
            $documentsCount += count($this->projectFiles->forProjectLatest($pid));
        }

        $inbox = $this->messaging->inboxForUser($userId);
        $unreadMessages = count(array_filter($inbox, fn ($c) => $c['is_unread']));
        $unreadNotifications = $this->notifications->unreadCount($userId);
        $recentActivity = array_slice($this->notifications->forUser($userId), 0, 5);

        $upcomingDeadlines = [];
        $recentAnnouncements = [];
        $recentFeed = [];
        if ($student->university_id) {
            $upcomingDeadlines = $this->announcements->publishedForUniversity(
                $student->university_id,
                ['category' => 'deadline'],
                1,
                3
            )['items'];
            $recentAnnouncements = $this->announcements->publishedForUniversity(
                $student->university_id,
                [],
                1,
                3
            )['items'];
            // Published University Feed posts, same visibility scope as /api/v1/feed.
            $recentFeed = $this->feedRepo->feedForUniversity(
                (int) $student->university_id,
                $userId,
                ['type' => 'all'],
                1,
                3,
                [
                    'faculty_id'    => $student->faculty_id ? (int) $student->faculty_id : null,
                    'department_id' => $student->department_id ? (int) $student->department_id : null,
                    'academic_year' => $student->academic_year ? (int) $student->academic_year : null,
                ]
            )['items'];
        }

        return $this->apiSuccess([
            'user'                    => $user ? ['id' => $user->id, 'full_name' => $user->full_name, 'name_ar' => $user->name_ar, 'name_en' => $user->name_en, 'email' => $user->email] : null,
            'university_name'         => $university?->name('en'),
            'faculty_name'            => $faculty ? $faculty->name($locale) : null,
            'department_name'         => $department ? $department->name($locale) : null,
            'academic_year'           => $student->academic_year,
            'supervisors'             => $supervisors,
            'projects'                => $projectCards,
            'total_projects'          => count($projectCards),
            'published_count'         => $statusCounts['published'],
            'pending_count'           => $statusCounts['pending'],
            'draft_count'             => $statusCounts['draft'],
            'avg_readiness'           => $readinessScores ? round(array_sum($readinessScores) / count($readinessScores), 1) : null,
            'avg_innovation'          => $innovationScores ? round(array_sum($innovationScores) / count($innovationScores), 1) : null,
            'scored_count'            => count($readinessScores),
            'latest_analysis'         => $latestAnalysis,
            'team_members_count'      => $teamMembersCount,
            'documents_count'         => $documentsCount,
            'recent_activity'         => $recentActivity,
            'unread_messages'         => $unreadMessages,
            'unread_notifications'    => $unreadNotifications,
            'upcoming_deadlines'      => $upcomingDeadlines,
            'recent_announcements'    => $recentAnnouncements,
            'recent_feed'             => $recentFeed,
        ], 'Dashboard stats retrieved successfully.');
    }

    /**
     * GET /api/v1/students/dashboard-stats/live?since_hash=<hash> (spec
     * section 12 "Real-Time APIs" > Live Dashboard Updates). Hash-diffed
     * polling wrapper around dashboardStats() above, via
     * Concerns\PollableSnapshot — same convention as every other portal's
     * live dashboard endpoint. Kept on this controller rather than a
     * shared DashboardsApiController because the plain (non-live) student
     * dashboard already lives here too, same as the legacy split.
     */
    public function dashboardStatsLive(Request $request)
    {
        return $this->pollSnapshot($request, fn () => $this->dashboardStats($request));
    }

    /**
     * GET /api/v1/students/{id}
     * A student may only fetch their own record; university/faculty may
     * fetch any student inside their own scope (StudentRepository::
     * findOwned()).
     */
    public function show(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') === 'student') {
            $self = $this->students->findByUserId((int) $request->attributes->get('uip_user_id'));
            if (!$self || (string) $self->id !== (string) $id) {
                return $this->apiError('You can only view your own student record.', null, 403);
            }
            $details = $this->students->withProfileDetails($self->id);
            return $details
                ? $this->apiSuccess($this->toProfileRow($details, $request), 'Student retrieved successfully.')
                : $this->apiError('Student not found.', null, 404);
        }

        $scope = $this->resolveScope($request);
        if (!$scope) {
            return $this->apiError('Not authorized to view student records.', null, 403);
        }

        $owned = $this->students->findOwned($id, $scope['universityId'], $scope['facultyId']);
        if (!$owned) {
            return $this->apiError('Student not found.', null, 404);
        }

        $details = $this->students->withProfileDetails($owned->id);
        return $this->apiSuccess($this->toProfileRow($details, $request), 'Student retrieved successfully.');
    }

    /**
     * PATCH /api/v1/students/{id}
     * Field group present in the request decides which existing
     * StudentManagementService mutator(s) run (mirrors the separate
     * updateAffiliation/updateAcademicDetails/updateStudyDates/
     * updateProfile/activate/deactivate actions the web portal exposes as
     * distinct buttons) — a single PATCH can touch more than one group at
     * once. A student caller is routed to selfUpdate() instead, which only
     * ever touches their own full_name/bio/avatar, same as
     * Student\StudentProfileController.
     */
    public function update(Request $request, $id)
    {
        $locale = (string) $request->input('locale', 'ar');

        if ($request->attributes->get('uip_role') === 'student') {
            return $this->selfUpdate($request, $id);
        }

        $scope = $this->resolveScope($request);
        if (!$scope) {
            return $this->apiError('Not authorized to update student records.', null, 403);
        }

        if (!$this->students->findOwned($id, $scope['universityId'], $scope['facultyId'])) {
            return $this->apiError('Student not found.', null, 404);
        }

        $actingUserId = (int) $request->attributes->get('uip_user_id');
        $attempted = false;
        $failedMessages = [];

        if ($request->input('full_name') !== null || $request->input('name_ar') !== null || $request->input('name_en') !== null || $request->file('avatar')) {
            $attempted = true;
            $r = $this->management->updateProfile(
                $id,
                $scope['universityId'],
                $actingUserId,
                $request->input('full_name'),
                $request->file('avatar'),
                $locale,
                $scope['facultyId'],
                $request->input('name_ar') !== null ? (string) $request->input('name_ar') : null,
                $request->input('name_en') !== null ? (string) $request->input('name_en') : null
            );
            if (!$r['success']) {
                $failedMessages[] = $r['message'];
            }
        }

        if ($request->input('faculty_id') !== null || $request->input('department_id') !== null ) {
            $attempted = true;
            $r = $this->management->updateAffiliation(
                $id,
                $scope['universityId'],
                $actingUserId,
                $this->nullableInt($request, 'faculty_id'),
                $this->nullableInt($request, 'department_id'),
                $locale,
                $scope['facultyId']
            );
            if (!$r['success']) {
                $failedMessages[] = $r['message'];
            }
        }

        if ($request->input('student_number') !== null || $request->input('academic_year') !== null || $request->input('current_semester') !== null) {
            $attempted = true;
            $r = $this->management->updateAcademicDetails(
                $id,
                $scope['universityId'],
                $actingUserId,
                $request->input('student_number'),
                $this->nullableInt($request, 'academic_year'),
                $locale,
                $scope['facultyId'],
                $this->nullableInt($request, 'current_semester')
            );
            if (!$r['success']) {
                $failedMessages[] = $r['message'];
            }
        }

        if ($request->input('study_start_date') !== null || $request->input('expected_graduation_date') !== null) {
            $attempted = true;
            $r = $this->management->updateStudyDates(
                $id,
                $scope['universityId'],
                $actingUserId,
                $request->input('study_start_date'),
                $request->input('expected_graduation_date'),
                $locale,
                $scope['facultyId']
            );
            if (!$r['success']) {
                $failedMessages[] = $r['message'];
            }
        }

        if ($request->input('account_status') !== null) {
            $status = $request->input('account_status');
            if (!in_array($status, ['active', 'inactive'], true)) {
                return $this->apiError('account_status must be "active" or "inactive".', null, 422);
            }
            $attempted = true;
            $ok = $status === 'active'
                ? $this->management->activate($id, $scope['universityId'], $actingUserId, $scope['facultyId'])
                : $this->management->deactivate($id, $scope['universityId'], $actingUserId, $scope['facultyId']);
            if (!$ok) {
                $failedMessages[] = 'Student not found.';
            }
        }

        if (!$attempted) {
            return $this->apiError(
                'No updatable fields provided. Supported: full_name, avatar, faculty_id, department_id, '
                . 'student_number, academic_year, current_semester, study_start_date, expected_graduation_date, account_status.',
                null,
                422
            );
        }

        if ($failedMessages) {
            return $this->apiError(implode(' ', array_unique($failedMessages)), null, 422);
        }

        $details = $this->students->withProfileDetails($id);
        return $this->apiSuccess($this->toProfileRow($details, $request), 'Student updated successfully.');
    }

    /**
     * DELETE /api/v1/students/{id}
     * University/faculty only — StudentManagementService::delete()'s
     * docblock: a faculty-scoped delete removes the student from that
     * faculty only, they remain a student at the wider university.
     */
    public function delete(Request $request, $id)
    {
        $scope = $this->resolveScope($request);
        if (!$scope) {
            return $this->apiError('Only university or faculty accounts can remove students.', null, 403);
        }

        $ok = $this->management->delete($id, $scope['universityId'], (int) $request->attributes->get('uip_user_id'), $scope['facultyId']);
        if (!$ok) {
            return $this->apiError('Student not found.', null, 404);
        }

        return $this->apiSuccess(null, 'Student removed successfully.');
    }

    /** PATCH /api/v1/students/{id}/password — university/faculty sets (or regenerates) a student's password. */
    public function setPassword(Request $request, $id)
    {
        $scope = $this->resolveScope($request);
        if (!$scope) {
            return $this->apiError('Only university or faculty accounts can change a student password.', null, 403);
        }

        $result = $this->management->setPassword(
            $id,
            $scope['universityId'],
            (int) $request->attributes->get('uip_user_id'),
            $request->input('password') !== null ? (string) $request->input('password') : null,
            (string) $request->input('locale', 'ar'),
            $scope['facultyId']
        );

        return $result['success']
            ? $this->apiSuccess(['password' => $result['password'] ?? null], $result['message'])
            : $this->apiError($result['message'], null, 422);
    }

    /**
     * POST /api/v1/students/{id}/resend
     * University/faculty only — re-sends the student's invitation email
     * via StudentManagementService::resendInvite(), same action as the
     * "Resend" button on students.php.
     */
    public function resend(Request $request, $id)
    {
        $locale = (string) $request->input('locale', 'ar');
        $scope = $this->resolveScope($request);
        if (!$scope) {
            return $this->apiError('Only university or faculty accounts can resend a student invite.', null, 403);
        }

        $result = $this->management->resendInvite(
            $id,
            $scope['universityId'],
            (int) $request->attributes->get('uip_user_id'),
            $locale,
            $scope['facultyId']
        );

        return $result['success']
            ? $this->apiSuccess(null, $result['message'])
            : $this->apiError($result['message'], null, 422);
    }

    /**
     * Student self-service edit — same field set as
     * Student\StudentProfileController::update()/uploadAvatar(): full_name,
     * bio, avatar. Faculty/department/status changes stay university- or
     * faculty-managed (see that controller's docblock for why).
     */
    private function selfUpdate(Request $request, $id)
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        $self = $this->students->findByUserId($userId);
        if (!$self || (string) $self->id !== (string) $id) {
            return $this->apiError('You can only update your own student record.', null, 403);
        }

        $user = User::find($userId);
        if (!$user) {
            return $this->apiError('Student not found.', null, 404);
        }

        $changed = false;

        if ($request->input('name_ar') !== null || $request->input('name_en') !== null) {
            $names = \App\Support\BilingualName::resolve(
                $request->input('name_ar', $user->name_ar),
                $request->input('name_en', $user->name_en),
                \App\Support\BilingualName::localeOf($request)
            );
            if (!$names['ok']) {
                return $this->apiError('Validation failed.', $names['errors'], 422);
            }
            $user->fill(\App\Support\BilingualName::columns($names));
            $user->save();
            $changed = true;
        } elseif ($request->input('full_name') !== null) {
            $fullName = trim((string) $request->input('full_name'));
            if ($fullName === '') {
                return $this->apiError('full_name cannot be empty.', null, 422);
            }
            $user->fill(['full_name' => $fullName]);
            $user->save();
            $changed = true;
        }

        $phone = \App\Support\ProfilePhone::fromRequest($request, \App\Support\BilingualName::localeOf($request));
        if (!$phone['ok']) {
            return $this->apiError('Validation failed.', ['phone' => $phone['error']], 422);
        }
        if ($phone['present']) {
            $user->fill(['phone' => $phone['value']]);
            $user->save();
            $changed = true;
        }

        if ($request->input('bio') !== null) {
            $self->fill(['bio' => $request->input('bio')]);
            $self->save();
            $changed = true;
        }

        $avatar = $request->file('avatar');
        if ($avatar) {
            try {
                $stored = $this->uploads->store($avatar, 'avatars', (string) $user->id);
                if ($user->avatar_path) {
                    $this->uploads->delete($user->avatar_path);
                }
                $user->fill(['avatar_path' => $stored['stored_path']]);
                $user->save();
                $changed = true;
            } catch (\RuntimeException $e) {
                return $this->apiError($e->getMessage(), null, 422);
            }
        }

        if (!$changed) {
            return $this->apiError(
                'No updatable fields provided. Students may update their name, phone, bio, and avatar — '
                . 'faculty/department/academic/status changes are university- or faculty-managed.',
                null,
                422
            );
        }

        $details = $this->students->withProfileDetails($self->id);
        return $this->apiSuccess($this->toProfileRow($details, $request), 'Profile updated successfully.');
    }

    /**
     * Resolves the caller's own admin scope from their session — never
     * from client input. Returns null for any role that isn't a
     * university or faculty account (including 'student', handled
     * separately by each action).
     * @return array{role:string,universityId:mixed,facultyId:?int}|null
     */
    private function resolveScope(Request $request): ?array
    {
        $role = $request->attributes->get('uip_role');
        $userId = (int) $request->attributes->get('uip_user_id');

        if ($role === 'university') {
            $user = User::find($userId);
            $university = $this->universities->getOrCreate($userId, (string) ($user->full_name ?? ''));
            return ['role' => 'university', 'universityId' => $university->id, 'facultyId' => null];
        }

        if ($role === 'faculty') {
            $faculty = $this->faculties->findByUserId($userId);
            if (!$faculty) {
                return null;
            }
            return ['role' => 'faculty', 'universityId' => $faculty->university_id, 'facultyId' => (int) $faculty->id];
        }

        return null;
    }

    private function nullableInt(Request $request, string $key): ?int
    {
        $v = $request->input($key);
        return ($v !== null && $v !== '') ? (int) $v : null;
    }

    /** Row shape for index() — from StudentRepository::forUniversityWithStats()/forFacultyWithStats(). */
    private function toRow(array $s): array
    {
        return [
            'id'                       => (int) $s['id'],
            'full_name'                => $s['full_name'],
            'name_ar'                  => $s['name_ar'] ?? null,
            'name_en'                  => $s['name_en'] ?? null,
            'email'                    => $s['email'],
            'student_number'           => $s['student_number'],
            'academic_year'            => $s['academic_year'] !== null ? (int) $s['academic_year'] : null,
            'current_semester'         => isset($s['current_semester']) && $s['current_semester'] !== null ? (int) $s['current_semester'] : null,
            'faculty'                  => $s['faculty'],
            'faculty_id'               => isset($s['faculty_id']) && $s['faculty_id'] !== null ? (int) $s['faculty_id'] : null,
            'department'               => $s['department'],
            'department_id'            => isset($s['department_id']) && $s['department_id'] !== null ? (int) $s['department_id'] : null,
            'group_id'                 => $s['group_id'] !== null ? (int) $s['group_id'] : null,
            'group_name'               => $s['group_name'] ?? null,
            'account_status'           => $s['account_status'],
            'invitation_status'        => $s['effective_invitation_status'] ?? null,
            'can_manage_password'      => !empty($s['invited_at']),
            'projects_count'           => (int) ($s['projects_count'] ?? 0),
            'published_projects_count' => (int) ($s['published_count'] ?? 0),
            'created_at'               => $s['created_at'] ?? null,
        ];
    }

    /** Row shape for show()/update() — from StudentRepository::withProfileDetails(). */
    private function toProfileRow(array $s, Request $request): array
    {
        $locale = (string) $request->input('locale', 'ar');
        return [
            'id'                       => (int) $s['id'],
            'full_name'                => $s['full_name'],
            'name_ar'                  => $s['name_ar'] ?? null,
            'name_en'                  => $s['name_en'] ?? null,
            'email'                    => $s['email'],
            'phone'                    => $s['phone'] ?? null,
            'avatar_path'              => $s['avatar_path'] ?? null,
            'account_status'           => $s['account_status'],
            'student_number'           => $s['student_number'],
            'academic_year'            => $s['academic_year'] !== null ? (int) $s['academic_year'] : null,
            'current_semester'         => isset($s['current_semester']) && $s['current_semester'] !== null ? (int) $s['current_semester'] : null,
            'university_id'            => $s['university_id'] !== null ? (int) $s['university_id'] : null,
            'university_name'          => $locale === 'ar' ? ($s['university_name_ar'] ?? null) : ($s['university_name_en'] ?? null),
            'faculty_id'               => $s['faculty_id'] !== null ? (int) $s['faculty_id'] : null,
            'faculty_name'             => $locale === 'ar' ? ($s['faculty_name_ar'] ?? null) : ($s['faculty_name_en'] ?? null),
            'department_id'            => $s['department_id'] !== null ? (int) $s['department_id'] : null,
            'department_name'          => $locale === 'ar' ? ($s['department_name_ar'] ?? null) : ($s['department_name_en'] ?? null),
            'group_id'                 => $s['group_id'] !== null ? (int) $s['group_id'] : null,
            'bio'                      => $s['bio'] ?? null,
            'study_start_date'         => $s['study_start_date'] ?? null,
            'expected_graduation_date' => $s['expected_graduation_date'] ?? null,
            'created_at'               => $s['created_at'] ?? null,
        ];
    }
}
