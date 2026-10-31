<?php

namespace App\Http\Middleware;

use App\Services\UserActivityService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Server-side half of the per-user activity timeline: records every WRITE request (POST/PUT/PATCH/DELETE) and every FAILED
 * request (status >= 400, with the message the person got) of a signed-in user, plus sign-in attempts. Plain successful reads
 * are not logged (polling would drown the timeline) — the browser tracker records page views / clicks instead.
 *
 * The response is inspected in handle() (this middleware sits INSIDE the response-encryption one, so the JSON is still readable);
 * the row is written in a terminating callback, i.e. after the answer has been sent: it never slows or breaks a request.
 * Request bodies are never stored — only the NAMES of the submitted fields.
 */
class UipActivityLogMiddleware
{
    private const SKIP = [
        'api/v1/activity/track', 'api/v1/session/heartbeat', 'api/v1/app/summary', 'api/v1/realtime/auth',
        'api/v1/secure/handshake', 'api/v1/secure/selftest', 'api/v1/app-config', 'api/v1/platform/status',
        'api/v1/messaging/presence/heartbeat', 'api/v1/auth/refresh-token', 'api/v1/app/client-report',
    ];

    /** Field names that are never even listed. */
    private const HIDDEN_FIELDS = ['password', 'password_confirmation', 'current_password', 'new_password', 'token', 'refresh_token', 'otp', 'code', 'secret'];

    public function handle(Request $request, Closure $next)
    {
        $t0 = microtime(true);
        $response = $next($request);

        try {
            if (UserActivityService::enabled() && !$request->isMethod('OPTIONS') && !in_array(trim($request->path(), '/'), self::SKIP, true)) {
                $status = method_exists($response, 'getStatusCode') ? (int) $response->getStatusCode() : 200;
                $isWrite = !$request->isMethodSafe();
                $isLogin = $request->is('api/v1/auth/login');
                if ($isWrite || $status >= 400 || $isLogin) {
                    $payload = $this->snapshot($request, $response, $status, (int) round((microtime(true) - $t0) * 1000));
                    app()->terminating(fn () => $this->store($payload));
                }
            }
        } catch (\Throwable $e) {
            // logging must never break a request
        }

        return $response;
    }

    /** @return array<string,mixed> */
    private function snapshot(Request $request, $response, int $status, int $ms): array
    {
        $json = null;
        $ct = isset($response->headers) ? (string) $response->headers->get('Content-Type', '') : '';
        if (str_contains($ct, 'json') && method_exists($response, 'getContent')) {
            $raw = (string) $response->getContent();
            if ($raw !== '' && strlen($raw) < 200000) {
                $decoded = json_decode($raw, true);
                $json = is_array($decoded) ? $decoded : null;
            }
        }

        $route = $request->route();
        $uri = $route && method_exists($route, 'uri') ? (string) $route->uri() : trim($request->path(), '/');
        $fields = [];
        if (!$request->isMethodSafe() && !$request->files->count()) {
            $fields = array_values(array_diff(array_slice(array_keys($request->except(array_merge(['_token'], []))), 0, 30), self::HIDDEN_FIELDS));
        }
        $files = [];
        foreach ($request->allFiles() as $name => $f) {
            $files[] = (string) $name;
        }

        $uid = (int) $request->attributes->get('uip_user_id');
        $loginEmail = null;
        if (!$uid && $request->is('api/v1/auth/login')) {
            $loginEmail = strtolower(trim((string) $request->input('email', '')));
        }

        $errors = null;
        if ($json && is_array($json['errors'] ?? null)) {
            $errors = $json['errors'];
            unset($errors['trace'], $errors['exception']);
        }

        return [
            'uid'          => $uid,
            'login_email'  => $loginEmail,
            'is_login'     => $request->is('api/v1/auth/login'),
            'method'       => $request->method(),
            'uri'          => $uri,
            'path'         => '/' . ltrim($request->path(), '/'),
            'full'         => $request->getRequestUri(),
            'status'       => $status,
            'ms'           => $ms,
            'message'      => is_array($json) && isset($json['message']) && is_string($json['message']) ? $json['message'] : null,
            'errors'       => $errors,
            'success'      => is_array($json) ? ($json['success'] ?? null) : null,
            'login_uid'    => $json['data']['user']['id'] ?? ($json['data']['user_id'] ?? null),
            'fields'       => $fields,
            'files'        => $files,
            'ip'           => $request->ip(),
            'ua'           => (string) $request->userAgent(),
            'referer_page' => (string) $request->header('X-UIP-Page', ''),
            'session_id'   => (string) $request->header('X-UIP-Activity-Session', ''),
        ];
    }

    private function store(array $p): void
    {
        try {
            $uid = (int) $p['uid'];
            if (!$uid && $p['is_login']) {
                $uid = (int) ($p['login_uid'] ?: 0);
                if (!$uid && $p['login_email'] !== '') {
                    $uid = (int) DB::table('users')->where('email', $p['login_email'])->value('id');
                }
            }
            if (!$uid) {
                return;
            }

            $status = (int) $p['status'];
            $failed = $status >= 400 || $p['success'] === false;
            $severity = !$failed ? 'info' : ($status >= 500 ? 'error' : 'warning');
            $label = $p['method'] . ' ' . $p['uri'];

            if ($p['is_login']) {
                $type = 'auth';
                $title = $failed ? 'Sign-in attempt failed' : 'Signed in';
            } elseif ($failed) {
                $type = 'api_error';
                $title = $label . ' → ' . $status;
            } else {
                $type = 'api_write';
                $title = $label . ' → ' . $status;
            }

            $details = [];
            if ($p['fields']) {
                $details['fields_sent'] = $p['fields'];
            }
            if ($p['files']) {
                $details['file_fields'] = $p['files'];
            }
            if ($failed && $p['errors']) {
                $details['errors'] = $p['errors'];
            }

            app(UserActivityService::class)->record($uid, [
                'type'        => $type,
                'severity'    => $severity,
                'source'      => 'server',
                'title'       => $title,
                'path'        => $p['referer_page'] ?: null,
                'method'      => $p['method'],
                'endpoint'    => $p['uri'],
                'url'         => $p['full'],
                'status'      => $status,
                'duration_ms' => $p['ms'],
                'message'     => $failed ? ($p['message'] ?: 'Request failed (' . $status . ')') : $p['message'],
                'ip'          => $p['ip'],
                'user_agent'  => $p['ua'],
                'session_id'  => $p['session_id'] ?: null,
                'details'     => $details,
            ]);
        } catch (\Throwable $e) {
            // never surface
        }
    }
}
