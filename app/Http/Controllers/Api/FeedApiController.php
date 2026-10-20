<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\FeedRepository;
use App\Repositories\UniversityRepository;
use App\Services\FeedService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/FeedApiController.php القديمة — سطح
 * /api/v1/feed/* واحد لبند الـ University Feed (migration 122 —
 * feed_posts + attachments/likes/saves/shares/comments/reports)، بنفس
 * الشكل بالظبط اللي GroupsApiController منقول بيه:
 *
 *   - جامعة: index()/show() بترجع بوستاتها هي (كل الحالات، drafts+published)
 *     عبر FeedRepository::postsForUniversity(). store()/update()/destroy()/
 *     publishDraft()/unpublish()/pin()/unpin() بتلف FeedService's identical
 *     calls — university_id دايمًا مُشتق من uip_user_id، مش من client
 *     input. reports()/resolveReport() طابور المراجعة (moderation queue).
 *   - طالب: index()/show() بترجع الفيد المنشور بس، مقيّد بجامعته
 *     (universityIdForStudent()) + نطاق رؤيته (scopeForStudent()) —
 *     زي AnnouncementsApiController بالظبط. like()/save()/comment()/
 *     deleteComment()/share()/report() بتلف FeedService's identical
 *     toggleLike()/toggleSave()/addComment()/deleteComment()/share()/
 *     report() calls.
 *
 * فرق شكلي فقط عن القديمة (زي GroupsApiController بالظبط):
 * Session::userId()/hasRole() -> $request->attributes->get('uip_user_id')/
 * 'uip_role')، $this->param('id') -> $id مُمرر صراحة كـ route parameter.
 * store()/update() بتاخد الـ Request كامل زي القديمة بالظبط، لأن
 * FeedService نفسه بيقرا title/body/is_event/... مباشرة منه.
 *
 * RBAC: uip.auth بتغطي الجروب كله (routes/api.php) + feed.comment_rate_limit
 * إضافي على POST /feed/{id}/comments بس. كل ميثود بتتأكد من uip_role بنفسها
 * لأن الجامعة والطالب بيعملوا أكشنز مختلفة تمامًا، زي الكنترولرين القديمين
 * اللي دي بتلفهم.
 */
class FeedApiController extends Controller
{
    public function __construct(
        private FeedRepository $feedRepo,
        private UniversityRepository $universities,
        private FeedService $feed
    ) {
    }

    // -- Listing / reading ----------------------------------------------------

    /** GET /api/v1/feed — جامعة: بوستاتها هي (كل الحالات). طالب: الفيد المنشور مقيّد بجامعته + نطاق رؤيته. */
    public function index(Request $request)
    {
        $role = $request->attributes->get('uip_role');
        $userId = $request->attributes->get('uip_user_id');

        $page = max(1, (int) $request->input('page', 1));
        $perPage = max(1, min(50, (int) $request->input('per_page', 10)));

        if ($role === 'university') {
            $university = $this->universities->getOrCreate($userId);
            $filters = [
                'q'      => trim((string) $request->input('q', '')),
                'status' => $request->input('status', 'all'),
            ];

            $listing = $this->feedRepo->postsForUniversity((int) $university->id, $filters, $page, $perPage);
            $attachments = $this->feedRepo->attachmentsForPosts(array_column($listing['items'], 'id'));

            return $this->apiSuccess($listing['items'], 'Feed posts retrieved successfully.', 200, [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $listing['total'],
                'attachments' => $attachments,
            ]);
        }

        if ($role === 'student') {
            $universityId = $this->feed->universityIdForStudent($userId);
            $filters = [
                'q'    => trim((string) $request->input('q', '')),
                'type' => $request->input('type', 'all'),
            ];

            if (!$universityId) {
                return $this->apiSuccess([], 'Feed posts retrieved successfully.', 200, [
                    'page' => 1,
                    'perPage' => $perPage,
                    'total' => 0,
                    'unaffiliated' => true,
                ]);
            }

            $scope = $this->feed->scopeForStudent($userId);
            $listing = $this->feedRepo->feedForUniversity($universityId, $userId, $filters, $page, $perPage, $scope);
            $postIds = array_column($listing['items'], 'id');
            $attachments = $this->feedRepo->attachmentsForPosts($postIds);
            $comments = [];
            foreach ($postIds as $pid) {
                $comments[$pid] = $this->feedRepo->commentsForPost((int) $pid);
            }

            return $this->apiSuccess($listing['items'], 'Feed posts retrieved successfully.', 200, [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $listing['total'],
                'attachments' => $attachments,
                'comments' => $comments,
            ]);
        }

        return $this->apiError('Only university or student accounts can view the feed.', null, 403);
    }

    /** GET /api/v1/feed/{id} — ownership-checked، مع attachments + comments. */
    public function show(Request $request, $id)
    {
        $universityId = $this->resolveUniversityId($request);
        if ($universityId === null) {
            return $this->apiError('Only university or affiliated student accounts can view feed posts.', null, 403);
        }

        $post = $this->feedRepo->findOwnedByUniversity((int) $id, $universityId);
        if (!$post) {
            return $this->apiError('Post not found.', null, 404);
        }

        $post['attachments'] = $this->feedRepo->attachmentsForPosts([$post['id']])[$post['id']] ?? [];
        $post['comments'] = $this->feedRepo->commentsForPost((int) $post['id']);

        return $this->apiSuccess($post, 'Feed post retrieved successfully.');
    }

    // -- University: publish / manage -----------------------------------------

    /** POST /api/v1/feed — جامعة بس. */
    public function store(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can publish feed posts.', null, 403);
        }

        $userId = $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);

        try {
            $id = $this->feed->publish((int) $university->id, $userId, $request);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess(['id' => $id], 'Post published successfully.', 201);
    }

    /** PATCH /api/v1/feed/{id} — جامعة بس. */
    public function update(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can update a feed post.', null, 403);
        }

        $userId = $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);

        try {
            $this->feed->update((int) $id, (int) $university->id, $request);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess(null, 'Post updated successfully.');
    }

    /** DELETE /api/v1/feed/{id} — جامعة بس. */
    public function destroy(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can delete a feed post.', null, 403);
        }

        $userId = $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);

        try {
            $this->feed->delete((int) $id, (int) $university->id, $userId);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 404);
        }

        return $this->apiSuccess(null, 'Post deleted successfully.');
    }

    /** POST /api/v1/feed/{id}/publish — بينشر draft محفوظ. جامعة بس. */
    public function publishDraft(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can publish a draft.', null, 403);
        }

        $userId = $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);

        try {
            $this->feed->publishDraft((int) $id, (int) $university->id, $userId);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 404);
        }

        return $this->apiSuccess(null, 'Post published successfully.');
    }

    /** POST /api/v1/feed/{id}/unpublish — بيرجّع بوست منشور لـ draft. جامعة بس. */
    public function unpublish(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can unpublish a post.', null, 403);
        }

        $userId = $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);

        try {
            $this->feed->unpublish((int) $id, (int) $university->id, $userId);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 404);
        }

        return $this->apiSuccess(null, 'Post moved back to draft.');
    }

    /** POST /api/v1/feed/{id}/pin — جامعة بس. */
    public function pin(Request $request, $id)
    {
        return $this->setPinned($request, $id, true);
    }

    /** POST /api/v1/feed/{id}/unpin — جامعة بس. */
    public function unpin(Request $request, $id)
    {
        return $this->setPinned($request, $id, false);
    }

    private function setPinned(Request $request, $id, bool $pinned)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can pin a post.', null, 403);
        }

        $userId = $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);

        try {
            $this->feed->setPinned((int) $id, (int) $university->id, $pinned, $userId);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 404);
        }

        return $this->apiSuccess(null, $pinned ? 'Post pinned.' : 'Post unpinned.');
    }

    // -- Student: interactions -------------------------------------------------

    /** POST /api/v1/feed/{id}/like — طالب بس. */
    public function like(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'student') {
            return $this->apiError('Only student accounts can like a feed post.', null, 403);
        }

        $liked = $this->feed->toggleLike((int) $id, $request->attributes->get('uip_user_id'));
        return $this->apiSuccess(['liked' => $liked], $liked ? 'Post liked.' : 'Like removed.');
    }

    /** POST /api/v1/feed/{id}/save — طالب بس. */
    public function save(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'student') {
            return $this->apiError('Only student accounts can save a feed post.', null, 403);
        }

        $saved = $this->feed->toggleSave((int) $id, $request->attributes->get('uip_user_id'));
        return $this->apiSuccess(['saved' => $saved], $saved ? 'Post saved.' : 'Save removed.');
    }

    /** GET /api/v1/feed/{id}/comments — جامعة أو طالب. */
    public function comments(Request $request, $id)
    {
        $universityId = $this->resolveUniversityId($request);
        if ($universityId === null) {
            return $this->apiError('Only university or affiliated student accounts can view comments.', null, 403);
        }

        $post = $this->feedRepo->findOwnedByUniversity((int) $id, $universityId);
        if (!$post) {
            return $this->apiError('Post not found.', null, 404);
        }

        return $this->apiSuccess($this->feedRepo->commentsForPost((int) $post['id']), 'Comments retrieved successfully.');
    }

    /** POST /api/v1/feed/{id}/comments — طالب بس. feed.comment_rate_limit إضافي على الروت ده (routes/api.php). */
    public function comment(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'student') {
            return $this->apiError('Only student accounts can comment on a feed post.', null, 403);
        }

        try {
            $commentId = $this->feed->addComment((int) $id, $request->attributes->get('uip_user_id'), (string) $request->input('body', ''));
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess(['id' => $commentId], 'Comment added successfully.', 201);
    }

    /** DELETE /api/v1/feed/comments/{commentId} — بس صاحب التعليق نفسه. طالب بس. */
    public function deleteComment(Request $request, $commentId)
    {
        if ($request->attributes->get('uip_role') !== 'student') {
            return $this->apiError('Only student accounts can delete their own comment.', null, 403);
        }

        try {
            $this->feed->deleteComment((int) $commentId, $request->attributes->get('uip_user_id'));
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess(null, 'Comment deleted successfully.');
    }

    /** POST /api/v1/feed/{id}/share — بيشارك البوست جوه inbox مستلم عبر الـ messaging. طالب بس. */
    public function share(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'student') {
            return $this->apiError('Only student accounts can share a feed post.', null, 403);
        }

        $id = (int) $id;
        $userId = $request->attributes->get('uip_user_id');
        $universityId = $this->feed->universityIdForStudent($userId);
        $post = $universityId ? $this->feedRepo->findOwnedByUniversity($id, $universityId) : null;
        if (!$post) {
            return $this->apiError('Post not found.', null, 404);
        }

        try {
            $this->feed->share(
                $id,
                $userId,
                (string) $request->input('recipient_email', ''),
                $request->input('note'),
                $post
            );
        } catch (\Throwable $e) {
            return $this->apiError('Could not share: ' . $e->getMessage(), null, 422);
        }

        return $this->apiSuccess(null, 'Post shared successfully.');
    }

    /** POST /api/v1/feed/{id}/report — بيبلّغ عن بوست عشان الجامعة تراجعه. طالب بس. */
    public function report(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'student') {
            return $this->apiError('Only student accounts can report a feed post.', null, 403);
        }

        try {
            $this->feed->report((int) $id, $request->attributes->get('uip_user_id'), (string) $request->input('reason', ''));
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess(null, 'Thanks — the university has been notified and will review this post.');
    }

    // -- University: moderation -------------------------------------------------

    /** GET /api/v1/feed/reports — طابور المراجعة بتاع الجامعة. جامعة بس. */
    public function reports(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can view the moderation queue.', null, 403);
        }

        $university = $this->universities->getOrCreate($request->attributes->get('uip_user_id'));
        return $this->apiSuccess($this->feed->reportsForUniversity((int) $university->id), 'Reports retrieved successfully.');
    }

    /** POST /api/v1/feed/reports/{id}/resolve — 'dismiss' أو 'takedown'. جامعة بس. */
    public function resolveReport(Request $request, $id)
    {
        if ($request->attributes->get('uip_role') !== 'university') {
            return $this->apiError('Only university accounts can resolve a report.', null, 403);
        }

        $userId = $request->attributes->get('uip_user_id');
        $university = $this->universities->getOrCreate($userId);

        try {
            $this->feed->moderateReport(
                (int) $id,
                (int) $university->id,
                (string) $request->input('action', ''),
                $request->input('notes'),
                $userId
            );
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess(null, 'Report resolved successfully.');
    }

    // -- helpers --------------------------------------------------------------

    private function resolveUniversityId(Request $request): ?int
    {
        $role = $request->attributes->get('uip_role');
        $userId = $request->attributes->get('uip_user_id');

        if ($role === 'university') {
            return (int) $this->universities->getOrCreate($userId)->id;
        }
        if ($role === 'student') {
            return $this->feed->universityIdForStudent($userId);
        }
        return null;
    }
}
