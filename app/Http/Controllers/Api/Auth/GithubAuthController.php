<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\GithubLoginService;
use App\Support\SecurityLog;
use Illuminate\Http\Request;

/**
 * الدخول / إنشاء الحساب بـ GitHub (ويب بس — نافذة OAuth مبتشتغلش جوه الـ WebView بتاع الأندرويد).
 *
 *  GET  /auth/github/config   { enabled }
 *  POST /auth/github/start    { nonce } -> { url }   (الفرونت يفتح الـ url في popup)
 *  POST /auth/github/claim    { nonce } -> { pending:true } أو نفس رد /auth/google (دخول / needs_registration / 2FA)
 *
 * الـ callback (GET /github/callback) مشترك مع "Connect GitHub": الـ state اللي بيبدأ بـ gl_ بيروح لـ GithubLoginService.
 * بعد التحقق كل حاجة بتعدّي من نفس مسار جوجل: GoogleAuthController::handleVerified()، وصفحة "كمّل بياناتك"
 * و /auth/google/complete و /auth/google/restore بيشتغلوا لـ GitHub كمان.
 */
class GithubAuthController extends Controller
{
    public function __construct(
        private GithubLoginService $github,
        private GoogleAuthController $social
    ) {
    }

    public function config()
    {
        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data'    => ['enabled' => $this->github->isConfigured()],
            'errors'  => null,
            'meta'    => (object) [],
        ]);
    }

    public function start(Request $request)
    {
        $ar = $request->header('X-Locale', 'en') === 'ar';
        $nonce = (string) $request->input('nonce', '');
        if (!GithubLoginService::validNonce($nonce)) {
            return $this->fail($ar ? 'طلب غير صالح.' : 'Invalid request.', 422);
        }
        if (!$this->github->isConfigured()) {
            return $this->fail($ar ? 'الدخول بـ GitHub مش متفعّل على المنصة لسه.' : 'GitHub sign-in is not set up on this platform yet.', 503, ['code' => 'not_configured']);
        }

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data'    => ['url' => $this->github->start($nonce)],
            'errors'  => null,
            'meta'    => (object) [],
        ]);
    }

    public function claim(Request $request)
    {
        $ar = $request->header('X-Locale', 'en') === 'ar';
        $locale = $ar ? 'ar' : 'en';
        $nonce = (string) $request->input('nonce', '');
        if (!GithubLoginService::validNonce($nonce)) {
            return $this->fail($ar ? 'طلب غير صالح.' : 'Invalid request.', 422);
        }

        $r = $this->github->claim($nonce);
        if ($r === null) {
            return response()->json(['success' => true, 'message' => 'Pending.', 'data' => ['pending' => true], 'errors' => null, 'meta' => (object) []]);
        }

        if (isset($r['error'])) {
            SecurityLog::write('GitHub sign-in rejected', ['ip' => $request->ip(), 'reason' => $r['error']]);
            return match ($r['error']) {
                'denied' => $this->fail($ar ? 'اتلغى الدخول بـ GitHub.' : 'GitHub sign-in was cancelled.', 422, ['code' => 'denied']),
                'no_verified_email' => $this->fail($ar
                    ? 'مفيش إيميل متأكَّد منه على حساب GitHub ده. أكّد إيميلك من إعدادات GitHub وجرّب تاني.'
                    : 'This GitHub account has no verified email. Verify an email in your GitHub settings and try again.', 422, ['code' => 'no_verified_email']),
                'private_email' => $this->fail($ar
                    ? 'حساب GitHub ده بيخفي إيميله الحقيقي (noreply). ضيف إيميل متأكَّد منه وفعّل ظهوره، أو سجّل بالإيميل العادي.'
                    : 'This GitHub account only exposes a private noreply address. Add a verified email to GitHub, or sign up with your email instead.', 422, ['code' => 'private_email']),
                default => $this->fail($ar ? 'مقدرناش نتأكد من حساب GitHub. جرّب تاني.' : "We couldn't verify your GitHub account. Please try again.", 401),
            };
        }

        $m = $r['profile'];
        $m['provider'] = 'github';
        return $this->social->handleVerified($request, $m, $locale);
    }

    private function fail(string $message, int $status, ?array $errors = null)
    {
        return response()->json(['success' => false, 'message' => $message, 'data' => null, 'errors' => $errors, 'meta' => (object) []], $status);
    }
}
