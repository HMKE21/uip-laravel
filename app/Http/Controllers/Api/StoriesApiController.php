<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StoryService;
use Illuminate\Http\Request;

/**
 * /api/v1/messaging/stories/* — الاستوري (Status). المستخدم الفاعل دايمًا من uip_user_id (uip.auth)،
 * والصلاحية messaging.use متطبقة على المجموعة كلها في routes/api.php.
 */
class StoriesApiController extends Controller
{
    public function __construct(private StoryService $stories)
    {
    }

    private function uid(Request $request): int
    {
        return (int) $request->attributes->get('uip_user_id');
    }

    /** GET /stories — الفيد: { me, groups[], reactions[], limits } */
    public function index(Request $request)
    {
        return $this->handle(fn () => $this->apiSuccess($this->stories->feed($this->uid($request)), 'Stories retrieved successfully.'));
    }

    /** POST /stories — multipart: type, body|media, caption, bg, font, audience, audience_ids[], expires_hours, allow_replies */
    public function store(Request $request)
    {
        return $this->handle(function () use ($request) {
            $story = $this->stories->create($this->uid($request), $request->only([
                'type', 'body', 'caption', 'bg', 'font', 'audience', 'audience_ids', 'expires_hours', 'allow_replies',
            ]), $request->file('media'));
            return $this->apiSuccess($story, 'Story posted successfully.', 201);
        });
    }

    /** GET /stories/contacts — query: q (اختياري) */
    public function contacts(Request $request)
    {
        return $this->handle(fn () => $this->apiSuccess($this->stories->pickablePeople($this->uid($request), (string) $request->input('q', '')), 'People retrieved successfully.'));
    }

    /** DELETE /stories/{id} */
    public function destroy(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $this->stories->delete($this->uid($request), (int) $id);
            return $this->apiSuccess(null, 'Story deleted successfully.');
        });
    }

    /** POST /stories/{id}/view */
    public function view(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $this->stories->markViewed($this->uid($request), (int) $id);
            return $this->apiSuccess(null, 'Story marked as viewed.');
        });
    }

    /** GET /stories/{id}/viewers — صاحب الاستوري بس */
    public function viewers(Request $request, $id)
    {
        return $this->handle(fn () => $this->apiSuccess($this->stories->viewers($this->uid($request), (int) $id), 'Viewers retrieved successfully.'));
    }

    /** POST /stories/{id}/react — body: emoji (فاضي = شيل التفاعل) */
    public function react(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $r = $this->stories->react($this->uid($request), (int) $id, $request->input('emoji'));
            return $this->apiSuccess(['reaction' => $r], 'Reaction saved.');
        });
    }

    /** POST /stories/{id}/reply — body: body */
    public function reply(Request $request, $id)
    {
        return $this->handle(fn () => $this->apiSuccess($this->stories->reply($this->uid($request), (int) $id, (string) $request->input('body', '')), 'Reply sent.', 201));
    }

    /** POST /stories/mute/{userId} = كتم، DELETE = إلغاء الكتم */
    public function mute(Request $request, $userId)
    {
        return $this->handle(function () use ($request, $userId) {
            $this->stories->setMuted($this->uid($request), (int) $userId, !$request->isMethod('delete'));
            return $this->apiSuccess(null, $request->isMethod('delete') ? 'Unmuted.' : 'Muted.');
        });
    }

    private function handle(\Closure $action)
    {
        try {
            return $action();
        } catch (\Illuminate\Database\QueryException $e) {
            \Illuminate\Support\Facades\Log::error('Stories DB error: ' . $e->getMessage());
            return $this->apiError('Server Error', ['db' => [(string) ($e->errorInfo[2] ?? 'database error')]], 500);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }
    }
}
