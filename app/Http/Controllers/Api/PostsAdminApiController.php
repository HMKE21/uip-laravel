<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PostModerationService;
use App\Services\PostSettings;
use Illuminate\Http\Request;

/**
 * /api/v1/posts-admin/* — لوحة أدمن المنشورات: إعدادات التشغيل (حدود + أوزان الخوارزمية)، البلاغات، المنشورات المعلّقة، إحصائيات.
 * الصلاحية posts.moderate متطبقة على المجموعة في routes/api.php.
 */
class PostsAdminApiController extends Controller
{
    public function __construct(private PostModerationService $moderation, private PostSettings $settings)
    {
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
            return $this->apiError($e->getMessage(), null, str_ends_with($e->getMessage(), 'not found.') ? 404 : 400);
        }
    }

    public function stats()
    {
        return $this->apiSuccess($this->moderation->stats(), 'Posts statistics retrieved successfully.');
    }

    /** GET /posts-admin/settings */
    public function settings()
    {
        return $this->apiSuccess(['settings' => PostSettings::all(), 'groups' => PostSettings::GROUPS], 'Posts settings retrieved successfully.');
    }

    /** PUT /posts-admin/settings  body: { key: value, ... } */
    public function updateSettings(Request $request)
    {
        return $this->run(function () use ($request) {
            $saved = $this->settings->update((array) $request->input('settings', $request->except(['_token'])));
            return $this->apiSuccess(['saved' => $saved, 'settings' => PostSettings::all()], 'Posts settings saved successfully.');
        });
    }

    /** GET /posts-admin/reports?status=pending|resolved|dismissed|all&page&per_page */
    public function reports(Request $request)
    {
        return $this->run(function () use ($request) {
            $res = $this->moderation->reports((string) $request->input('status', 'pending'), max(1, (int) $request->input('page', 1)), (int) $request->input('per_page', 20));
            return $this->apiSuccess($res['items'], 'Reports retrieved successfully.', 200, ['has_more' => $res['has_more']]);
        });
    }

    /** POST /posts-admin/reports/{id}/resolve  body: action = dismiss | remove_post | remove_comment */
    public function resolveReport(Request $request, $id)
    {
        return $this->run(function () use ($request, $id) {
            $this->moderation->resolve($this->uid($request), (int) $id, (string) $request->input('action', ''));
            return $this->apiSuccess(null, 'Report resolved.');
        });
    }

    /** GET /posts-admin/pending */
    public function pending(Request $request)
    {
        return $this->run(function () use ($request) {
            $res = $this->moderation->pending($this->uid($request), max(1, (int) $request->input('page', 1)), (int) $request->input('per_page', 20));
            return $this->apiSuccess($res['items'], 'Pending posts retrieved successfully.', 200, ['has_more' => $res['has_more']]);
        });
    }

    public function approve(Request $request, $id)
    {
        return $this->run(function () use ($request, $id) {
            $this->moderation->approve($this->uid($request), (int) $id);
            return $this->apiSuccess(null, 'Post approved.');
        });
    }

    public function reject(Request $request, $id)
    {
        return $this->run(function () use ($request, $id) {
            $this->moderation->reject($this->uid($request), (int) $id, $request->input('reason'));
            return $this->apiSuccess(null, 'Post rejected.');
        });
    }
}
