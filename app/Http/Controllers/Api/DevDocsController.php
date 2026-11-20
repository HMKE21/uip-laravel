<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Developer\DevOpenApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * GET /api/dev/v1/openapi.json — مواصفات OpenAPI 3.1 للـ Developer API. عامة (من غير اعتماد): مفيش فيها أسرار، ومحتاجينها أدوات التوليد (SDKs) والـ API Explorer.
 * متولّدة من الـ routes الفعلية، متكاشّية 10 دقايق، وبترجع ETag فالـ 304 رخيص.
 */
class DevDocsController extends Controller
{
    public function openapi(Request $r)
    {
        $base = rtrim((string) (config('app.url') ?: $r->getSchemeAndHttpHost()), '/');
        $json = Cache::remember('dev:openapi:' . config('developer.api_version') . ':' . md5($base), 600, function () use ($base) {
            return json_encode(DevOpenApi::build(DevOpenApi::routesFromLaravel(), $base), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        });
        $etag = '"' . md5($json) . '"';
        $headers = ['ETag' => $etag, 'Cache-Control' => 'public, max-age=300', 'Content-Type' => 'application/json; charset=utf-8', 'Access-Control-Allow-Origin' => '*'];
        if (trim((string) $r->header('If-None-Match')) === $etag) {
            return response('', 304, $headers);
        }
        return response($json, 200, $headers);
    }
}
