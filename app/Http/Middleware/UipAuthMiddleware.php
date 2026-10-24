<?php

namespace App\Http\Middleware;

use App\Services\UipJwtService;
use Closure;
use Illuminate\Http\Request;

/**
 * يطابق سلوك AuthMiddleware القديمة لكن بس على الجزء الخاص بـ Bearer
 * token (JSON API) — التحقق من الـ session cookie للويب الـ server-rendered
 * مش منقول هنا لأننا بنغطي الـ React SPA بس.
 *
 * لو التوكن صالح، بيحط $request->attributes: uip_user_id و uip_role
 * (بديل بسيط عن Auth::user() لحد ما نربط Guard كامل).
 */
class UipAuthMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $header = $request->header('Authorization', '');

        if (!str_starts_with($header, 'Bearer ')) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required.',
                'data'    => null,
                'errors'  => null,
                'meta'    => (object) [],
            ], 401);
        }

        $token = substr($header, 7);
        $claims = UipJwtService::decodeAccess($token);

        if (!$claims || !isset($claims['sub'])) {
            return response()->json([
                'success' => false,
                'message' => 'Your session has expired. Please log in again.',
                'data'    => null,
                'errors'  => null,
                'meta'    => (object) [],
            ], 401);
        }

        // Device Restrictions Policy — بتتطبق على كل ريكوست محمي، فالجلسات اللي كانت
        // شغالة على نوع جهاز اتحظر بعد كده بتتقطع فورًا (مش بس اللوجين الجديد).
        $devicePolicy = app(\App\Services\DeviceRestrictionPolicyService::class);
        $claimRole = (string) ($claims['role'] ?? '');
        if (!$devicePolicy->isAllowed($request->userAgent(), $claimRole)) {
            return response()->json([
                'success' => false,
                'message' => $devicePolicy->blockedMessage($request->header('X-Locale', 'en') === 'ar' ? 'ar' : 'en', $claimRole),
                'data'    => [
                    'device_blocked' => true,
                    'device_type'    => $devicePolicy->classify($request->userAgent()),
                    'role'           => $claimRole,
                ],
                'errors'  => ['code' => 'device_blocked'],
                'meta'    => (object) [],
            ], 403);
        }

        // جلسة اتلغت من بورتال الأمان (أو خلصت) => الـ access token بيبطل فورًا.
        $sid = isset($claims['sid']) ? (int) $claims['sid'] : null;
        // توكن من غير sid مينفعش يتلغى بالـ logout — نرفضه لو AUTH_REQUIRE_SID=true.
        if (!$sid && (bool) config('security.jwt.require_sid', false)) {
            return response()->json([
                'success' => false,
                'message' => 'Your session has expired. Please log in again.',
                'data'    => null,
                'errors'  => null,
                'meta'    => (object) [],
            ], 401);
        }
        if ($sid) {
            $sessions = app(\App\Services\UserSessionService::class);
            if (!$sessions->isActive($sid)) {
                return response()->json([
                    'success' => false,
                    'message' => 'This session was ended. Please log in again.',
                    'data'    => null,
                    'errors'  => null,
                    'meta'    => (object) [],
                ], 401);
            }
            $sessions->touch($sid);
            $request->attributes->set('uip_session_id', $sid);
        }

        // حساب اتحذف/اتوقف/اتحظر (مثلاً الجامعة اتحذفت وطلابها اتوقفوا) => الـ token
        // بيبطل فورًا بدل ما يفضل شغال لحد ما يخلص.
        $account = \Illuminate\Support\Facades\DB::table('users')->where('id', (int) $claims['sub'])->first(['status', 'deleted_at']);
        if (!$account || $account->deleted_at !== null || in_array($account->status, ['suspended', 'banned'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is no longer active. Please contact platform support.',
                'data'    => null,
                'errors'  => ['code' => 'account_inactive'],
                'meta'    => (object) [],
            ], 401);
        }

        $request->attributes->set('uip_user_id', (int) $claims['sub']);
        $request->attributes->set('uip_role', (string) ($claims['role'] ?? ''));

        // MFA Requirements Policy — لو دور المستخدم ملزم بـ 2FA ومفعّلهوش وخلصت مهلة التسجيل،
        // كل الـ API بيتقفل ماعدا /auth/* (منها endpoints تفعيل 2FA + logout + permissions)
        // و /session/* (heartbeat/release) لحد ما يفعّل 2FA. بتتطبق على كل ريكوست محمي
        // فالجلسات المفتوحة بتتقيّد فورًا لما السياسة تتغير.
        if (!$request->is('api/v1/auth/*', 'api/v1/session/*')) {
            $mfa = app(\App\Services\MfaPolicyService::class)->status((int) $claims['sub'], $claimRole);
            if ($mfa['restricted']) {
                $ar = $request->header('X-Locale', 'en') === 'ar';
                return response()->json([
                    'success' => false,
                    'message' => $ar
                        ? 'لازم تفعّل المصادقة الثنائية (2FA) قبل ما تكمل استخدام المنصة.'
                        : 'You must enable two-factor authentication before you can keep using the platform.',
                    'data'    => $mfa,
                    'errors'  => ['code' => 'mfa_setup_required'],
                    'meta'    => (object) [],
                ], 403);
            }
        }

        // الحساب مش مرتبط بجامعة => مفيش استخدام للمنصة غير البروفايل/طلب الانضمام/الإشعارات لحد ما الجامعة أو الكلية
        // توافق، أو أدمن/جامعة/كلية يمنحه ميزات (رسائل/اجتماعات/مشاريع/جهازي...). شوف AccountAccessGate.
        [$allowed, $gate, $feature] = app(\App\Services\AccountAccessGate::class)->check((int) $claims['sub'], $claimRole, $request->path());
        if (!$allowed) {
            $ar = $request->header('X-Locale', 'en') === 'ar';
            return response()->json([
                'success' => false,
                'message' => $ar
                    ? 'حسابك مش مرتبط بجامعة. لازم تنضم لجامعة وتستنى موافقتها، أو تاخد صلاحية من الإدارة/الجامعة/الكلية قبل ما تستخدم الميزة دي.'
                    : 'Your account is not linked to a university. Join a university and wait for its approval, or get access from the admin, university or faculty, before using this feature.',
                'data'    => $gate + ['required_feature' => $feature],
                'errors'  => ['code' => 'university_approval_required', 'feature' => $feature],
                'meta'    => (object) [],
            ], 403);
        }

        return $next($request);
    }
}
