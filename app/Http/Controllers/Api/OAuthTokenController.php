<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Developer\OAuthException;
use App\Services\Developer\OAuthService;
use Illuminate\Http\Request;

/** /api/oauth/token و /api/oauth/revoke — عام (من غير uip.auth)، شكل الرد RFC 6749/7009 (مش شكل apiSuccess). */
class OAuthTokenController extends Controller
{
    public function __construct(private OAuthService $oauth)
    {
    }

    private function err(OAuthException $e)
    {
        $status = $e->getCode() >= 400 && $e->getCode() < 600 ? (int) $e->getCode() : 400;
        $h = ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache'];
        if ($status === 401) {
            $h['WWW-Authenticate'] = 'Basic realm="uipe"';
        }
        return response()->json(['error' => $e->oauthError, 'error_description' => $e->getMessage()], $status, $h);
    }

    public function token(Request $r)
    {
        try {
            return response()->json($this->oauth->token($r), 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
        } catch (OAuthException $e) {
            return $this->err($e);
        } catch (\DomainException $e) {
            return response()->json(['error' => 'invalid_request', 'error_description' => $e->getMessage()], 400, ['Cache-Control' => 'no-store']);
        }
    }

    public function revoke(Request $r)
    {
        try {
            $this->oauth->revoke($r);
            return response()->json((object) [], 200, ['Cache-Control' => 'no-store']);
        } catch (OAuthException $e) {
            return $this->err($e);
        }
    }
}
