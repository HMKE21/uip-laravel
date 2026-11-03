<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PostFeedService;
use App\Services\PostFollowService;
use App\Services\PostInteractionService;
use App\Services\PostService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * /api/v1/posts/* — نظام المنشورات الاجتماعي (منفصل عن /api/v1/feed بتاع University Feed).
 * المستخدم الفاعل دايمًا من uip_user_id (uip.auth)، والصلاحية posts.use متطبقة على المجموعة كلها في routes/api.php.
 * كل خطأ بيرميه الـ services كـ RuntimeException بيتحوّل لـ 400 (أو 404 لو "... not found.") برسالة آمنة للعرض.
 */
class PostsApiController extends Controller
{
    private const CONTENT_KEYS = [
        'title', 'body', 'link_url', 'visibility', 'comments_mode', 'allow_repost', 'allow_download', 'allow_run', 'hide_counts', 'is_sensitive',
        'draft', 'status', 'scheduled_at', 'expires_in_hours', 'tags', 'mentioned_user_ids', 'audience_ids', 'media_ids', 'media_details', 'subtype',
    ];

    public function __construct(
        private PostService $posts,
        private PostFeedService $feed,
        private PostInteractionService $act,
        private PostFollowService $follows
    ) {
    }

    private function uid(Request $request): int
    {
        return (int) $request->attributes->get('uip_user_id');
    }

    private function run(\Closure $fn)
    {
        try {
            return $fn();
        } catch (\RuntimeException $e) {
            $notFound = str_ends_with($e->getMessage(), 'not found.');
            return $this->apiError($e->getMessage(), null, $notFound ? 404 : 400);
        } catch (\Illuminate\Database\QueryException $e) {
            Log::error('Posts DB error: ' . $e->getMessage());
            return $this->apiError('Server Error', null, 500);
        }
    }

    // ---------------------------------------------------------------- config / feed

    /** GET /posts/config — الحدود والقوائم اللي الواجهة بتلتزم بيها. */
    public function config(Request $request)
    {
        return $this->run(fn () => $this->apiSuccess($this->posts->config($this->uid($request)), 'Posts configuration retrieved successfully.'));
    }

    /** GET /posts/feed?mode=for_you|following|latest|trending|university|saved|user|tag|search|mine&page&per_page&q&tag&user_id&status&refresh */
    public function feed(Request $request)
    {
        return $this->run(function () use ($request) {
            $res = $this->feed->feed($this->uid($request), $request->only(['mode', 'page', 'per_page', 'q', 'tag', 'user_id', 'status', 'refresh', 'collection']));
            return $this->apiSuccess($res['items'], 'Posts retrieved successfully.', 200, $res['meta']);
        });
    }

    /** GET /posts/tags/trending */
    public function trendingTags(Request $request)
    {
        return $this->run(fn () => $this->apiSuccess($this->feed->trendingTags($this->uid($request), (int) $request->input('limit', 12)), 'Tags retrieved successfully.'));
    }

    /** GET /posts/tags/suggest?q= */
    public function suggestTags(Request $request)
    {
        return $this->run(fn () => $this->apiSuccess($this->feed->suggestTags($this->uid($request), (string) $request->input('q', '')), 'Tags retrieved successfully.'));
    }

    // ---------------------------------------------------------------- CRUD / lifecycle

    /** GET /posts/{id} */
    public function show(Request $request, $id)
    {
        return $this->run(function () use ($request, $id) {
            $post = $this->posts->one($this->uid($request), (int) $id);
            return $post ? $this->apiSuccess($post, 'Post retrieved successfully.') : $this->apiError('Post not found.', null, 404);
        });
    }

    /** POST /posts */
    public function store(Request $request)
    {
        return $this->run(fn () => $this->apiSuccess($this->posts->create($this->uid($request), $request->only(self::CONTENT_KEYS)), 'Post created successfully.', 201));
    }

    /** PATCH /posts/{id} */
    public function update(Request $request, $id)
    {
        return $this->run(fn () => $this->apiSuccess($this->posts->update($this->uid($request), (int) $id, $request->only(array_merge(self::CONTENT_KEYS, ['scheduled_at']))), 'Post updated successfully.'));
    }

    /** DELETE /posts/{id} */
    public function destroy(Request $request, $id)
    {
        return $this->run(function () use ($request, $id) {
            $this->posts->delete($this->uid($request), (int) $id, $request->input('reason'));
            return $this->apiSuccess(null, 'Post deleted successfully.');
        });
    }

    public function publish(Request $request, $id)
    {
        return $this->run(fn () => $this->apiSuccess($this->posts->publish($this->uid($request), (int) $id), 'Post published successfully.'));
    }

    public function archive(Request $request, $id)
    {
        return $this->run(fn () => $this->apiSuccess($this->posts->archive($this->uid($request), (int) $id), 'Post archived successfully.'));
    }

    public function unarchive(Request $request, $id)
    {
        return $this->run(fn () => $this->apiSuccess($this->posts->unarchive($this->uid($request), (int) $id), 'Post restored successfully.'));
    }

    public function pin(Request $request, $id)
    {
        return $this->run(fn () => $this->apiSuccess($this->posts->pin($this->uid($request), (int) $id, true), 'Post pinned successfully.'));
    }

    public function unpin(Request $request, $id)
    {
        return $this->run(fn () => $this->apiSuccess($this->posts->pin($this->uid($request), (int) $id, false), 'Post unpinned successfully.'));
    }

    // ---------------------------------------------------------------- interactions

    /** POST /posts/{id}/react  body: type (like|love|...) — null/فاضي = شيل التفاعل */
    public function react(Request $request, $id)
    {
        return $this->run(function () use ($request, $id) {
            $type = $request->filled('type') ? (string) $request->input('type') : null;
            return $this->apiSuccess($this->act->react($this->uid($request), (int) $id, $type), 'Reaction saved.');
        });
    }

    /** GET /posts/{id}/reactions?type&page&per_page */
    public function reactors(Request $request, $id)
    {
        return $this->run(function () use ($request, $id) {
            $per = max(1, min(50, (int) $request->input('per_page', 30)));
            return $this->apiSuccess($this->act->reactors($this->uid($request), (int) $id, $request->input('type'), max(1, (int) $request->input('page', 1)), $per), 'Reactions retrieved successfully.');
        });
    }

    /** GET /posts/{id}/shares?kind=all|share|repost&page&per_page — مين شارك / أعاد النشر */
    public function sharers(Request $request, $id)
    {
        return $this->run(function () use ($request, $id) {
            $per = max(1, min(50, (int) $request->input('per_page', 30)));
            $kind = in_array($request->input('kind'), ['share', 'repost'], true) ? (string) $request->input('kind') : 'all';
            return $this->apiSuccess($this->act->sharers($this->uid($request), (int) $id, $kind, max(1, (int) $request->input('page', 1)), $per), 'Shares retrieved successfully.');
        });
    }

    /** GET /posts/{id}/viewers?page&per_page — مين شاف المنشور (المالك/المشرف فقط) */
    public function viewers(Request $request, $id)
    {
        return $this->run(function () use ($request, $id) {
            $per = max(1, min(50, (int) $request->input('per_page', 30)));
            return $this->apiSuccess($this->act->viewers($this->uid($request), (int) $id, max(1, (int) $request->input('page', 1)), $per), 'Viewers retrieved successfully.');
        });
    }

    public function save(Request $request, $id)
    {
        return $this->run(function () use ($request, $id) {
            $action = in_array($request->input('action'), ['save', 'unsave'], true) ? $request->input('action') : null;
            $cid = $request->filled('collection_id') ? (int) $request->input('collection_id') : null;
            return $this->apiSuccess($this->act->toggleSave($this->uid($request), (int) $id, $action, $cid), 'Saved state updated.');
        });
    }

    /** GET /posts/collections */
    public function collections(Request $request)
    {
        return $this->run(fn () => $this->apiSuccess($this->act->collections($this->uid($request)), 'Collections retrieved successfully.'));
    }

    /** POST /posts/collections  body: name */
    public function createCollection(Request $request)
    {
        return $this->run(fn () => $this->apiSuccess($this->act->createCollection($this->uid($request), (string) $request->input('name', '')), 'Collection created.', 201));
    }

    /** PATCH /posts/collections/{id}  body: name */
    public function renameCollection(Request $request, $id)
    {
        return $this->run(fn () => $this->apiSuccess($this->act->renameCollection($this->uid($request), (int) $id, (string) $request->input('name', '')), 'Collection renamed.'));
    }

    /** DELETE /posts/collections/{id} — المنشورات بترجع بدون مجلد */
    public function deleteCollection(Request $request, $id)
    {
        return $this->run(function () use ($request, $id) {
            $this->act->deleteCollection($this->uid($request), (int) $id);
            return $this->apiSuccess(null, 'Collection deleted.');
        });
    }

    /** POST /posts/{id}/repost  body: body (تعليق اختياري) */
    public function repost(Request $request, $id)
    {
        return $this->run(fn () => $this->apiSuccess($this->act->repost($this->uid($request), (int) $id, $request->input('body')), 'Post reposted successfully.', 201));
    }

    /** POST /posts/{id}/share  body: channel (link|chat|external) */
    public function share(Request $request, $id)
    {
        return $this->run(fn () => $this->apiSuccess($this->act->share($this->uid($request), (int) $id, (string) $request->input('channel', 'link')), 'Share recorded.'));
    }

    /** POST /posts/{id}/view  body: dwell_ms, completed */
    public function view(Request $request, $id)
    {
        return $this->run(function () use ($request, $id) {
            $this->act->trackView($this->uid($request), (int) $id, (int) $request->input('dwell_ms', 0), $request->boolean('completed'));
            return $this->apiSuccess(null, 'View recorded.');
        });
    }

    /** POST /posts/{id}/hide  body: reason */
    public function hide(Request $request, $id)
    {
        return $this->run(function () use ($request, $id) {
            $this->act->hide($this->uid($request), (int) $id, $request->input('reason'));
            return $this->apiSuccess(null, 'We will show you fewer posts like this.');
        });
    }

    public function unhide(Request $request, $id)
    {
        return $this->run(function () use ($request, $id) {
            $this->act->unhide($this->uid($request), (int) $id);
            return $this->apiSuccess(null, 'Post restored to your feed.');
        });
    }

    /** POST /posts/{id}/report  body: reason, details, comment_id */
    public function report(Request $request, $id)
    {
        return $this->run(function () use ($request, $id) {
            $this->act->report($this->uid($request), (int) $id, (string) $request->input('reason', ''), $request->input('details'), $request->filled('comment_id') ? (int) $request->input('comment_id') : null);
            return $this->apiSuccess(null, 'Thanks — your report was sent to the moderators.', 201);
        });
    }

    // ---------------------------------------------------------------- comments

    /** GET /posts/{id}/comments?sort=new|top&page&per_page */
    public function comments(Request $request, $id)
    {
        return $this->run(function () use ($request, $id) {
            $per = max(1, min(30, (int) $request->input('per_page', 10)));
            $res = $this->act->comments($this->uid($request), (int) $id, $request->input('sort') === 'top' ? 'top' : 'new', max(1, (int) $request->input('page', 1)), $per);
            return $this->apiSuccess($res['items'], 'Comments retrieved successfully.', 200, ['has_more' => $res['has_more']]);
        });
    }

    /** GET /posts/comments/{commentId}/replies?page&per_page */
    public function replies(Request $request, $commentId)
    {
        return $this->run(function () use ($request, $commentId) {
            $per = max(1, min(30, (int) $request->input('per_page', 10)));
            $res = $this->act->replies($this->uid($request), (int) $commentId, max(1, (int) $request->input('page', 1)), $per);
            return $this->apiSuccess($res['items'], 'Replies retrieved successfully.', 200, ['has_more' => $res['has_more']]);
        });
    }

    /** POST /posts/{id}/comments  body: body, parent_id */
    public function addComment(Request $request, $id)
    {
        return $this->run(fn () => $this->apiSuccess(
            $this->act->addComment($this->uid($request), (int) $id, (string) $request->input('body', ''), $request->filled('parent_id') ? (int) $request->input('parent_id') : null),
            'Comment added successfully.',
            201
        ));
    }

    public function editComment(Request $request, $commentId)
    {
        return $this->run(fn () => $this->apiSuccess($this->act->editComment($this->uid($request), (int) $commentId, (string) $request->input('body', '')), 'Comment updated successfully.'));
    }

    public function deleteComment(Request $request, $commentId)
    {
        return $this->run(function () use ($request, $commentId) {
            $this->act->deleteComment($this->uid($request), (int) $commentId);
            return $this->apiSuccess(null, 'Comment deleted successfully.');
        });
    }

    /** POST /posts/comments/{commentId}/react  body: type (like|love|…) — فاضي = شيل التفاعل */
    public function reactComment(Request $request, $commentId)
    {
        return $this->run(function () use ($request, $commentId) {
            $type = $request->filled('type') ? (string) $request->input('type') : null;
            return $this->apiSuccess($this->act->reactComment($this->uid($request), (int) $commentId, $type), 'Reaction saved.');
        });
    }

    public function likeComment(Request $request, $commentId)
    {
        return $this->run(fn () => $this->apiSuccess($this->act->toggleCommentLike($this->uid($request), (int) $commentId), 'Comment like updated.'));
    }

    // ---------------------------------------------------------------- people (follow / mute / profile)

    /** GET /posts/profile/{userId} */
    public function profile(Request $request, $userId)
    {
        return $this->run(fn () => $this->apiSuccess($this->follows->profile($this->uid($request), (int) $userId), 'Profile retrieved successfully.'));
    }

    public function follow(Request $request, $userId)
    {
        return $this->run(fn () => $this->apiSuccess($this->follows->follow($this->uid($request), (int) $userId), 'Followed.'));
    }

    public function unfollow(Request $request, $userId)
    {
        return $this->run(fn () => $this->apiSuccess($this->follows->unfollow($this->uid($request), (int) $userId), 'Unfollowed.'));
    }

    public function followers(Request $request, $userId)
    {
        return $this->run(function () use ($request, $userId) {
            $res = $this->follows->followers($this->uid($request), (int) $userId, max(1, (int) $request->input('page', 1)), (int) $request->input('per_page', 30));
            return $this->apiSuccess($res['items'], 'Followers retrieved successfully.', 200, ['has_more' => $res['has_more'], 'restricted' => !empty($res['restricted'])]);
        });
    }

    public function following(Request $request, $userId)
    {
        return $this->run(function () use ($request, $userId) {
            $res = $this->follows->following($this->uid($request), (int) $userId, max(1, (int) $request->input('page', 1)), (int) $request->input('per_page', 30));
            return $this->apiSuccess($res['items'], 'Following retrieved successfully.', 200, ['has_more' => $res['has_more'], 'restricted' => !empty($res['restricted'])]);
        });
    }

    /** GET /posts/people/suggestions */
    public function suggestions(Request $request)
    {
        return $this->run(fn () => $this->apiSuccess($this->follows->suggestions($this->uid($request), (int) $request->input('limit', 10)), 'Suggestions retrieved successfully.'));
    }

    /** POST /posts/authors/{userId}/mute — مايظهرش منشوراته في الفيد (من غير ما يعرف). DELETE بيلغيه. */
    public function muteAuthor(Request $request, $userId)
    {
        return $this->run(function () use ($request, $userId) {
            $this->act->muteAuthor($this->uid($request), (int) $userId, true);
            return $this->apiSuccess(null, 'Muted.');
        });
    }

    public function unmuteAuthor(Request $request, $userId)
    {
        return $this->run(function () use ($request, $userId) {
            $this->act->muteAuthor($this->uid($request), (int) $userId, false);
            return $this->apiSuccess(null, 'Unmuted.');
        });
    }
}
