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
        if (\App\Support\SandboxTokenSigner::config() === null) {
            return $this->apiError('The sandbox is not configured on this server.', null, 503);
        }

        $userId  = (int) $request->attributes->get('uip_user_id');
        $project = $this->projects->findAccessible((string) $id, $userId);
        if (!$project instanceof Project) {
            return $this->apiError('Project not found.', null, 404);
        }

        // $id is only a session key for the gateway; findAccessible() above is the access check
        $token = \App\Support\SandboxTokenSigner::issue($userId, (string) $id, 'project');
        if ($token === null) {
            return $this->apiError('The sandbox is not configured on this server.', null, 503);
        }

        return $this->apiSuccess($token, 'Sandbox token issued.');
    }
}
