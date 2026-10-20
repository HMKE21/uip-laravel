<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GroupChatService;
use App\Services\MessagingService;
use Illuminate\Http\Request;

/**
 * /api/v1/messaging/... — WhatsApp-style group administration (settings, invite link, join requests,
 * member control). Same conventions as MessagingController: actor = uip_user_id, RuntimeException => 422.
 */
class GroupChatController extends Controller
{
    public function __construct(private GroupChatService $groups, private MessagingService $messaging)
    {
    }

    private function uid(Request $request): int
    {
        return (int) $request->attributes->get('uip_user_id');
    }

    private function handle(\Closure $action)
    {
        try {
            return $action();
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }
    }

    private function thread(Request $request, int $id): array
    {
        return $this->messaging->threadForUser($id, $this->uid($request)) ?? [];
    }

    /** PATCH /conversations/{id}/group — body: name?, description?, only_admins_send?, members_can_edit_info?, members_can_add?, join_approval?, allowed_types[]?, invite_enabled?, max_members? */
    public function update(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $keys = ['name', 'description', 'only_admins_send', 'members_can_edit_info', 'members_can_add', 'join_approval', 'allowed_types', 'invite_enabled', 'max_members'];
            $input = [];
            foreach ($keys as $k) {
                if ($request->has($k)) {
                    $input[$k] = $request->input($k);
                }
            }
            $this->groups->update((int) $id, $this->uid($request), $input);
            return $this->apiSuccess($this->thread($request, (int) $id), 'Group updated successfully.');
        });
    }

    /** POST /conversations/{id}/group/avatar — multipart: avatar */
    public function setAvatar(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $this->groups->setAvatar((int) $id, $this->uid($request), $request->file('avatar'));
            return $this->apiSuccess($this->thread($request, (int) $id), 'Group photo updated successfully.');
        });
    }

    /** DELETE /conversations/{id}/group/avatar */
    public function removeAvatar(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $this->groups->removeAvatar((int) $id, $this->uid($request));
            return $this->apiSuccess($this->thread($request, (int) $id), 'Group photo removed successfully.');
        });
    }

    /** POST /conversations/{id}/members/bulk — body: user_ids[] */
    public function addMembers(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $added = $this->groups->addMembers((int) $id, $this->uid($request), (array) $request->input('user_ids', []));
            return $this->apiSuccess(['added' => $added], $added ? 'Members added successfully.' : 'No new members were added.');
        });
    }

    /** POST /conversations/{id}/members/{userId}/restrict — body: value (bool, default true) */
    public function restrict(Request $request, $id, $userId)
    {
        return $this->handle(function () use ($request, $id, $userId) {
            $this->groups->setRestricted((int) $id, $this->uid($request), (int) $userId, filter_var($request->input('value', true), FILTER_VALIDATE_BOOLEAN));
            return $this->apiSuccess(null, 'Member updated successfully.');
        });
    }

    /** POST /conversations/{id}/transfer-owner — body: user_id */
    public function transferOwner(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $this->groups->transferOwnership((int) $id, $this->uid($request), (int) $request->input('user_id'));
            return $this->apiSuccess($this->thread($request, (int) $id), 'Ownership transferred successfully.');
        });
    }

    /** POST /conversations/{id}/leave */
    public function leave(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $deleted = $this->groups->leave((int) $id, $this->uid($request));
            return $this->apiSuccess(['deleted' => $deleted], 'You left the group.');
        });
    }

    /** DELETE /conversations/{id}/group — owner only */
    public function destroy(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $this->groups->deleteGroup((int) $id, $this->uid($request));
            return $this->apiSuccess(null, 'Group deleted successfully.');
        });
    }

    /** GET /conversations/{id}/invite */
    public function invite(Request $request, $id)
    {
        return $this->handle(fn () => $this->apiSuccess($this->groups->inviteLink((int) $id, $this->uid($request)), 'Invite link retrieved successfully.'));
    }

    /** POST /conversations/{id}/invite/reset */
    public function resetInvite(Request $request, $id)
    {
        return $this->handle(fn () => $this->apiSuccess($this->groups->resetInviteLink((int) $id, $this->uid($request)), 'Invite link reset successfully.'));
    }

    /** GET /groups/invite/{token} — preview before joining */
    public function previewInvite(Request $request, $token)
    {
        return $this->handle(fn () => $this->apiSuccess($this->groups->previewInvite((string) $token, $this->uid($request)), 'Invite retrieved successfully.'));
    }

    /** POST /groups/invite/{token}/join */
    public function join(Request $request, $token)
    {
        return $this->handle(fn () => $this->apiSuccess($this->groups->joinByInvite((string) $token, $this->uid($request)), 'Done.'));
    }

    /** DELETE /groups/invite/{token}/request — withdraw my pending join request */
    public function cancelRequest(Request $request, $token)
    {
        return $this->handle(function () use ($request, $token) {
            $this->groups->cancelMyRequest((string) $token, $this->uid($request));
            return $this->apiSuccess(null, 'Request withdrawn.');
        });
    }

    /** GET /conversations/{id}/join-requests */
    public function joinRequests(Request $request, $id)
    {
        return $this->handle(fn () => $this->apiSuccess($this->groups->joinRequests((int) $id, $this->uid($request)), 'Join requests retrieved successfully.'));
    }

    /** POST /conversations/{id}/join-requests/{requestId}/approve */
    public function approveRequest(Request $request, $id, $requestId)
    {
        return $this->handle(function () use ($request, $id, $requestId) {
            $this->groups->decideRequest((int) $id, $this->uid($request), (int) $requestId, true);
            return $this->apiSuccess(null, 'Request approved.');
        });
    }

    /** POST /conversations/{id}/join-requests/{requestId}/reject */
    public function rejectRequest(Request $request, $id, $requestId)
    {
        return $this->handle(function () use ($request, $id, $requestId) {
            $this->groups->decideRequest((int) $id, $this->uid($request), (int) $requestId, false);
            return $this->apiSuccess(null, 'Request declined.');
        });
    }
}
