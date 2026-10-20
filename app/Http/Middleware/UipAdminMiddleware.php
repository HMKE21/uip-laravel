<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * يطابق app/Middleware/AdminMiddleware.php القديمة. لازم يتحط بعد
 * uip.auth في نفس المجموعة (محتاج uip_role اللي uip.auth بتحطها في
 * $request->attributes — شوف bootstrap/app.php).
 */
class UipAdminMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->attributes->get('uip_role') !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Admins only.',
                'data'    => null,
                'errors'  => null,
                'meta'    => (object) [],
            ], 403);
        }

        return $next($request);
    }
}
