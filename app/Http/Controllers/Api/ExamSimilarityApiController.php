<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\AcademicStaffRepository;
use App\Services\ExamSimilarityService;
use App\Services\ExamSystemService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * /api/v1/exam-system/exams/{id}/similarity* — كشف التشابه بين إجابات المقالي. سطح المدرس بس،
 * وبنفس ownership check بتاع باقي exams/{id}/* (findOwnedExam): مدرس تاني ياخد 404.
 */
class ExamSimilarityApiController extends Controller
{
    public function __construct(
        private ExamSimilarityService $similarity,
        private ExamSystemService $examSystem,
        private AcademicStaffRepository $staffRepo
    ) {
    }

    /** @return array{0:mixed,1:mixed,2:mixed} [staff, exam, errorResponse] */
    private function resolve(Request $request, $examId): array
    {
        if ($request->attributes->get('uip_role') !== 'academic_staff') {
            return [null, null, $this->apiError('Only academic staff accounts can use similarity detection.', null, 403)];
        }
        $staff = $this->staffRepo->findByUserId((int) $request->attributes->get('uip_user_id'));
        if (!$staff) {
            return [null, null, $this->apiError('No academic staff profile found for this account.', null, 404)];
        }
        $exam = $this->examSystem->findOwnedExam($examId, $staff->id);
        if (!$exam) {
            return [null, null, $this->apiError('Exam not found.', null, 404)];
        }
        return [$staff, $exam, null];
    }

    /** GET .../similarity?status=pending|confirmed|dismissed */
    public function index(Request $request, $examId)
    {
        [, $exam, $err] = $this->resolve($request, $examId);
        if ($err) {
            return $err;
        }
        $status = $request->query('status');
        if ($status !== null && !in_array($status, ExamSimilarityService::STATUSES, true)) {
            return $this->apiError('Invalid status filter.', null, 422);
        }

        return $this->apiSuccess([
            'flags'  => $this->similarity->listForExam($exam, $status),
            'counts' => $this->similarity->countsForExam($exam),
            'min_words' => ExamSimilarityService::MIN_WORDS,
            'default_threshold' => ExamSimilarityService::DEFAULT_THRESHOLD,
        ], 'Similarity flags retrieved successfully.');
    }

    /** POST .../similarity/analyze  body { threshold?: 30..100 } */
    public function analyze(Request $request, $examId)
    {
        [$staff, $exam, $err] = $this->resolve($request, $examId);
        if ($err) {
            return $err;
        }
        $v = Validator::make($request->all(), ['threshold' => 'nullable|numeric|min:30|max:100']);
        if ($v->fails()) {
            return $this->apiError('The given data was invalid.', $v->errors()->toArray(), 422);
        }
        $result = $this->similarity->analyze($exam, (float) $request->input('threshold', ExamSimilarityService::DEFAULT_THRESHOLD), $staff);

        return $this->apiSuccess($result, 'Similarity analysis completed.');
    }

    /** PATCH .../similarity/{flagId}  body { status, note? } */
    public function review(Request $request, $examId, $flagId)
    {
        [$staff, $exam, $err] = $this->resolve($request, $examId);
        if ($err) {
            return $err;
        }
        $v = Validator::make($request->all(), ['status' => 'required|in:pending,confirmed,dismissed', 'note' => 'nullable|string|max:2000']);
        if ($v->fails()) {
            return $this->apiError('The given data was invalid.', $v->errors()->toArray(), 422);
        }
        try {
            $flag = $this->similarity->review($exam, (int) $flagId, $request->input('status'), $request->input('note'), $staff);
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 404);
        }

        return $this->apiSuccess(['id' => $flag->id, 'status' => $flag->status], 'Flag updated.');
    }
}
