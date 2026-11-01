<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('https://uip-nub.vercel.app');
});

/*
 * Fallback للملفات العامة (avatars / wallpapers / logos …): الويب سيرفر بيخدم public/uploads/* مباشرة لو الملف موجود.
 * بعد Deploy الديسك بيتمسح — الطلب بيوصل هنا، فبنرجّع الملف من نسخة S3 (UploadMirror) ونكمّل زي الطبيعي.
 * فئات الشات الخاصة (messages) مش هنا: بتتسلّم بس من خلال routes الـ API بتاعة العضوية.
 */
Route::get('/uploads/{path}', function (string $path) {
    $rel = 'uploads/' . ltrim(str_replace('\\', '/', $path), '/');
    if (str_contains($rel, '..') || str_contains($rel, "\0")) {
        abort(404);
    }
    $allowed = false;
    foreach ((array) config('upload.mirror.public_serve', []) as $prefix) {
        if (str_starts_with($rel, rtrim($prefix, '/') . '/')) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed || \App\Services\FileUploadService::isPrivatePath($rel)) {
        abort(404);
    }
    $full = public_path($rel);
    if (!is_file($full) && !\App\Support\UploadMirror::restore($rel, $full)) {
        abort(404);
    }
    return response()->file($full, [
        'X-Content-Type-Options' => 'nosniff',
        'Cache-Control' => 'public, max-age=86400',
    ]);
})->where('path', '.*')->withoutMiddleware([
    \Illuminate\Session\Middleware\StartSession::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
]);
