<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\MailService;
use App\Support\DisposableEmail;
use App\Support\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * طلب تغيير الإيميل من داخل الحساب (أي دور).
 *
 *  POST   /api/v1/auth/email-change  { new_email, current_password }
 *      - بيتحقق من كلمة السر، ويسجّل الإيميل الجديد كـ pending_email فقط (الإيميل الحالي ما بيتغيرش)،
 *        ويبعت لينك تفعيل على الإيميل الجديد صالح 24 ساعة.
 *      - الإيميل بيتغيّر فعليًا لما صاحب الحساب يفتح اللينك (ConfirmEmailChangeController).
 *        لو اللينك ماتفتحش أو انتهى، الإيميل يفضل زي ما هو.
 *  GET    /api/v1/auth/email-change  -> الإيميل الحالي + الطلب المعلّق (لو فيه)
 *  DELETE /api/v1/auth/email-change  -> إلغاء الطلب المعلّق
 */
class RequestEmailChangeController extends Controller
{
    private const TTL_HOURS = 24;

    public function show(Request $request)
    {
        $user = DB::table('users')->where('id', (int) $request->attributes->get('uip_user_id'))->first(['email', 'pending_email']);

        return $this->apiSuccess([
            'email'         => $user->email ?? null,
            'pending_email' => $user->pending_email ?? null,
        ], 'OK');
    }

    public function submit(Request $request, MailService $mail)
    {
        $userId = (int) $request->attributes->get('uip_user_id');

        $v = Validator::make($request->all(), [
            'new_email'        => 'required|string|email:rfc|max:190',
            'current_password' => 'nullable|string',
        ]);
        if ($v->fails()) {
            return $this->apiError($v->errors()->first(), $v->errors()->toArray(), 422);
        }

        $user = DB::table('users')->where('id', $userId)->whereNull('deleted_at')->first();
        if (!$user) {
            return $this->apiError('User not found.', null, 404);
        }

        // حسابات جوجل بدون كلمة سر مش بتطلبها؛ الباقي لازم يثبت كلمة السر.
        if (!empty($user->password_hash)) {
            $pw = (string) $request->input('current_password', '');
            if ($pw === '' || !password_verify($pw, (string) $user->password_hash)) {
                return $this->apiError('Current password is incorrect.', null, 422);
            }
        }

        $new = strtolower(trim((string) $request->input('new_email')));

        if ($new === strtolower((string) $user->email)) {
            return $this->apiError('This is already your email address.', null, 422);
        }
        if (DisposableEmail::isDisposable($new)) {
            return $this->apiError('Please use a real email address (temporary mail is not allowed).', null, 422);
        }
        if (DB::table('users')->whereRaw('LOWER(email) = ?', [$new])->exists()) {
            return $this->apiError('This email address is already used by another account.', null, 422);
        }

        $token = Str::random(64);
        DB::transaction(function () use ($userId, $new, $token) {
            // أي طلبات تغيير قديمة لسه ما اتفعلتش تتلغي — آخر طلب هو الوحيد الصالح.
            DB::table('email_verification_tokens')
                ->where('user_id', $userId)->where('purpose', 'email_change')->whereNull('used_at')
                ->update(['used_at' => now()]);

            DB::table('email_verification_tokens')->insert([
                'user_id'    => $userId,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addHours(self::TTL_HOURS),
                'purpose'    => 'email_change',
                'new_email'  => $new,
                'created_at' => now(),
            ]);
            DB::table('users')->where('id', $userId)->update(['pending_email' => $new]);
        });

        $locale = (string) ($user->preferred_language ?? 'ar');
        $base = rtrim((string) (config('app.frontend_url') ?: config('app.url', 'http://localhost')), '/');
        $sent = $mail->sendEmailChangeConfirmation($new, (string) ($user->full_name ?? ''), $base . '/auth/confirm-email-change?token=' . $token, self::TTL_HOURS, $locale);

        if (!$sent) {
            // مفيش فايدة من طلب معلّق مش واصل لحد.
            DB::table('email_verification_tokens')->where('token_hash', hash('sha256', $token))->update(['used_at' => now()]);
            DB::table('users')->where('id', $userId)->update(['pending_email' => null]);

            return $this->apiError('We could not send the confirmation email. Please try again later.', null, 503);
        }

        // تنبيه أمني للإيميل الحالي (best-effort).
        try {
            $ar = $locale === 'ar';
            $mail->sendNotificationEmail(
                (string) $user->email, (string) ($user->full_name ?? ''),
                $ar ? 'طلب تغيير البريد الإلكتروني' : 'Email change requested',
                $ar ? "تم طلب تغيير بريد حسابك إلى {$new}. لو مش أنت، غيّر كلمة السر فورًا." : "A request was made to change your account email to {$new}. If this wasn't you, change your password right away.",
                null, $ar ? 'ar' : 'en'
            );
        } catch (\Throwable $e) {
            report($e);
        }

        SecurityLog::write('Email change requested', ['user_id' => $userId, 'new_email' => $new]);

        return $this->apiSuccess(['pending_email' => $new], 'Confirmation email sent. Your email will change once you open the link.');
    }

    public function cancel(Request $request)
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        DB::table('email_verification_tokens')
            ->where('user_id', $userId)->where('purpose', 'email_change')->whereNull('used_at')
            ->update(['used_at' => now()]);
        DB::table('users')->where('id', $userId)->update(['pending_email' => null]);

        return $this->apiSuccess(null, 'Email change cancelled.');
    }
}
