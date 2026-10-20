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

        return $response;
    }
}
