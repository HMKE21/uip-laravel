<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\RefreshToken;
use App\Services\UipJwtService;
use App\Services\UserSessionService;
use App\Support\AuthCookies;
use App\Support\SecurityLog;
use Illuminate\Http\Request;

/**
 * Logout يلغي الجلسة من تلات مصادر (أي واحد كفاية):
 *   1) claim `sid` جوه الـ access token (Bearer) — المصدر الأساسي.
 *      ده اللي الـ UipAuthMiddleware بيفحصه في كل طلب، فإلغاؤه = التوكن يبطل فورًا.
 *   2) كوكي uip_refresh (HttpOnly).
 *   3) refresh_token في الجسم (جلسات legacy).
 *
 * قبل كده كان بيعتمد على (2)/(3) بس، فلو الكوكي مبعتش أو الـ CSRF فشل
 * كان بيرجّع نجاح/403 من غير ما الجلسة تتلغي، والـ access token يفضل شغال.
 */
class LogoutController extends Controller
{
    public function __construct(private UserSessionService $sessions)
    {
    }

    public function handle(Request $request)
    {
        $endedUserId = null;

        // 1) من الـ access token (Bearer). مفيش CSRF هنا: Bearer مش credential بيتبعت أوتوماتيك.
        $header = (string) $request->header('Authorization', '');
        if (str_starts_with($header, 'Bearer ')) {
            $claims = UipJwtService::decodeAccess(substr($header, 7));
            if ($claims && !empty($claims['sid'])) {
                $sid = (int) $claims['sid'];
                if ($this->sessions->end($sid, 'logout')) {
                    $endedUserId = (int) ($claims['sub'] ?? 0);
                    SecurityLog::write('User logged out', ['user_id' => $endedUserId, 'via' => 'access_token', 'sid' => $sid]);
                }
            }
        }

        // 2) + 3) من الـ refresh token (كوكي أو جسم).
        $refreshToken = AuthCookies::refreshFromCookie($request);
        $fromCookie = $refreshToken !== '';
        if (!$fromCookie && AuthCookies::allowBodyRefresh()) {
            $refreshToken = (string) $request->input('refresh_token', '');
        }

        if ($refreshToken !== '') {
            // CSRF بيتفحص بس لو الاعتماد على الكوكي لوحده (مفيش Bearer اتلغى بيه جلسة).
            if ($fromCookie && $endedUserId === null && ($reason = AuthCookies::csrfFailure($request))) {
                SecurityLog::write('Logout rejected — CSRF check failed', ['reason' => $reason, 'ip' => $request->ip()]);
                return AuthCookies::noStore(response()->json(['success' => false, 'message' => 'Request could not be verified.'], 403));
            }

            $hash = hash('sha256', $refreshToken);
            $session = $this->sessions->findByRefreshHash($hash);
            if ($session) {
                $this->sessions->end((int) $session->id, 'logout');
            }
            $row = RefreshToken::where('token_hash', $hash)->whereNull('revoked_at')->first();
            if ($row) {
                $row->update(['revoked_at' => now()]);
                if ($endedUserId === null) {
                    SecurityLog::write('User logged out', ['user_id' => $row->user_id, 'via' => 'refresh_token']);
                }
            }
        }

        return AuthCookies::clear(response()->json(['success' => true, 'redirect' => '/auth/login']), $request);
    }
}
