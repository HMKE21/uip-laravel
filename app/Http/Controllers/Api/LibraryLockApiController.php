<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\OtpGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * /api/v1/library-lock/* — قفل اختياري لصفحة "مكتبتي". أي مستخدم مسجّل دخول.
 *
 *  GET  /                 الحالة: {enabled, mode, desktopLock, hasAccountPassword, fallback}
 *  POST /set              تفعيل/تغيير القفل: mode = own | account | mypc
 *                           own     محتاج password جديد (4–72 حرف)
 *                           account بيتفتح بباسورد الحساب
 *                           mypc    بيتفتح بباسورد قفل "جهازي" (لازم يكون متفعّل هناك)
 *                         ولازم تثبت إنك صاحب الحساب: account_password (أو current_password للقفل الحالي)
 *  POST /verify           فتح القفل: password (+ use_account=true لو نسيت باسورد القفل: بيتفتح بباسورد الحساب)
 *  POST /disable          إلغاء القفل: password (باسورد القفل الحالي أو باسورد الحساب)
 *
 * الباسورد الخاص بيتخزن bcrypt بس، وكل المحاولات الغلط محكومة بـ OtpGuard: 5 غلطات => حظر 10 دقايق (نطاق منفصل عن قفل "جهازي").
 * ملاحظة: ده قفل لواجهة المكتبة (خصوصية على نفس الجهاز) — الملفات نفسها لسه في "جهازي"، وده مش بديل لتسجيل الخروج.
 */
class LibraryLockApiController extends Controller
{
    private const MODES = ['own', 'account', 'mypc'];

    private function uid(Request $request): int
    {
        return (int) $request->attributes->get('uip_user_id');
    }

    private function user(int $uid): ?User
    {
        return User::find($uid);
    }

    private function ar(?User $user): bool
    {
        return (($user->preferred_language ?? 'ar') !== 'en');
    }

    private function scope(int $uid): string
    {
        return 'library_lock:' . $uid;
    }

    private function row(int $uid): ?object
    {
        return DB::table('library_locks')->where('user_id', $uid)->first();
    }

    private function desktopHash(int $uid): ?string
    {
        $h = DB::table('desktop_settings')->where('user_id', $uid)->value('lock_hash');

        return $h ? (string) $h : null;
    }

    private function hasAccountPassword(?User $user): bool
    {
        return $user && !empty($user->password_hash);
    }

    /** باسورد الحساب صح؟ (حساب Google بدون باسورد = الجلسة نفسها كفاية). */
    private function accountOk(?User $user, string $given): bool
    {
        if (!$user) {
            return false;
        }
        if (empty($user->password_hash)) {
            return true;
        }

        return $given !== '' && Hash::check($given, $user->password_hash);
    }

    /** باسورد القفل الحالي صح؟ حسب الوضع. لو وضع "جهازي" وقفل جهازي اتشال بيرجع false (والحل باسورد الحساب). */
    private function secretOk(object $row, int $uid, string $given): bool
    {
        if ($given === '') {
            return false;
        }
        if ($row->mode === 'own') {
            return !empty($row->lock_hash) && Hash::check($given, $row->lock_hash);
        }
        if ($row->mode === 'mypc') {
            $h = $this->desktopHash($uid);

            return $h !== null && Hash::check($given, $h);
        }

        $user = $this->user($uid);

        return $this->hasAccountPassword($user) && Hash::check($given, $user->password_hash);
    }

    private function blocked(int $uid, bool $ar): ?JsonResponse
    {
        $secs = OtpGuard::blockedSeconds($this->scope($uid));
        if ($secs > 0) {
            return $this->apiError(OtpGuard::message($ar, $secs), ['retry_after' => $secs], 429);
        }

        return null;
    }

    private function wrong(int $uid, bool $ar, string $msg, string $field = 'password'): JsonResponse
    {
        $secs = OtpGuard::recordFailure($this->scope($uid));
        if ($secs > 0) {
            return $this->apiError(OtpGuard::message($ar, $secs), ['retry_after' => $secs], 429);
        }

        return $this->apiError($msg, [$field => ['invalid']], 422);
    }

    private function status(int $uid): array
    {
        $row = $this->row($uid);
        $user = $this->user($uid);

        return [
            'enabled'            => (bool) $row,
            'mode'               => $row ? $row->mode : null,
            'desktopLock'        => $this->desktopHash($uid) !== null,
            'hasAccountPassword' => $this->hasAccountPassword($user),
            // وضع "جهازي" لكن قفل جهازي اتشال: بيتفتح بباسورد الحساب لحد ما تفعّله تاني أو تغيّر الوضع
            'fallback'           => $row && $row->mode === 'mypc' && $this->desktopHash($uid) === null,
            'changedAt'          => $row && $row->changed_at ? (string) $row->changed_at : null,
        ];
    }

    public function show(Request $request): JsonResponse
    {
        return $this->apiSuccess($this->status($this->uid($request)));
    }

    public function set(Request $request): JsonResponse
    {
        $uid = $this->uid($request);
        $user = $this->user($uid);
        $ar = $this->ar($user);
        $mode = (string) $request->input('mode', '');
        if (!in_array($mode, self::MODES, true)) {
            return $this->apiError($ar ? 'اختار طريقة القفل.' : 'Choose a lock type.', ['mode' => ['invalid']], 422);
        }
        if ($b = $this->blocked($uid, $ar)) {
            return $b;
        }

        $hash = null;
        if ($mode === 'own') {
            $pw = $request->input('password');
            if (!is_string($pw) || mb_strlen($pw) < 4) {
                return $this->apiError($ar ? 'الباسورد لازم يكون 4 حروف/أرقام على الأقل.' : 'The password must be at least 4 characters.', ['password' => ['too_short']], 422);
            }
            if (strlen($pw) > 72) {
                return $this->apiError($ar ? 'الباسورد طويل جدًا (الحد 72 حرف).' : 'The password is too long (72 characters max).', ['password' => ['too_long']], 422);
            }
            $hash = Hash::make($pw);
        } elseif ($mode === 'account' && !$this->hasAccountPassword($user)) {
            // حساب Google/بدون باسورد: القفل بباسورد الحساب هيتفتح بأي حاجة، فمنعناه.
            return $this->apiError($ar ? 'حسابك مالوش باسورد. استخدم باسورد خاص بالمكتبة.' : 'Your account has no password. Use a library-only password instead.', ['mode' => ['no_account_password']], 422);
        } elseif ($mode === 'mypc' && $this->desktopHash($uid) === null) {
            return $this->apiError($ar ? 'فعّل باسورد قفل "جهازي" الأول (الإعدادات > شاشة القفل).' : 'Set a My PC lock password first (Settings > Lock screen).', ['mode' => ['no_mypc_lock']], 422);
        }

        // لازم تثبت إنك صاحب الحساب: باسورد الحساب، أو (لو القفل متفعّل) باسورد القفل الحالي
        $row = $this->row($uid);
        $ok = $this->accountOk($user, (string) $request->input('account_password', ''))
            || ($row && $this->secretOk($row, $uid, (string) $request->input('current_password', '')));
        if (!$ok) {
            return $this->wrong($uid, $ar, $ar ? 'باسورد الحساب (أو باسورد القفل الحالي) غير صحيح.' : 'Your account password (or the current lock password) is incorrect.', 'account_password');
        }

        DB::table('library_locks')->updateOrInsert(
            ['user_id' => $uid],
            ['mode' => $mode, 'lock_hash' => $hash, 'changed_at' => now(), 'updated_at' => now(), 'created_at' => $row->created_at ?? now()]
        );
        OtpGuard::recordSuccess($this->scope($uid));

        return $this->apiSuccess($this->status($uid), 'Library lock saved.');
    }

    public function verify(Request $request): JsonResponse
    {
        $uid = $this->uid($request);
        $user = $this->user($uid);
        $ar = $this->ar($user);
        $row = $this->row($uid);
        if (!$row) {
            return $this->apiSuccess(['ok' => true, 'enabled' => false], 'The library is not locked.');
        }
        $given = (string) $request->input('password', '');
        // فاضي = مجرد سؤال "هل متقفلة؟" مش تخمين غلط، فمش بيتعدّ.
        if ($given === '') {
            return $this->apiError($ar ? 'اكتب الباسورد.' : 'Enter the password.', ['password' => ['required']], 422);
        }
        if ($b = $this->blocked($uid, $ar)) {
            return $b;
        }
        $useAccount = filter_var($request->input('use_account', false), FILTER_VALIDATE_BOOLEAN);
        $ok = ($useAccount || $row->mode === 'account' || ($row->mode === 'mypc' && $this->desktopHash($uid) === null))
            ? ($this->hasAccountPassword($user) && Hash::check($given, $user->password_hash))
            : $this->secretOk($row, $uid, $given);
        if (!$ok) {
            return $this->wrong($uid, $ar, $useAccount || $row->mode === 'account'
                ? ($ar ? 'باسورد الحساب غير صحيح.' : 'Incorrect account password.')
                : ($ar ? 'الباسورد غير صحيح.' : 'Incorrect password.'));
        }
        OtpGuard::recordSuccess($this->scope($uid));

        return $this->apiSuccess(['ok' => true, 'enabled' => true], 'Unlocked.');
    }

    public function disable(Request $request): JsonResponse
    {
        $uid = $this->uid($request);
        $user = $this->user($uid);
        $ar = $this->ar($user);
        if ($b = $this->blocked($uid, $ar)) {
            return $b;
        }
        $row = $this->row($uid);
        if ($row) {
            $given = (string) $request->input('password', '');
            $ok = $this->secretOk($row, $uid, $given)
                || ($this->hasAccountPassword($user) && $given !== '' && Hash::check($given, $user->password_hash));
            if (!$ok) {
                return $this->wrong($uid, $ar, $ar ? 'الباسورد غير صحيح.' : 'Incorrect password.');
            }
            DB::table('library_locks')->where('user_id', $uid)->delete();
        }
        OtpGuard::recordSuccess($this->scope($uid));

        return $this->apiSuccess($this->status($uid), 'Library lock removed.');
    }
}
