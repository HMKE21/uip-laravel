<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Support\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** يطابق ConfirmEmailChangeController::handle() + AuthService::confirmEmailChange() القديمين. */
class ConfirmEmailChangeController extends Controller
{
    public function handle(Request $request)
    {
        $token = (string) $request->query('token', '');
        $genericError = 'This confirmation link is invalid, expired, or the new address is no longer available.';

        if ($token === '') {
            return $this->apiError($genericError, null, 422);
        }

        $hash = hash('sha256', $token);

        $row = DB::table('email_verification_tokens')
            ->where('token_hash', $hash)
            ->where('purpose', 'email_change')
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (!$row || !$row->new_email) {
            return $this->apiError($genericError, null, 422);
        }

        // Real e-mail only: a temporary-mail address can never become the account's address.
        if (\App\Support\DisposableEmail::isDisposable($row->new_email)) {
            DB::table('email_verification_tokens')->where('id', $row->id)->update(['used_at' => now()]);
            DB::table('users')->where('id', $row->user_id)->update(['pending_email' => null]);
            return $this->apiError($genericError, null, 422);
        }

        // العنوان الجديد بقى مستخدم من حد تاني في الفترة بين إنشاء التوكن واستخدامه
        if (DB::table('users')->whereRaw('LOWER(email) = ?', [strtolower($row->new_email)])->where('id', '!=', $row->user_id)->exists()) {
            DB::table('email_verification_tokens')->where('id', $row->id)->update(['used_at' => now()]);
            DB::table('users')->where('id', $row->user_id)->update(['pending_email' => null]);
            return $this->apiError($genericError, null, 422);
        }

        DB::table('users')->where('id', $row->user_id)->update([
            'email'          => $row->new_email,
            'pending_email'  => null,
            // فتح اللينك من الإيميل الجديد = إثبات ملكيته.
            'email_verified_at' => now(),
            'updated_at'     => now(),
        ]);
        DB::table('email_verification_tokens')->where('id', $row->id)->update(['used_at' => now()]);

        SecurityLog::write('Email address changed', ['user_id' => $row->user_id, 'new_email' => $row->new_email]);

        return $this->apiSuccess(['new_email' => $row->new_email], 'Email address changed successfully.');
    }
}
