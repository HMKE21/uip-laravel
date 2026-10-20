<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\AiCodeReviewIssueRepository;
use App\Repositories\AiCodeReviewRepository;
use App\Repositories\UniversityRepository;
use App\Services\AuditLogService;
use App\Services\GithubCodeReviewService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/AdminAiCodeReviewApiController.php
 * القديمة — بند 26 (Admin) batch 6. بتعيد استخدام AiCodeReviewRepository::
 * filteredPaginated()/platformStats()/allForProject()/find()،
 * AiCodeReviewIssueRepository::severityCountsForReview()/forReview()،
 * GithubCodeReviewService::runAsAdmin()، و
 * UniversityRepository::allForRegistration() بالظبط زي
 * Admin\AdminAiCodeReviewController القديمة كانت بتعمله
 * (index()/history()/approve()/addNote()/overrideScore()/rerun()/
 * compareVersions()). مفيش حاجة مختلَقة هنا؛ كل تعديل بيكتب نفس الأعمدة
 * الحقيقية على ai_code_review_results وبيتسجل عبر AuditLogService بالظبط
 * زي flow الفورم-Blade القديم.
 *
 * التصدير (project/university/faculty/comparison، متعدد الصيغ) موجود في
 * AdminAiCodeReviewExportController — بيرجع Response ملف قائم بذاته
 * (Content-Disposition)، ومسجل تحت نفس prefix /api/v1/admin/ai-code-review.
 *
 * RBAC: middleware uip.auth + uip.admin بيقفلوا المجموعة كلها (routes/api.php).
 */
class AdminAiCodeReviewApiController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(
        private AiCodeReviewRepository $reviews,
        private AiCodeReviewIssueRepository $issues,
        private GithubCodeReviewService $codeReview,
        private AuditLogService $auditLog,
        private UniversityRepository $universities
    ) {
    }

    private function requireAdmin(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'admin') {
            return $this->apiError('Only admin accounts can access AI code review oversight.', null, 403);
        }
        return null;
    }

    /** GET /api/v1/admin/ai-code-review — قائمة مفلترة + مقسّمة صفحات على مستوى المنصة. */
    public function index(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $page = max(1, (int) $request->input('page', 1));
        $filters = [
            'university_id' => $request->input('university_id', ''),
            'status'        => $request->input('status', ''),
            'date_from'     => $request->input('date_from', ''),
            'date_to'       => $request->input('date_to', ''),
            'score_min'     => $request->input('score_min', ''),
            'score_max'     => $request->input('score_max', ''),
            'search'        => trim((string) $request->input('search', '')),
        ];

        $result = $this->reviews->filteredPaginated($filters, $page, self::PER_PAGE);
        $totalPages = (int) ceil($result['total'] / self::PER_PAGE);

        $universities = array_map(fn ($u) => [
            'id'   => $u->id,
            'name' => $u->official_name_en ?: $u->official_name_ar,
        ], $this->universities->allForRegistration());

        return $this->apiSuccess($result['rows'], 'Code reviews retrieved successfully.', 200, [
            'stats'        => $this->reviews->platformStats(),
            'universities' => $universities,
            'filters'      => $filters,
            'page'         => $page,
            'totalPages'   => $totalPages,
            'total'        => $result['total'],
        ]);
    }

    /** GET /api/v1/admin/ai-code-review/project/{projectId}/history — سلسلة النسخ الكاملة، الأحدث أولاً. */
    public function history(Request $request, $projectId)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $chain = $this->reviews->allForProject((int) $projectId);

        $versions = array_map(function ($review) {
            $row = $review->toArray();
            $row['scores'] = $review->scores();
            $row['issue_counts'] = $this->issues->severityCountsForReview((int) $review->id);
            return $row;
        }, $chain);

        return $this->apiSuccess($versions, 'Review history retrieved successfully.');
    }

    /** POST /api/v1/admin/ai-code-review/{id}/approve */
    public function approve(Request $request, $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $review = $this->reviews->find((int) $id);
        if (!$review) {
            return $this->apiError('Review not found.', null, 404);
        }

        $adminId = $request->attributes->get('uip_user_id');
        $review->reviewed_by = $adminId;
        $review->approved_at = date('Y-m-d H:i:s');
        $review->save();

        $this->auditLog->record($adminId, 'ai_code_review.approve', 'AiCodeReviewResult', $id, null, [
            'reviewed_by' => $adminId,
            'approved_at' => $review->approved_at,
        ]);

        return $this->apiSuccess(['approved_at' => $review->approved_at], 'Review approved.');
    }

    /** POST /api/v1/admin/ai-code-review/{id}/note — {admin_notes} */
    public function addNote(Request $request, $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $note = trim((string) $request->input('admin_notes', ''));
        if ($note === '') {
            return $this->apiError('Note text is required.', null, 422);
        }

        $review = $this->reviews->find((int) $id);
        if (!$review) {
            return $this->apiError('Review not found.', null, 404);
        }

        $old = $review->admin_notes;
        $review->admin_notes = $note;
        $review->save();

        $this->auditLog->record($request->attributes->get('uip_user_id'), 'ai_code_review.add_note', 'AiCodeReviewResult', $id,
            ['admin_notes' => $old],
            ['admin_notes' => $note]
        );

        return $this->apiSuccess(['admin_notes' => $note], 'Note saved.');
    }

    /** POST /api/v1/admin/ai-code-review/{id}/override-score — {overall_score, override_reason} */
    public function overrideScore(Request $request, $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $newScore = $request->input('overall_score', null);
        $reason = trim((string) $request->input('override_reason', ''));

        if ($reason === '' || $newScore === null || $newScore === '' || !is_numeric($newScore) || (int) $newScore < 0 || (int) $newScore > 100) {
            return $this->apiError('A score (0-100) and a written reason are both required.', null, 422);
        }

        $review = $this->reviews->find((int) $id);
        if (!$review) {
            return $this->apiError('Review not found.', null, 404);
        }

        $old = $review->overall_score;
        $review->overall_score = (int) $newScore;
        $review->score_overridden = 1;
        $review->override_reason = $reason;
        $review->reviewed_by = $request->attributes->get('uip_user_id');
        $review->save();

        $this->auditLog->record($request->attributes->get('uip_user_id'), 'ai_code_review.override_score', 'AiCodeReviewResult', $id,
            ['overall_score' => $old],
            ['overall_score' => (int) $newScore, 'override_reason' => $reason]
        );

        return $this->apiSuccess(['overall_score' => (int) $newScore, 'override_reason' => $reason], 'Score overridden.');
    }

    /** POST /api/v1/admin/ai-code-review/{projectUuid}/rerun — تحليل حقيقي جديد. */
    public function rerun(Request $request, $projectUuid)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $adminId = $request->attributes->get('uip_user_id');

        try {
            $result = $this->codeReview->runAsAdmin((string) $projectUuid, $adminId);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        $this->auditLog->record($adminId, 'ai_code_review.rerun', 'Project', $projectUuid, null, [
            'new_review_id' => $result['id'] ?? null,
            'version'       => $result['version'] ?? null,
        ]);

        return $this->apiSuccess($result, 'Review re-run started and completed.');
    }

    /** GET /api/v1/admin/ai-code-review/compare/{idA}/{idB} — فرق السكور + الـ issues. */
    public function compareVersions(Request $request, $idA, $idB)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $reviewA = $this->reviews->find((int) $idA);
        $reviewB = $this->reviews->find((int) $idB);
        if (!$reviewA || !$reviewB) {
            return $this->apiError('One or both reviews not found.', null, 404);
        }

        $a = $reviewA->toArray();
        $a['scores'] = $reviewA->scores();
        $a['issues'] = array_map(fn ($i) => $i->toArray(), $this->issues->forReview((int) $idA));

        $b = $reviewB->toArray();
        $b['scores'] = $reviewB->scores();
        $b['issues'] = array_map(fn ($i) => $i->toArray(), $this->issues->forReview((int) $idB));

        $scoreDiff = [];
        foreach ($a['scores'] as $key => $val) {
            $scoreDiff[$key] = ($b['scores'][$key] ?? null) !== null && $val !== null
                ? $b['scores'][$key] - $val
                : null;
        }

        return $this->apiSuccess(['a' => $a, 'b' => $b, 'score_diff' => $scoreDiff], 'Comparison retrieved successfully.');
    }
}
