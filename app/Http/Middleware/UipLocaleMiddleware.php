<?php

namespace App\Http\Middleware;

use App\Support\MessageTranslator;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ⚠️ فرق تصميم متعمّد عن القديم — Stateless.
 *
 * القديم (app/Middleware/LocaleMiddleware.php) كان بيقرا ?lang=en|ar،
 * يخزّنه في الـ PHP session، وبعد كده بيرجع لقيمة الـ session المخزّنة
 * لو الريكوست مبعتش ?lang. الـ API الجديد Stateless بالكامل (Bearer JWT،
 * مفيش session cookie خالص) فمفيش حاجة يتخزن فيها locale بين الريكوستس.
 *
 * البديل هنا: كل ريكوست لازم يبعت لغته بنفسه — إما ?lang=en|ar أو هيدر
 * Accept-Language — وإلا بيرجع لـ config('app.locale'). الفرونت (React
 * SPA) هو المسؤول عن تخزين اختيار المستخدم للغة (localStorage مثلًا)
 * وبعتها مع كل ريكوست، بدل ما السيرفر يفتكرها. App::setLocale() هنا
 * بيتحط لمدة الريكوست بس (Laravel بيعمل reset لكل ريكوست جديد أصلًا).
 */
class UipLocaleMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // ?lang= أو lang في الـ body (تصدير)، وبعدها هيدر X-Locale اللي الفرونت بيبعته مع كل ريكوست.
        $requested = $request->query('lang') ?: $request->input('lang') ?: $request->header('X-Locale');
        $available = array_keys((array) config('languages', ['en' => [], 'ar' => []]));

        $locale = ($requested && in_array($requested, $available, true))
            ? $requested
            : (string) config('app.locale', 'en');

        app()->setLocale($locale);

        $response = $next($request);

        // عربي: ترجمة message و errors في ريسبونس الـ JSON (الـ envelope الموحّد) بشكل مركزي.
        if ($locale === 'ar' && $response instanceof JsonResponse) {
            $data = $response->getData(false);
            if (is_object($data)) {
                $touched = false;
                if (isset($data->message) && is_string($data->message)) {
                    $data->message = MessageTranslator::translate($data->message);
                    $touched = true;
                }
                if (isset($data->errors) && (is_string($data->errors) || is_array($data->errors) || is_object($data->errors))) {
                    $data->errors = MessageTranslator::translateDeep($data->errors);
                    $touched = true;
                }
                if ($touched) {
                    $response->setData($data);
                }
            }
        }

        return $response;
    }
}
