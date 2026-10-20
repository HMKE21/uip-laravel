<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Faculty;
use App\Models\University;
use App\Models\User;
use App\Repositories\StaffAssignmentRepository;
use App\Repositories\StudentGroupRepository;
use App\Repositories\StudentRepository;
use App\Repositories\SupervisorAssignmentRepository;
use Illuminate\Http\Request;

/**
 * سطح /api/v1/contacts للطالب — "My Contacts" (StudentContacts.jsx): جامعته
 * وكليته (حساب اللوجين المرتبط بيهم عبر user_id، يعني ايميل حقيقي قابل
 * للمراسلة)، رئيس القسم الحالي (StaffAssignmentRepository::
 * currentHolderByRankName بمسمى "Head of Department" — نفس الاتفاق النصي
 * اللي كل إشارة تانية للمسميات الإدارية في المشروع بتستخدمه)، مشرفيه
 * الحقيقيين (SupervisorAssignmentRepository::supervisorsForStudent —
 * أبدًا مش مشرف عشوائي)، وزملاء مجموعته (StudentGroupRepository::members،
 * الطالب نفسه مُستبعد من القايمة).
 *
 * RBAC: uip.auth بتغطي الجروب (routes/api.php)؛ role='student' بتتفحص جوه
 * الميثود — الصفحة دي طالب بس (على عكس بعض الأسطح التانية اللي دورين).
 */
class ContactsApiController extends Controller
{
    public function __construct(
        private StudentRepository $students,
        private StaffAssignmentRepository $staffAssignments,
        private SupervisorAssignmentRepository $supervisorAssignments,
        private StudentGroupRepository $studentGroups
    ) {
    }

    /** GET /api/v1/contacts */
    public function index(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'student') {
            return $this->apiError('Only student accounts can view these contacts.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $hierarchy = $this->students->fullHierarchyForUser($userId);

        if (!$hierarchy) {
            return $this->apiSuccess([
                'hierarchy'       => null,
                'university'      => null,
                'faculty'         => null,
                'department_head' => null,
                'supervisors'     => [],
                'group_members'   => [],
            ], 'Contacts retrieved successfully.');
        }

        $university = null;
        if (!empty($hierarchy['university_id'])) {
            $uni = University::find($hierarchy['university_id']);
            $uniUser = $uni && $uni->user_id ? User::find($uni->user_id) : null;
            if ($uni && $uniUser) {
                $university = [
                    'name_ar' => $uni->official_name_ar,
                    'name_en' => $uni->official_name_en,
                    'email'   => $uniUser->email,
                ];
            }
        }

        $faculty = null;
        if (!empty($hierarchy['faculty_id'])) {
            $fac = Faculty::find($hierarchy['faculty_id']);
            $facUser = $fac && $fac->user_id ? User::find($fac->user_id) : null;
            if ($fac && $facUser) {
                $faculty = [
                    'name_ar' => $fac->name_ar,
                    'name_en' => $fac->name_en,
                    'email'   => $facUser->email,
                ];
            }
        }

        $departmentHead = !empty($hierarchy['department_id'])
            ? $this->staffAssignments->currentHolderByRankName('department', $hierarchy['department_id'], 'Head of Department')
            : null;

        $supervisors = $this->supervisorAssignments->supervisorsForStudent($userId);

        $groupMembers = [];
        if (!empty($hierarchy['group_id'])) {
            $groupMembers = array_values(array_filter(
                $this->studentGroups->members($hierarchy['group_id']),
                fn ($m) => (int) $m['user_id'] !== $userId
            ));
        }

        return $this->apiSuccess([
            'hierarchy'       => $hierarchy,
            'university'      => $university,
            'faculty'         => $faculty,
            'department_head' => $departmentHead,
            'supervisors'     => $supervisors,
            'group_members'   => $groupMembers,
        ], 'Contacts retrieved successfully.');
    }
}
