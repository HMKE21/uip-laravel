<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use App\Services\ChatMediaPolicyService;
use App\Support\PlatformPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Admin → Chat Media (GIFs + stickers).
 *
 *   GET    /api/v1/admin/chat-media          policy, defaults, Giphy key status (masked)
 *   PUT    /api/v1/admin/chat-media          { ...policy fields, giphy_api_key? }   ("" for giphy_api_key = leave as is)
 *   DELETE /api/v1/admin/chat-media          back to the default policy
 *   DELETE /api/v1/admin/chat-media/giphy-key  forget the dashboard key (the .env one applies again)
 *   POST   /api/v1/admin/chat-media/test-giphy { giphy_api_key? }  does Giphy accept this key?
 *
 * RBAC: uip.auth + uip.admin on the route group, plus the same uip_role === 'admin' guard as Platform Policy. Every change is audit-logged.
 */
class AdminChatMediaApiController extends Controller
{
    public function __construct(private ChatMediaPolicyService $media, private AuditLogService $auditLog)
    {
    }

    private function requireAdmin(Request $request)
    {
        return $request->attributes->get('uip_role') === 'admin' ? null : $this->apiError('Only admin accounts can manage chat media.', null, 403);
    }

    private function adminId(Request $request): int
    {
        return (int) $request->attributes->get('uip_user_id');
    }

    /** @return array<string,mixed> */
    private function snapshot(): array
    {
        $key = trim((string) PlatformPolicy::value('giphy_api_key'));

        return [
            'policy'   => $this->media->get(),
            'defaults' => ChatMediaPolicyService::defaults(),
            'ratings'  => ChatMediaPolicyService::RATINGS,
            'roles'    => ChatMediaPolicyService::ROLE_KEYS,
            'giphy'    => [
                'configured' => $key !== '',
                'masked'     => $key !== '' ? PlatformPolicy::mask($key) : '',
                'source'     => PlatformPolicy::isValueOverridden('giphy_api_key') ? 'dashboard' : ($key !== '' ? 'env' : 'none'),
            ],
        ];
    }

    public function show(Request $request)
    {
        if ($deny = $this->requireAdmin($request)) {
            return $deny;
        }

        return $this->apiSuccess($this->snapshot(), 'Chat media settings retrieved.');
    }

    public function update(Request $request)
    {
        if ($deny = $this->requireAdmin($request)) {
            return $deny;
        }
        $before = $this->media->get();
        try {
            $after = $this->media->update($request->except(['giphy_api_key']));
            $newKey = trim((string) $request->input('giphy_api_key', ''));
            if ($newKey !== '') {
                [$val, $err] = PlatformPolicy::normalizeValue('giphy_api_key', $newKey);
                if ($err) {
                    return $this->apiError($err, null, 422);
                }
                PlatformPolicy::setValue('giphy_api_key', (string) $val);
            }
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }
        $this->auditLog->record($this->adminId($request), 'platform.chat_media_update', 'Setting', null, $before, $after + ['giphy_key_changed' => $newKey !== ''], $request->ip());

        return $this->apiSuccess($this->snapshot(), 'Chat media settings saved.');
    }

    public function reset(Request $request)
    {
        if ($deny = $this->requireAdmin($request)) {
            return $deny;
        }
        $before = $this->media->get();
        $after = $this->media->reset();
        $this->auditLog->record($this->adminId($request), 'platform.chat_media_reset', 'Setting', null, $before, $after, $request->ip());

        return $this->apiSuccess($this->snapshot(), 'Chat media settings reset.');
    }

    public function resetGiphyKey(Request $request)
    {
        if ($deny = $this->requireAdmin($request)) {
            return $deny;
        }
        PlatformPolicy::resetValue('giphy_api_key');
        $this->auditLog->record($this->adminId($request), 'platform.chat_media_giphy_key_reset', 'Setting', null, null, null, $request->ip());

        return $this->apiSuccess($this->snapshot(), 'Giphy key removed.');
    }

    public function testGiphy(Request $request)
    {
        if ($deny = $this->requireAdmin($request)) {
            return $deny;
        }
        $key = trim((string) $request->input('giphy_api_key', ''));
        if ($key === '') {
            $key = $this->media->giphyKey();
        }
        if ($key === '') {
            return $this->apiError('No Giphy API key is set yet.', null, 422);
        }
        try {
            $resp = Http::timeout(8)->get('https://api.giphy.com/v1/gifs/trending', ['api_key' => $key, 'limit' => 1]);
        } catch (\Throwable $e) {
            return $this->apiError('Could not reach Giphy from the server.', null, 502);
        }
        if (!$resp->ok()) {
            return $this->apiError('Giphy rejected this key (HTTP ' . $resp->status() . ').', null, 422);
        }

        return $this->apiSuccess(['ok' => true], 'The key works.');
    }
}
