<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NetworkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * /api/v1/network/* — "شبكتي": الاتصالات، طلبات الاتصال والمتابعة (قبول/رفض)، المتابِعون/المتابَعون، البحث عن الناس والاقتراحات.
 * المستخدم الفاعل دايمًا من uip_user_id (uip.auth) والصلاحية posts.use على المجموعة كلها. المنطق كله في NetworkService.
 */
class NetworkApiController extends Controller
{
    public function __construct(private NetworkService $network)
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
            $notFound = str_ends_with($e->getMessage(), 'not found.');
            return $this->apiError($e->getMessage(), null, $notFound ? 404 : 400);
        } catch (\Illuminate\Database\QueryException $e) {
            Log::error('Network DB error: ' . $e->getMessage());
            return $this->apiError('Server Error', null, 500);
        }
    }

    private function page(Request $request): array
    {
        return [max(1, (int) $request->input('page', 1)), max(1, min(50, (int) $request->input('per_page', 20)))];
    }

    private function list(array $res, string $msg)
    {
        return $this->apiSuccess($res['items'], $msg, 200, ['has_more' => $res['has_more'], 'restricted' => !empty($res['restricted'])]);
    }

    /** GET /network/summary — عدّادات + إعدادات الشبكة */
    public function summary(Request $request)
    {
        return $this->run(fn () => $this->apiSuccess($this->network->summary($this->uid($request)), 'Network summary retrieved successfully.'));
    }

    /** GET /network/settings, PATCH /network/settings {follow_approval?, accept_connection_requests?} */
    public function settings(Request $request)
    {
        return $this->run(fn () => $this->apiSuccess($this->network->settings($this->uid($request)), 'Network settings retrieved successfully.'));
    }

    public function saveSettings(Request $request)
    {
        return $this->run(fn () => $this->apiSuccess($this->network->saveSettings($this->uid($request), $request->only(['follow_approval', 'accept_connection_requests', 'profile_locked', 'lists_visibility', 'photo_download'])), 'Network settings saved successfully.'));
    }

    /** GET /network/connections?q&page&per_page */
    public function connections(Request $request)
    {
        return $this->run(function () use ($request) {
            [$page, $per] = $this->page($request);
            return $this->list($this->network->connections($this->uid($request), (string) $request->input('q', ''), $page, $per), 'Connections retrieved successfully.');
        });
    }

    /** GET /network/requests?direction=received|sent */
    public function requests(Request $request)
    {
        return $this->run(function () use ($request) {
            [$page, $per] = $this->page($request);
            $dir = $request->input('direction') === 'sent' ? 'sent' : 'received';
            return $this->list($this->network->requests($this->uid($request), $dir, $page, $per), 'Requests retrieved successfully.');
        });
    }

    /** GET /network/follow-requests — طلبات متابعة معلّقة وصلتني */
    public function followRequests(Request $request)
    {
        return $this->run(function () use ($request) {
            [$page, $per] = $this->page($request);
            return $this->list($this->network->followRequests($this->uid($request), $page, $per), 'Follow requests retrieved successfully.');
        });
    }

    /** GET /network/followers?q  |  GET /network/following?q */
    public function followers(Request $request)
    {
        return $this->run(function () use ($request) {
            [$page, $per] = $this->page($request);
            return $this->list($this->network->followers($this->uid($request), (string) $request->input('q', ''), $page, $per), 'Followers retrieved successfully.');
        });
    }

    public function following(Request $request)
    {
        return $this->run(function () use ($request) {
            [$page, $per] = $this->page($request);
            return $this->list($this->network->following($this->uid($request), (string) $request->input('q', ''), $page, $per), 'Following retrieved successfully.');
        });
    }

    /** GET /network/search?q&role&same_university&relation=all|connections|following|followers */
    public function search(Request $request)
    {
        return $this->run(function () use ($request) {
            [$page, $per] = $this->page($request);
            $filters = [
                'role'            => (string) $request->input('role', ''),
                'same_university' => filter_var($request->input('same_university', false), FILTER_VALIDATE_BOOLEAN),
                'relation'        => in_array($request->input('relation'), ['connections', 'following', 'followers'], true) ? (string) $request->input('relation') : 'all',
            ];
            return $this->list($this->network->search($this->uid($request), (string) $request->input('q', ''), $filters, $page, $per), 'People retrieved successfully.');
        });
    }

    /** GET /network/suggestions?limit */
    public function suggestions(Request $request)
    {
        return $this->run(fn () => $this->apiSuccess($this->network->suggestions($this->uid($request), (int) $request->input('limit', 12)), 'Suggestions retrieved successfully.'));
    }

    /** GET /network/people/{userId} */
    public function person(Request $request, $userId)
    {
        return $this->run(fn () => $this->apiSuccess($this->network->person($this->uid($request), (int) $userId), 'Person retrieved successfully.'));
    }

    /** GET /network/people/{userId}/connections — أصدقاء الشخص ده */
    public function friends(Request $request, $userId)
    {
        [$page, $per] = $this->page($request);
        return $this->run(fn () => $this->list($this->network->friendsOf($this->uid($request), (int) $userId, (string) $request->input('q', ''), $page, $per), 'Connections retrieved successfully.'));
    }

    /** POST /network/me/cover (multipart: cover) */
    public function setCover(Request $request)
    {
        return $this->run(function () use ($request) {
            $file = $request->file('cover');
            if (!$file) {
                return $this->apiError('cover file is required.', null, 422);
            }
            return $this->apiSuccess(['cover_path' => $this->network->setCover($this->uid($request), $file)], 'Cover photo updated successfully.');
        });
    }

    /** DELETE /network/me/cover */
    public function removeCover(Request $request)
    {
        return $this->run(function () use ($request) {
            $this->network->removeCover($this->uid($request));
            return $this->apiSuccess(['cover_path' => null], 'Cover photo removed.');
        });
    }

    /** POST /network/connect/{userId} {message?} */
    public function connect(Request $request, $userId)
    {
        return $this->run(fn () => $this->apiSuccess($this->network->connect($this->uid($request), (int) $userId, $request->filled('message') ? (string) $request->input('message') : null), 'Connection request sent.'));
    }

    /** POST /network/requests/{id}/accept | /decline ; DELETE /network/requests/{id} (سحب طلب أرسلته) */
    public function accept(Request $request, $id)
    {
        return $this->run(fn () => $this->apiSuccess($this->network->accept($this->uid($request), (int) $id), 'Request accepted.'));
    }

    public function decline(Request $request, $id)
    {
        return $this->run(fn () => $this->apiSuccess($this->network->decline($this->uid($request), (int) $id), 'Request declined.'));
    }

    public function withdraw(Request $request, $id)
    {
        return $this->run(fn () => $this->apiSuccess($this->network->withdraw($this->uid($request), (int) $id), 'Request withdrawn.'));
    }

    /** DELETE /network/connections/{userId} */
    public function removeConnection(Request $request, $userId)
    {
        return $this->run(fn () => $this->apiSuccess($this->network->removeConnection($this->uid($request), (int) $userId), 'Connection removed.'));
    }

    /** POST /network/follow-requests/{userId}/accept | /decline ; DELETE /network/followers/{userId} */
    public function acceptFollow(Request $request, $userId)
    {
        return $this->run(fn () => $this->apiSuccess($this->network->acceptFollow($this->uid($request), (int) $userId), 'Follow request accepted.'));
    }

    public function declineFollow(Request $request, $userId)
    {
        return $this->run(fn () => $this->apiSuccess($this->network->declineFollow($this->uid($request), (int) $userId), 'Follow request declined.'));
    }

    public function removeFollower(Request $request, $userId)
    {
        return $this->run(fn () => $this->apiSuccess($this->network->removeFollower($this->uid($request), (int) $userId), 'Follower removed.'));
    }
}
