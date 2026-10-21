<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * نسخة طبق الأصل من app/Middleware/SecurityHeadersMiddleware.php القديمة
 * — بتحط نفس الهيدرز من config('security.security_headers') على كل ريسبونس.
 */
class UipSecurityHeadersMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach ((array) config('security.security_headers', []) as $name => $value) {
            $response->headers->set($name, $value);
        }

        // Google Drive pop-up callback: a tiny page that must run one inline script (postMessage to the opener) and must keep
        // window.opener, so it gets a narrower CSP and no same-origin COOP. Nothing user-controlled is printed (values are JSON-escaped).
        if ($request->is('api/v1/cloud/google/callback')) {
            $response->headers->set('Cross-Origin-Opener-Policy', 'unsafe-none');
            $response->headers->set('Content-Security-Policy', "default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
        }

        return $response;
    }
}
