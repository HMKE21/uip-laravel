<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Developer\DevOpenApi;
use App\Services\Developer\Sdk\SdkGenerator;
use App\Services\Developer\Sdk\SdkPackager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * GET /api/dev/v1/sdks/{php|js|python}.zip — تنزيل مكتبة الـ SDK جاهزة (عام، من غير اعتماد: نفس الـ openapi.json والقوالب مفيهاش أسرار).
 * بتتولّد في الذاكرة من الـ spec الحالي بنفس مولّد `php artisan developer:sdk` (فمفيش اعتماد على sdks/ جوه السيرفر)، متكاشّية 10 دقايق.
 * مش جوه الـ OpenAPI: DevOpenApi بيتجاهل أي route من غير dev.auth.
 */
class DevSdkDownloadController extends Controller
{
    public function download(Request $r, string $lang)
    {
        if (!in_array($lang, SdkGenerator::LANGS, true)) {
            return response()->json(['success' => false, 'message' => 'Unknown SDK.'], 404);
        }
        if (!class_exists(\ZipArchive::class)) {
            return response()->json(['success' => false, 'message' => 'The server cannot build zip files (php-zip is missing).'], 503);
        }
        $base = rtrim((string) (config('app.url') ?: $r->getSchemeAndHttpHost()), '/');
        $version = (string) config('developer.sdk_version', '1.0.0');
        $files = Cache::remember('dev:sdk:files:' . config('developer.api_version') . ':' . md5($base . '|' . $version), 600, function () use ($base, $version) {
            return SdkGenerator::generate(DevOpenApi::build(DevOpenApi::routesFromLaravel(), $base), SdkGenerator::LANGS, $version);
        });
        $zip = SdkPackager::zip($files, $lang);
        return response($zip, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="uipe-' . $lang . '.zip"',
            'Content-Length' => (string) strlen($zip),
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
