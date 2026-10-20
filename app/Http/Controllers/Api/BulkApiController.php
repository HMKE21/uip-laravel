<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\FacultyRepository;
use App\Repositories\StudentRepository;
use App\Repositories\UniversityRepository;
use App\Services\StudentManagementService;
use App\Support\TabularFileReader;
use Illuminate\Http\Request;

/**
 * /api/v1/bulk/students/* — the two bulk actions the students pages call:
 *   POST /bulk/students/import  multipart (csv_file | file, .csv/.xlsx) → {imported, skipped, errors, results}
 *   POST /bulk/students/update  {ids:[], group_id|null}                  → move students to a group
 * University accounts act on the whole university; faculty accounts only on their own faculty.
 */
class BulkApiController extends Controller
{
    public function __construct(
        private StudentManagementService $management,
        private StudentRepository $students,
        private UniversityRepository $universities,
        private FacultyRepository $faculties
    ) {
    }

    private function scope(Request $request): ?array
    {
        $role = $request->attributes->get('uip_role');
        $userId = (int) $request->attributes->get('uip_user_id');

        if ($role === 'university') {
            $user = User::find($userId);
            $university = $this->universities->getOrCreate($userId, (string) ($user->full_name ?? ''));
            return ['universityId' => $university->id, 'facultyId' => null, 'userId' => $userId];
        }
        if ($role === 'faculty') {
            $faculty = $this->faculties->findByUserId($userId);
            return $faculty ? ['universityId' => $faculty->university_id, 'facultyId' => (int) $faculty->id, 'userId' => $userId] : null;
        }
        return null;
    }

    public function studentsImport(Request $request)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->apiError('Only university or faculty accounts can import students.', null, 403);
        }

        $file = $request->file('csv_file') ?: $request->file('file');
        if (!$file || !$file->isValid()) {
            return $this->apiError('Validation failed.', ['file' => 'A .csv or .xlsx file is required.'], 422);
        }

        try {
            $rows = TabularFileReader::readAssoc($file->getRealPath(), strtolower($file->getClientOriginalExtension()));
        } catch (\Throwable $e) {
            return $this->apiError($e->getMessage() ?: 'Could not read the uploaded file.', null, 422);
        }
        if (empty($rows)) {
            return $this->apiError('The file has no data rows.', null, 422);
        }
        if (count($rows) > 1000) {
            return $this->apiError('Please import at most 1000 rows at a time.', null, 422);
        }

        $summary = $this->management->importRows(
            $rows,
            $scope['universityId'],
            $scope['userId'],
            $scope['facultyId'],
            (string) $request->input('locale', 'ar'),
            $request->boolean('send_email', false)
        );

        return $this->apiSuccess($summary, 'Import finished.');
    }

    public function studentsUpdate(Request $request)
    {
        $scope = $this->scope($request);
        if (!$scope) {
            return $this->apiError('Only university or faculty accounts can update students.', null, 403);
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('ids', [])))));
        if (!$ids) {
            return $this->apiError('Validation failed.', ['ids' => 'Select at least one student.'], 422);
        }
        // Faculty accounts can only touch students of their own faculty.
        $ids = array_values(array_filter($ids, fn ($id) => $this->students->findOwned($id, $scope['universityId'], $scope['facultyId'])));
        if (!$ids) {
            return $this->apiError('No matching students.', null, 404);
        }

        $groupId = $request->input('group_id');
        $groupId = ($groupId !== null && $groupId !== '') ? (int) $groupId : null;

        $result = $this->management->moveStudents($ids, $scope['universityId'], $scope['userId'], $groupId, (string) $request->input('locale', 'ar'));

        return $result['success']
            ? $this->apiSuccess(['moved' => count($ids)], $result['message'])
            : $this->apiError($result['message'], null, 422);
    }
}
