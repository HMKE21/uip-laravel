<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

/**
 * POST /auth/resend-verification { email } — يبعت لينك تأكيد جديد لحساب pending.
 * الرد دايمًا نفس الرسالة (منع user enumeration)، ومحدود بـ throttle + cooldown لكل حساب.
 */
class ResendVerificationController extends Controller
{
    public function __construct(private EmailVerificationService $verification)
    {
    }

    public function submit(Request $request)
    {
        $v = Validator::make($request->all(), ['email' => 'required|email']);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }

        // Same idea as ForgotPasswordController: a cooldown per address for known and unknown addresses alike,
        // so `retry_after` is identical either way and doesn't leak which accounts exist.
        $email = strtolower(trim((string) $request->input('email')));
        $cooldownKey = 'verify_resend_cd:' . sha1($email);
        $now = time();
        $cooldown = EmailVerificationService::RESEND_COOLDOWN;
        $started = Cache::add($cooldownKey, $now + $cooldown, $cooldown);
        $retryAfter = $started ? $cooldown : max(1, (int) Cache::get($cooldownKey, $now + 1) - $now);

        $user = $started ? User::whereRaw('LOWER(email) = ?', [$email])->first() : null;
        if ($user && EmailVerificationService::blocksLogin($user)) {
            $last = $this->verification->lastSentAt((int) $user->id);
            if (!$last || $last->diffInSeconds(now()) >= EmailVerificationService::RESEND_COOLDOWN) {
                $this->verification->issueAndSend($user);
            }
        }

        $ar = $request->header('X-Locale', 'en') === 'ar';
        return response()->json([
            'success' => true,
            'retry_after' => $retryAfter,
            'data' => ['retry_after' => $retryAfter],
            'message' => $ar
                ? 'لو فيه حساب بهذا البريد محتاج تأكيد، بعتنا له رسالة جديدة.'
                : 'If an account with that email needs confirmation, we sent a new message.',
        ]);
    }
}
