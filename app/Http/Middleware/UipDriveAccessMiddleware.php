<?php

namespace App\Http\Middleware;

use App\Services\DriveAdminSettings;
use Closure;
use Illuminate\Http\Request;

/**
 * بوابة "جهازي" لكل endpoints سطح المكتب (/desktop/*): إعدادات، ويدجت، خلفية، قفل، أمان، إعادة تهيئة.
 *
 * قبل كده فحص الأدمن (Admin → My PC Control: إيقاف "جهازي" / الأدوار المسموحة / حظر مستخدم) كان بيتطبّق على ملفات
 * الدرايف بس (DriveApiController)، فالشاشة نفسها كانت بتفتح وتشتغل لدور اتقفل (مثلًا data_analyst).
 * دلوقتي نفس DriveAdminSettings::denial بيتطبّق هنا: 503 لو "جهازي" مقفول، 403 لو الدور مش مسموح أو المستخدم محظور،
 * و"قراءة فقط" بيمنع أي POST/PUT/PATCH/DELETE. لازم يتحط بعد uip.auth (محتاج uip_user_id / uip_role).
 */
class UipDriveAccessMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $write = !in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);
        $d = DriveAdminSettings::denial((int) $request->attributes->get('uip_user_id'), (string) $request->attributes->get('uip_role'), $write);
        if ($d) {
            return response()->json([
                'success' => false,
                'message' => $d[0],
                'data'    => null,
                'errors'  => ['code' => $d[2]],
                'meta'    => (object) [],
            ], $d[1]);
        }

        return $next($request);
    }
}
