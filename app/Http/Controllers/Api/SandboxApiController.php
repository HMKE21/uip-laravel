<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\ResearchProjectService;
use Illuminate\Http\Request;

/**
 * Project sandbox (real terminal + Laravel / Node / Python servers) — token issuer ONLY.
 *
 * The sandbox itself runs in a SEPARATE service (uip-sandbox) on its own domain and its own server; student code never
 * runs next to this API. This controller only answers "may this user open a sandbox for this project?" by signing a
 * short-lived, single-use HS256 token that the gateway verifies with the same SANDBOX_JWT_SECRET.
 *
 *   POST /api/v1/projects/{id}/sandbox-token  ->  { url, token, expires_in }
 *
 * Access rule = same as reading the project's files (ResearchProjectService::findAccessible): owner, accepted team
 * members, and the staff who can open the project. The sandbox gets no secrets from this app — only the user id and
 * project id inside the token.
 */
class SandboxApiController extends Controller
{
    public function __construct(private ResearchProjectService $projects)
    {
    }

    public function token(Request $request, $id)
    {
        $url    = rtrim((string) config('services.sandbox.url'), '/');
        $secret = (string) config('services.sandbox.secret');
        if ($url === '' || strlen($secret) < 32) {
            return $this->apiError('The sandbox is not configured on this server.', null, 503);
        }

        $userId  = (int) $request->attributes->get('uip_user_id');
        $project = $this->projects->findAccessible((string) $id, $userId);
        if (!$project instanceof Project) {
            return $this->apiError('Project not found.', null, 404);
        }

        $ttl = max(30, (int) config('services.sandbox.token_ttl', 120));
        $now = time();
        $claims = [
            'iss' => 'uip-api',
            'aud' => 'uip-sandbox',
            'sub' => (string) $userId,
            'pid' => (string) $id,
            'iat' => $now,
            'exp' => $now + $ttl,
            'jti' => bin2hex(random_bytes(8)),
        ];

        return $this->apiSuccess([
            'url'        => $url,
            'token'      => $this->signHs256($claims, $secret),
            'expires_in' => $ttl,
        ], 'Sandbox token issued.');
    }

    private function signHs256(array $claims, string $secret): string
    {
        $b64  = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $head = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = $b64(json_encode($claims));
        $sig  = $b64(hash_hmac('sha256', "{$head}.{$body}", $secret, true));
        return "{$head}.{$body}.{$sig}";
    }
}
