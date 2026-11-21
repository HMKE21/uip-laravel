<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\EmbedException;
use App\Services\Tenancy\EmbedFramePage;
use App\Services\Tenancy\TenantBranding;
use App\Services\Tenancy\TenantDomainService;
use App\Services\Tenancy\TenantEmbedService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Embed SDK — نقاط عامة (من غير dev.auth، خارج الـ OpenAPI):
 *  GET    /api/dev/v1/embed/frame?token=…&parent=…   صفحة الـ iframe (CSP frame-ancestors = نطاقات الـ tenant بس)
 *  POST   /api/dev/v1/embed/exchange                 توكن الدخول (مرة واحدة) → توكن جلسة
 *  GET    /api/dev/v1/embed/session                  (Bearer ems_…) بيانات الجلسة
 *  DELETE /api/dev/v1/embed/session                  (Bearer ems_…) إنهاء الجلسة
 *  GET    /api/dev/v1/embed/loader.js                سكريبت الـ loader (UIPE.meetings.embed)
 */
class EmbedPublicController extends Controller
{
    public function __construct(private TenantEmbedService $embed)
    {
    }

    private function err(EmbedException $e)
    {
        $st = $e->getCode() >= 400 && $e->getCode() < 600 ? (int) $e->getCode() : 422;
        return response()->json(['success' => false, 'message' => $e->getMessage(), 'data' => null, 'errors' => ['code' => $e->errorCode], 'meta' => (object) []], $st, ['Cache-Control' => 'no-store']);
    }

    private function ok(mixed $data, int $status = 200)
    {
        return response()->json(['success' => true, 'message' => 'OK', 'data' => $data, 'errors' => null, 'meta' => (object) []], $status, ['Cache-Control' => 'no-store']);
    }

    private function bearer(Request $r): string
    {
        $h = (string) $r->header('Authorization', '');
        return stripos($h, 'Bearer ') === 0 ? trim(substr($h, 7)) : '';
    }

    public function frame(Request $r)
    {
        // يخلّي UipSecurityHeadersMiddleware ما يكتبش فوق CSP / X-Frame-Options / Permissions-Policy / Referrer-Policy بتوع الصفحة دي.
        $r->attributes->set('uip_embed_frame', true);

        $token = (string) $r->query('token', '');
        $parent = $r->query('parent');
        $parent = is_string($parent) && $parent !== '' ? $parent : null;
        $x = $this->embed->inspect($token);

        $status = 200;
        $parentOk = null;
        if (!$x) {
            $state = 'invalid_token';
            $status = 404;
            $host = TenantDomainService::resolve($r->getHost());
            $brand = $host ? TenantBranding::publicPayload($host) : ['name' => (string) config('app.name', ''), 'powered_by' => null];
            $ancestors = "'none'";
        } else {
            $brand = TenantBranding::publicPayload($x['t']);
            $ancestors = $this->embed->frameAncestors((int) $x['t']->id);
            $state = $this->embed->joinState($x);
            if ($state === 'ok') {
                try {
                    $parentOk = $this->embed->assertParent($x, $parent);
                } catch (EmbedException $e) {
                    $state = $e->errorCode;
                }
            }
            $status = match ($state) { 'ok' => 200, 'used' => 409, 'expired' => 410, default => 403 };
            $state = match ($state) { 'used' => 'token_used', 'expired' => 'token_expired', default => $state };
        }

        $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        $connect = trim("'self' " . (string) config('tenancy.embed.connect_extra', ''));
        $csp = "default-src 'none'; script-src 'nonce-{$nonce}'; style-src 'nonce-{$nonce}'; img-src https: data: blob:; media-src 'self' blob:; connect-src {$connect}; "
            . "base-uri 'none'; form-action 'none'; frame-ancestors {$ancestors}";

        $ns = (string) config('tenancy.embed.namespace', 'uipe');
        $html = EmbedFramePage::render($brand, [
            'state' => $state, 'token' => $token, 'parent' => $parentOk, 'ns' => preg_match('/^[a-z0-9-]{1,32}$/', $ns) ? $ns : 'uipe',
            'exchange' => '/api/dev/v1/embed/exchange', 'session' => '/api/dev/v1/embed/session',
        ], $nonce);

        return response($html, $status, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => $csp,
            'Permissions-Policy' => 'camera=(self), microphone=(self), display-capture=(self), fullscreen=(self), autoplay=(self), geolocation=(), payment=(), usb=(), interest-cohort=()',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function exchange(Request $r)
    {
        $in = $r->validate(['token' => 'required|string|max:100', 'parent' => 'nullable|string|max:190']);
        try {
            return $this->ok($this->embed->exchange($in['token'], $in['parent'] ?? null));
        } catch (EmbedException $e) {
            return $this->err($e);
        }
    }

    public function session(Request $r)
    {
        $s = $this->embed->resolveAccess($this->bearer($r));
        return $s ? $this->ok($s) : $this->err(new EmbedException('The session is not valid or has ended.', 401, 'invalid_session'));
    }

    public function endSession(Request $r)
    {
        $this->embed->revokeAccess($this->bearer($r));
        return $this->ok(null);
    }

    public function loader(Request $r)
    {
        $file = resource_path('embed/loader.js');
        $g = (string) config('tenancy.embed.global', 'UIPE');
        $ns = (string) config('tenancy.embed.namespace', 'uipe');
        $js = str_replace(['__GLOBAL__', '__NS__'], [preg_match('/^[A-Za-z_$][A-Za-z0-9_$]{0,31}$/', $g) ? $g : 'UIPE', preg_match('/^[a-z0-9-]{1,32}$/', $ns) ? $ns : 'uipe'], (string) file_get_contents($file));
        return response($js, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8', 'Cache-Control' => 'public, max-age=300',
            'Access-Control-Allow-Origin' => '*', 'Cross-Origin-Resource-Policy' => 'cross-origin',
        ]);
    }
}
