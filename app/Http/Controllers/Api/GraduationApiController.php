<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\FacultyRepository;
use App\Repositories\StudentRepository;
use App\Repositories\UniversityRepository;
use App\Services\GraduationService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/GraduationApiController.php القديمة —
 * بند 14 (Graduation). سطح REST واحد /api/v1/graduation/* لأهلية/سجل/
 * transcript/شهادة التخرج (`graduation_records`، migration 109)، بيعيد
 * استخدام GraduationService بالظبط زي القديمة (Student/University/
 * Faculty GraduationController).
 *
 * RBAC، ثلاث أدوار، نفس نطاق القديمة بالظبط:
 *   - student: قراءة فقط، سجله هو بس (getOrCreate() من uip_user_id).
 *     مفيش approve/revoke/update.
 *   - university: قائمة مراجعة كاملة + approve/revoke/edit-certificate
 *     لأي طالب في جامعته هي (university_id من getOrCreate()، مش من
 *     العميل أبدًا).
 *   - faculty: نفس الأفعال، محكومة بكلية الحساب (FacultyRepository::
 *     findByUserId())، عبر StudentRepository::findOwned($id,
 *     $universityId, $facultyId) وargument $facultyId الاختياري بتاع
 *     GraduationService في كل ميثود — دفاع إضافي.
 */
class GraduationApiController extends Controller
{
    public function __construct(
        private StudentRepository $students,
        private UniversityRepository $universities,
        private FacultyRepository $faculties,
        private GraduationService $graduation
    ) {
    }

    /**
     * GET /api/v1/graduation
     * student: أهليته هو (سجل واحد). university/faculty: قوائم
     * eligible/graduated/revoked لمراجعة القائمة.
     */
    public function index(Request $request)
    {
        if ($this->role($request) === 'student') {
            $student = $this->students->getOrCreate($this->userId($request));
            return $this->apiSuccess(
                $this->eligibilityForApi((int) $student->id),
                'Graduation eligibility retrieved successfully.'
            );
        }

        if ($scope = $this->universityOrFacultyScope($request)) {
            $lists = $this->graduation->listForUniversity($scope['university_id'], $scope['faculty_id']);
            $lists['eligible'] = array_map(
                fn (array $entry) => $this->serializeEligibility($entry),
                $lists['eligible']
            );
            return $this->apiSuccess($lists, 'Graduation lists retrieved successfully.');
        }

        return $this->apiError('Only student, university, or faculty accounts can view graduation data.', null, 403);
    }

    /**
     * GET /api/v1/graduation/{studentId}
     * student: بس رقمه هو. university/faculty: أي طالب في نطاقهم.
     * بترجع الأهلية + transcript كامل (بروفايل + تقييمات نهائية + سجل
     * التخرج لو موجود).
     */
    public function show(Request $request, string $studentId)
    {
        $studentId = (int) $studentId;

        if ($this->role($request) === 'student') {
            $own = $this->students->getOrCreate($this->userId($request));
            if ((int) $own->id !== $studentId) {
                return $this->apiError('Graduation record not found.', null, 404);
            }
        } elseif ($scope = $this->universityOrFacultyScope($request)) {
            if (!$this->students->findOwned($studentId, $scope['university_id'], $scope['faculty_id'])) {
                return $this->apiError('Student not found within your scope.', null, 404);
            }
        } else {
            return $this->apiError('Only student, university, or faculty accounts can view graduation data.', null, 403);
        }

        return $this->apiSuccess([
            'eligibility' => $this->eligibilityForApi($studentId),
            'transcript'  => $this->transcriptForApi($studentId),
        ], 'Graduation record retrieved successfully.');
    }

    /** PATCH /api/v1/graduation/{studentId} — university/faculty بس (تعديل بيانات شهادة صادرة). */
    public function update(Request $request, string $studentId)
    {
        $scope = $this->universityOrFacultyScope($request);
        if (!$scope) {
            return $this->apiError('Only university or faculty accounts can edit a graduation certificate.', null, 403);
        }

        $studentId = (int) $studentId;
        $result = $this->graduation->editCertificate(
            $studentId,
            $scope['university_id'],
            $this->userId($request),
            [
                'graduation_date'    => (string) $request->input('graduation_date', ''),
                'final_gpa'          => $request->input('final_gpa', ''),
                'degree_title_ar'    => (string) $request->input('degree_title_ar', ''),
                'degree_title_en'    => (string) $request->input('degree_title_en', ''),
                'faculty_name_ar'    => (string) $request->input('faculty_name_ar', ''),
                'faculty_name_en'    => (string) $request->input('faculty_name_en', ''),
                'department_name_ar' => (string) $request->input('department_name_ar', ''),
                'department_name_en' => (string) $request->input('department_name_en', ''),
                'notes'              => (string) $request->input('notes', ''),
            ],
            'en',
            $scope['faculty_id']
        );

        if (!$result['success']) {
            return $this->apiError($result['message'], null, 422);
        }

        return $this->apiSuccess($this->transcriptForApi($studentId), $result['message']);
    }

    /** POST /api/v1/graduation/{studentId}/approve — university/faculty بس. */
    public function approve(Request $request, string $studentId)
    {
        $scope = $this->universityOrFacultyScope($request);
        if (!$scope) {
            return $this->apiError('Only university or faculty accounts can approve a graduation.', null, 403);
        }

        $studentId = (int) $studentId;
        $result = $this->graduation->approve(
            $studentId,
            $scope['university_id'],
            $this->userId($request),
            (string) $request->input('degree_title_ar', ''),
            (string) $request->input('degree_title_en', ''),
            (string) $request->input('notes', ''),
            'en',
            $scope['faculty_id'],
            (bool) $request->input('manual_override', false),
            (string) $request->input('override_reason', ''),
            [
                'graduation_date'    => (string) $request->input('graduation_date', ''),
                'final_gpa'          => $request->input('final_gpa', ''),
                'faculty_name_ar'    => (string) $request->input('faculty_name_ar', ''),
                'faculty_name_en'    => (string) $request->input('faculty_name_en', ''),
                'department_name_ar' => (string) $request->input('department_name_ar', ''),
                'department_name_en' => (string) $request->input('department_name_en', ''),
            ]
        );

        if (!$result['success']) {
            return $this->apiError($result['message'], null, 422);
        }

        return $this->apiSuccess($this->transcriptForApi($studentId), $result['message'], 201);
    }

    /** POST /api/v1/graduation/{studentId}/revoke — university/faculty بس. */
    public function revoke(Request $request, string $studentId)
    {
        $scope = $this->universityOrFacultyScope($request);
        if (!$scope) {
            return $this->apiError('Only university or faculty accounts can revoke a graduation.', null, 403);
        }

        $reason = trim((string) $request->input('reason', ''));
        if ($reason === '') {
            return $this->apiError('The reason field is required.', ['reason' => ['The reason field is required.']], 422);
        }

        $studentId = (int) $studentId;
        $result = $this->graduation->revoke(
            $studentId,
            $scope['university_id'],
            $this->userId($request),
            $reason,
            'en',
            $scope['faculty_id']
        );

        if (!$result['success']) {
            return $this->apiError($result['message'], null, 422);
        }

        return $this->apiSuccess($this->transcriptForApi($studentId), $result['message']);
    }

    /**
     * POST /api/v1/graduation/{studentId}/restore — university/faculty بس.
     * بيسحب الإلغاء ويرجّع الطالب متخرج بنفس الشهادة ونفس البيانات القديمة.
     */
    public function restore(Request $request, string $studentId)
    {
        $scope = $this->universityOrFacultyScope($request);
        if (!$scope) {
            return $this->apiError('Only university or faculty accounts can restore a graduation.', null, 403);
        }

        $studentId = (int) $studentId;
        $result = $this->graduation->restore(
            $studentId,
            $scope['university_id'],
            $this->userId($request),
            'en',
            $scope['faculty_id']
        );

        if (!$result['success']) {
            return $this->apiError($result['message'], null, 422);
        }

        return $this->apiSuccess($this->transcriptForApi($studentId), $result['message']);
    }

    /** GET /api/v1/graduation/{studentId}/certificate */
    public function certificate(Request $request, string $studentId)
    {
        $studentId = (int) $studentId;

        if ($this->role($request) === 'student') {
            $own = $this->students->getOrCreate($this->userId($request));
            if ((int) $own->id !== $studentId) {
                return $this->apiError('Certificate not found.', null, 404);
            }
        } elseif ($scope = $this->universityOrFacultyScope($request)) {
            if (!$this->students->findOwned($studentId, $scope['university_id'], $scope['faculty_id'])) {
                return $this->apiError('Student not found within your scope.', null, 404);
            }
        } else {
            return $this->apiError('Only student, university, or faculty accounts can view a certificate.', null, 403);
        }

        $data = $this->graduation->transcriptFor($studentId);
        if (!$data['graduation'] || $data['graduation']->status !== 'graduated') {
            return $this->apiError('No approved graduation certificate yet.', null, 404);
        }

        $university = $this->universities->find($data['graduation']->university_id);

        return $this->apiSuccess([
            'student'    => $data['student'],
            'graduation' => $data['graduation']->toArray(),
            'university' => $university ? array_merge($university->toArray(), $university->brandingUrls()) : null,
        ], 'Certificate retrieved successfully.');
    }

    // -- helpers --------------------------------------------------------------

    private function role(Request $request): string
    {
        return (string) $request->attributes->get('uip_role');
    }

    private function userId(Request $request): int
    {
        return (int) $request->attributes->get('uip_user_id');
    }

    private function eligibilityForApi(int $studentId): array
    {
        return $this->serializeEligibility($this->graduation->checkEligibility($studentId));
    }

    private function serializeEligibility(array $eligibility): array
    {
        if (!empty($eligibility['existing_record'])) {
            $eligibility['existing_record'] = $eligibility['existing_record']->toArray();
        }
        return $eligibility;
    }

    private function transcriptForApi(int $studentId): array
    {
        $transcript = $this->graduation->transcriptFor($studentId);
        if ($transcript['graduation']) {
            $transcript['graduation'] = $transcript['graduation']->toArray();
        }
        return $transcript;
    }

    /** @return array{university_id:int, faculty_id:?int}|null null لو الحساب مش جامعة ولا كلية. */
    private function universityOrFacultyScope(Request $request): ?array
    {
        $role = $this->role($request);

        if ($role === 'university') {
            $university = $this->universities->getOrCreate($this->userId($request));
            return ['university_id' => (int) $university->id, 'faculty_id' => null];
        }

        if ($role === 'faculty') {
            $faculty = $this->faculties->findByUserId($this->userId($request));
            if (!$faculty) {
                return null;
            }
            return ['university_id' => (int) $faculty->university_id, 'faculty_id' => (int) $faculty->id];
        }

        return null;
    }
}
