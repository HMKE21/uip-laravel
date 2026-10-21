<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GoogleDriveOAuthService;
use Illuminate\Http\Request;

/**
 * Google Drive backup link (server-side OAuth, refresh token kept encrypted) — see GoogleDriveOAuthService.
 *
 *   GET    /api/v1/cloud/google/status      { configured, connected, email }
 *   POST   /api/v1/cloud/google/connect     → { url } Google consent page (opened in a pop-up)
 *   GET    /api/v1/cloud/google/callback    (public) Google redirects here → tiny page that tells the opener and closes
 *   GET    /api/v1/cloud/google/token       → { access_token, expires_in } (?force=1 skips the cache)
 *   DELETE /api/v1/cloud/google/connection  unlink + revoke
 */
class GoogleDriveConnectionApiController extends Controller
{
    public function __construct(private GoogleDriveOAuthService $google)
    {
    }

    public function status(Request $request)
    {
        $conn = $this->google->connectionFor($this->userId($request));
        return $this->apiSuccess([
            'configured' => $this->google->isConfigured(),
            'connected'  => (bool) $conn && $conn->refreshToken() !== null,
            'email'      => $conn?->google_email,
        ], 'OK');
    }

    public function connect(Request $request)
    {
        if (!$this->google->isConfigured()) {
            return $this->apiError('Google Drive backup is not configured on this platform yet.', null, 503);
        }
        $hint = $request->input('hint');
        return $this->apiSuccess(['url' => $this->google->authorizeUrl($this->userId($request), is_string($hint) && $hint !== '' ? $hint : null)], 'OK');
    }

    /** Public: identity comes from the cached state. Answers with a small page that posts the result to the opener window. */
    public function callback(Request $request)
    {
        $result = ['source' => 'uip-gdrive', 'ok' => false, 'reason' => 'failed'];

        if ($request->query('error')) {
            $result['reason'] = 'denied';
        } else {
            try {
                $conn = $this->google->completeCallback((string) $request->query('code', ''), (string) $request->query('state', ''));
                $result = ['source' => 'uip-gdrive', 'ok' => true, 'email' => $conn->google_email];
            } catch (\RuntimeException $e) {
                $known = ['expired_state', 'token_exchange_failed', 'no_refresh_token', 'scope_missing'];
                $result['reason'] = in_array($e->getMessage(), $known, true) ? $e->getMessage() : 'failed';
            } catch (\Throwable $e) {
                $result['reason'] = 'failed';
            }
        }

        $origin = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');
        $json = json_encode($result, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $o = json_encode($origin, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $msg = $result['ok'] ? 'Connected. You can close this window.' : 'Could not connect. You can close this window and try again.';
        $html = '<!doctype html><meta charset="utf-8"><title>Google Drive</title><body style="font-family:sans-serif;text-align:center;padding:40px">'
            . '<p>' . e($msg) . '</p><script>try{window.opener&&window.opener.postMessage(' . $json . ',' . $o . ');}catch(e){}setTimeout(function(){window.close()},300);</script>';

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function token(Request $request)
    {
        $conn = $this->google->connectionFor($this->userId($request));
        if (!$conn) {
            return $this->apiError('Google Drive is not connected.', null, 409);
        }
        try {
            return $this->apiSuccess($this->google->accessToken($conn, (bool) $request->boolean('force')), 'OK');
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'unauthorized') {
                return $this->apiError('Google access was revoked or expired. Please connect again.', null, 409);
            }
            return $this->apiError('Could not reach Google right now. Please try again.', null, 502);
        }
    }

    public function disconnect(Request $request)
    {
        if ($conn = $this->google->connectionFor($this->userId($request))) {
            $this->google->disconnect($conn);
        }
        return $this->apiSuccess(null, 'Disconnected.');
    }

    private function userId(Request $request): int
    {
        return (int) $request->attributes->get('uip_user_id');
    }
}
