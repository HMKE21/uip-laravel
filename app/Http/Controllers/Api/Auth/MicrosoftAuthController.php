<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\MicrosoftIdTokenVerifier;
use App\Support\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * الدخول / إنشاء الحساب بمايكروسوفت (حساب شخصي أو جامعة/شغل).
 *
 *  GET  /auth/microsoft/config   { enabled, client_id, authority }
 *  POST /auth/microsoft          { id_token }
 *
 * التوكن بيتجاب من الفرونت (OAuth code + PKCE في popup)، والسيرفر هو اللي بيتحقق منه. بعد كده كل حاجة
 * (دخول / إنشاء حساب طالب / استرجاع حساب محذوف / 2FA / الجهاز / الصيانة) بتعدّي من نفس مسار جوجل:
 * GoogleAuthController::handleVerified()، وصفحة "كمّل بياناتك" و /auth/google/complete و /auth/google/restore
 * بيشتغلوا لمايكروسوفت كمان (التوكن بيشيل اسم المزوّد).
 */
class MicrosoftAuthController extends Controller
{
    public function __construct(
        private MicrosoftIdTokenVerifier $microsoft,
        private GoogleAuthController $social
    ) {
    }

    public function config()
    {
        $id = $this->microsoft->clientId();
        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data'    => [
                'enabled'   => $id !== '',
                'client_id' => $id ?: null,
                'authority' => (string) config('services.microsoft.authority', 'https://login.microsoftonline.com/common'),
            ],
            'errors'  => null,
            'meta'    => (object) [],
        ]);
    }

    public function submit(Request $request)
    {
        $ar = $request->header('X-Locale', 'en') === 'ar';
        $locale = $ar ? 'ar' : 'en';
        $v = Validator::make($request->all(), ['id_token' => 'required|string|max:8192']);
        if ($v->fails()) {
            return $this->fail($v->errors()->first(), 422);
        }

        $m = $this->microsoft->verify((string) $request->input('id_token'));
        if (!$m) {
            $why = $this->microsoft->lastError;
            SecurityLog::write('Microsoft sign-in rejected', ['ip' => $request->ip(), 'reason' => $why]);
            if ($why === 'email_unverified') {
                return $this->fail($ar
                    ? 'مقدرناش نتأكد إن الإيميل ده بتاعك. ادخل بحساب مايكروسوفت شخصي، أو اطلب من الأدمن يفعّل التأكيد لحسابات الجامعة.'
                    : "We couldn't confirm that you own this email. Sign in with a personal Microsoft account, or ask the admin to enable email verification for work/school accounts.", 422, ['code' => 'email_unverified']);
            }
            if ($why === 'no_email') {
                return $this->fail($ar ? 'حساب مايكروسوفت ده مفيهوش إيميل. جرّب حساب تاني.' : 'This Microsoft account has no email address. Try another account.', 422, ['code' => 'no_email']);
            }
            return $this->fail($ar ? 'مقدرناش نتأكد من حساب مايكروسوفت. جرّب تاني.' : "We couldn't verify your Microsoft account. Please try again.", 401);
        }

        $m['provider'] = 'microsoft';
        return $this->social->handleVerified($request, $m, $locale);
    }

    private function fail(string $message, int $status, ?array $errors = null)
    {
        return response()->json(['success' => false, 'message' => $message, 'data' => null, 'errors' => $errors, 'meta' => (object) []], $status);
    }
}
