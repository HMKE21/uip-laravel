<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ProjectApprovalService;
use App\Services\ProjectDeadlineService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * /api/v1/project-deadlines — الجامعة والكلية بيحددوا موعد التسليم/المناقشة، والطالب بيشوف الموعد المنطبق عليه.
 */
class ProjectDeadlinesApiController extends Controller
{
    public function __construct(
        private ProjectDeadlineService $deadlines,
        private ProjectApprovalService $approvals
    ) {
    }

    /** @return array{0:?int,1:?int} [universityId, facultyId] لحساب جامعة/كلية، وإلا [null,null]. */
    private function scope(Request $request): array
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        $role = (string) $request->attributes->get('uip_role');
        if ($role === 'university') {
            return [$this->approvals->universityIdForUser($userId), null];
        }
        if ($role === 'faculty') {
            $facultyId = $this->approvals->facultyIdForUser($userId);
            $uni = $facultyId ? DB::table('faculties')->where('id', $facultyId)->value('university_id') : null;
            return [$uni ? (int) $uni : null, $facultyId];
        }
        return [null, null];
    }

    /** GET — جامعة/كلية: قائمة مواعيدها. طالب: الموعد المنطبق عليه. */
    public function index(Request $request)
    {
        $role = (string) $request->attributes->get('uip_role');
        if ($role === 'student') {
            $s = DB::table('students')->where('user_id', (int) $request->attributes->get('uip_user_id'))->first(['university_id', 'faculty_id']);
            $d = $s ? $this->deadlines->applicable((int) $s->university_id, $s->faculty_id) : null;
            return $this->apiSuccess(['current' => $d], 'Deadline retrieved.');
        }
        [$uni, $fac] = $this->scope($request);
        if (!$uni) {
            return $this->apiError('Only university or faculty accounts can manage deadlines.', null, 403);
        }
        return $this->apiSuccess(['items' => $this->deadlines->listFor($uni, $fac)], 'Deadlines retrieved.');
    }

    public function store(Request $request)
    {
        [$uni, $fac] = $this->scope($request);
        if (!$uni) {
            return $this->apiError('Only university or faculty accounts can manage deadlines.', null, 403);
        }
        $title = trim((string) $request->input('title', ''));
        $submission = trim((string) $request->input('submission_deadline', ''));
        if ($title === '' || ($submission === '' && !$request->input('defense_starts_at'))) {
            return $this->apiError('Validation failed.', ['title' => 'Title and at least one date are required.'], 422);
        }
        foreach (['submission_deadline', 'defense_starts_at', 'defense_ends_at'] as $f) {
            $v = trim((string) $request->input($f, ''));
            if ($v !== '' && strtotime($v) === false) {
                return $this->apiError('Validation failed.', [$f => 'Invalid date.'], 422);
            }
        }
        $id = $this->deadlines->create($uni, $fac, (int) $request->attributes->get('uip_user_id'), [
            'title' => $title,
            'submission_deadline' => $submission !== '' ? date('Y-m-d H:i:s', strtotime($submission)) : null,
            'defense_starts_at' => $request->input('defense_starts_at') ?: null,
            'defense_ends_at' => $request->input('defense_ends_at') ?: null,
        ]);
        return $this->apiSuccess(['id' => $id], 'Deadline created.', 201);
    }

    public function destroy(Request $request, $id)
    {
        [$uni, $fac] = $this->scope($request);
        if (!$uni) {
            return $this->apiError('Only university or faculty accounts can manage deadlines.', null, 403);
        }
        return $this->deadlines->delete((int) $id, $uni, $fac)
            ? $this->apiSuccess(null, 'Deadline deleted.')
            : $this->apiError('Deadline not found.', null, 404);
    }
}
