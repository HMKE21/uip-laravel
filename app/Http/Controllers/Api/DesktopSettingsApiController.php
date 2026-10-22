<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\DesktopResetService;
use App\Services\DriveService;
use App\Services\MailService;
use App\Services\OtpService;
use App\Support\OtpGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * /api/v1/desktop/* — إعدادات سطح المكتب ("جهازي") + باسورد شاشة القفل. أي مستخدم مسجّل دخول.
 *
 *  GET  settings            الإعدادات + هل القفل متفعّل (مفيش صورة الخلفية هنا — بس wp = بصمتها)
 *  PUT  settings            حفظ الإعدادات (الأحدث بيكسب، لو الجهاز قديم بيرجع stale:true ومعاه اللي على السيرفر)
 *  GET  wallpaper           صورة الخلفية المخصصة { dataUrl }
 *  PUT  wallpaper           رفع/تغيير صورة الخلفية
 *  POST lock/set            تفعيل/تغيير باسورد القفل (محتاج باسورد الحساب أو باسورد القفل الحالي)
 *  POST lock/verify         فتح القفل
 *  POST lock/disable        إلغاء باسورد القفل
 *  POST lock/otp            "نسيت الباسورد": رمز 6 أرقام على إيميل الحساب
 *  POST lock/reset          الرمز + باسورد جديد
 *  GET  reset               حالة إعادة التهيئة: {pending, expires_at, seconds_left, exam_active}
 *  POST reset               إعادة تهيئة الجهاز (محتاج confirm=RESET وباسورد الحساب): الملفات للسلة 24 ساعة + الإعدادات/الويدجت/باسورد القفل بتتمسح، وفيه تراجع
 *  POST reset/undo          تراجع عن إعادة التهيئة (جوه الـ 24 ساعة)
 *
 * الباسورد بيتخزن bcrypt بس، وكل المحاولات الغلط محكومة بـ OtpGuard (نفس حماية رموز OTP): 5 غلطات => حظر 10 دقايق.
 * ملاحظة: ده قفل لواجهة سطح المكتب (خصوصية على نفس الجهاز) — مش بديل لتسجيل الخروج.
 */
class DesktopSettingsApiController extends Controller
{
    public const OTP_PURPOSE = 'desktop_lock';
    private const MAX_WALLPAPER_CHARS = 4_000_000;
    private const MAX_ICONS = 600;

    public function __construct(private OtpService $otp, private MailService $mail, private DriveService $drive, private DesktopResetService $resets)
    {
    }

    // -- helpers ----------------------------------------------------------------------------------------

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

    private function row(int $uid): object
    {
        $row = DB::table('desktop_settings')->where('user_id', $uid)->first();
        if (!$row) {
            DB::table('desktop_settings')->insertOrIgnore(['user_id' => $uid, 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('desktop_settings')->where('user_id', $uid)->first();
        }

        return $row;
    }

    private function lockScope(int $uid): string
    {
        return 'desktop_lock:' . $uid;
    }

    private function json(?string $s): array
    {
        $v = $s ? json_decode($s, true) : null;

        return is_array($v) ? $v : [];
    }

    private function payload(object $row): array
    {
        $prefs = $this->json($row->prefs);

        return [
            'exists'      => $row->updated_ms > 0,
            'updatedAt'   => (int) $row->updated_ms,
            'prefs'       => $prefs ?: (object) [],
            'icons'       => $this->json($row->icons) ?: (object) [],
            'mode'        => $row->mode,
            'locale'      => $row->locale,
            'wp'          => $row->wallpaper ? (string) $row->wp_hash : '',
            'lockEnabled' => !empty($row->lock_hash),
        ];
    }

    private function blocked(int $uid, bool $ar): ?JsonResponse
    {
        $secs = OtpGuard::blockedSeconds($this->lockScope($uid));
        if ($secs > 0) {
            return $this->apiError(OtpGuard::message($ar, $secs), ['retry_after' => $secs], 429);
        }

        return null;
    }

    /** غلطة باسورد: بتتسجّل، ولو دي اللي فعّلت الحظر بيرجّع 429. */
    private function wrong(int $uid, bool $ar, string $msg, string $field = 'password'): JsonResponse
    {
        $secs = OtpGuard::recordFailure($this->lockScope($uid));
        if ($secs > 0) {
            return $this->apiError(OtpGuard::message($ar, $secs), ['retry_after' => $secs], 429);
        }

        return $this->apiError($msg, [$field => ['invalid']], 422);
    }

    private function lockOk(object $row, string $given): bool
    {
        return !empty($row->lock_hash) && $given !== '' && Hash::check($given, $row->lock_hash);
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

    private function badNewPassword(Request $request): ?JsonResponse
    {
        $pw = $request->input('password');
        $ar = $this->ar($this->user($this->uid($request)));
        if (!is_string($pw) || mb_strlen($pw) < 4) {
            return $this->apiError($ar ? 'الباسورد لازم يكون 4 حروف/أرقام على الأقل.' : 'The password must be at least 4 characters.', ['password' => ['too_short']], 422);
        }
        if (strlen($pw) > 72) {
            return $this->apiError($ar ? 'الباسورد طويل جدًا (الحد 72 حرف).' : 'The password is too long (72 characters max).', ['password' => ['too_long']], 422);
        }

        return null;
    }

    private function mask(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $keep = mb_substr($name, 0, min(2, max(1, mb_strlen($name) - 1)));

        return $keep . str_repeat('•', max(2, mb_strlen($name) - mb_strlen($keep))) . '@' . $domain;
    }

    // -- settings ---------------------------------------------------------------------------------------

    public function show(Request $request): JsonResponse
    {
        return $this->apiSuccess($this->payload($this->row($this->uid($request))), 'Desktop settings retrieved successfully.');
    }

    private const PREF_ENUMS = ['iconSize' => ['s', 'm', 'l'], 'taskbarAlign' => ['center', 'start']];

    public function update(Request $request): JsonResponse
    {
        $uid = $this->uid($request);
        $request->validate([
            'updatedAt' => 'required|numeric|min:0',
            'prefs'     => 'nullable|array',
            'icons'     => 'nullable|array',
            'mode'      => 'nullable|string|in:light,classic,dark',
            'locale'    => 'nullable|string|in:ar,en',
        ]);
        $row = $this->row($uid);
        $at = min((int) $request->input('updatedAt'), (int) (microtime(true) * 1000) + 300_000);
        if ($at < (int) $row->updated_ms) {
            return $this->apiSuccess($this->payload($row) + ['stale' => true], 'A newer version exists.');
        }

        $in = (array) $request->input('prefs', []);
        $prefs = [];
        $wp = (string) ($in['wallpaper'] ?? 'aurora');
        $prefs['wallpaper'] = ($wp === 'custom' || preg_match('/^[a-z0-9_-]{1,24}$/', $wp)) ? $wp : 'aurora';
        $acc = (string) ($in['accent'] ?? '#6366f1');
        $prefs['accent'] = preg_match('/^#[0-9a-fA-F]{6}$/', $acc) ? $acc : '#6366f1';
        foreach (self::PREF_ENUMS as $k => $allowed) {
            $def = $k === 'iconSize' ? 'm' : 'center';
            $v = (string) ($in[$k] ?? $def);
            $prefs[$k] = in_array($v, $allowed, true) ? $v : $def;
        }
        $prefs['brightness'] = max(40, min(100, (int) ($in['brightness'] ?? 100)));
        // icons the person removed from the desktop / Dock (restorable from Settings): short keys only, so they follow the account to every device
        foreach (['hiddenIcons', 'hiddenDock'] as $hk) {
            $list = [];
            foreach (array_slice((array) ($in[$hk] ?? []), 0, 32) as $v) {
                if (is_string($v) && preg_match('/^[a-z]{1,16}(:[a-z]{1,16})?$/', $v)) {
                    $list[] = $v;
                }
            }
            $prefs[$hk] = array_values(array_unique($list));
        }
        // اختصارات سطح المكتب: ids عناصر "جهازي" (الاختصار بيشاور على العنصر، مفيش نسخ)
        $sc = [];
        foreach (array_slice((array) ($in['shortcuts'] ?? []), 0, 100) as $v) {
            if ((is_int($v) || (is_string($v) && ctype_digit($v))) && (int) $v > 0) {
                $sc[] = (int) $v;
            }
        }
        $prefs['shortcuts'] = array_values(array_unique($sc));
        if ($prefs['wallpaper'] === 'custom' && empty($row->wallpaper)) {
            $prefs['wallpaper'] = 'aurora'; // مفيش صورة مرفوعة لسه
        }

        $icons = [];
        foreach ((array) $request->input('icons', []) as $k => $p) {
            if (count($icons) >= self::MAX_ICONS) {
                break;
            }
            if (!is_string($k) || strlen($k) > 64 || !is_array($p)) {
                continue;
            }
            if (isset($p['x'], $p['y']) && is_numeric($p['x']) && is_numeric($p['y'])) { // free position, in pixels
                $x = (int) round((float) $p['x']);
                $y = (int) round((float) $p['y']);
                if ($x >= 0 && $y >= 0 && $x < 20000 && $y < 20000) {
                    $icons[$k] = ['x' => $x, 'y' => $y];
                }
            } elseif (isset($p['c'], $p['r']) && is_numeric($p['c']) && is_numeric($p['r'])) { // old grid cell
                $c = (int) $p['c'];
                $r = (int) $p['r'];
                if ($c >= 0 && $r >= 0 && $c < 1000 && $r < 1000) {
                    $icons[$k] = ['c' => $c, 'r' => $r];
                }
            }
        }

        DB::table('desktop_settings')->where('user_id', $uid)->update([
            'prefs'      => json_encode($prefs),
            'icons'      => json_encode($icons ?: new \stdClass()),
            'mode'       => $request->input('mode') ?: $row->mode,
            'locale'     => $request->input('locale') ?: $row->locale,
            'updated_ms' => max(1, $at),
            'updated_at' => now(),
        ]);

        return $this->apiSuccess($this->payload($this->row($uid)) + ['stale' => false], 'Desktop settings saved successfully.');
    }

    public function wallpaper(Request $request): JsonResponse
    {
        $row = $this->row($this->uid($request));

        return $this->apiSuccess(['dataUrl' => $row->wallpaper ?: null, 'wp' => $row->wallpaper ? (string) $row->wp_hash : ''], 'Wallpaper retrieved successfully.');
    }

    public function saveWallpaper(Request $request): JsonResponse
    {
        $uid = $this->uid($request);
        $ar = $this->ar($this->user($uid));
        $data = $request->input('dataUrl');
        $row = $this->row($uid);

        if ($data === null) {
            DB::table('desktop_settings')->where('user_id', $uid)->update(['wallpaper' => null, 'wp_hash' => null, 'updated_at' => now()]);

            return $this->apiSuccess(['wp' => ''], 'Wallpaper removed.');
        }
        $bad = fn () => $this->apiError($ar ? 'الصورة غير صالحة أو كبيرة جدًا.' : 'The image is invalid or too large.', ['dataUrl' => ['invalid']], 422);
        // (prefix checked on its own — a regex over a multi-MB string can hit PCRE limits)
        if (!is_string($data) || strlen($data) > self::MAX_WALLPAPER_CHARS || !preg_match('#^data:image/(jpeg|png|webp);base64,#', substr($data, 0, 40), $m)) {
            return $bad();
        }
        $bin = base64_decode(substr($data, strlen($m[0])), true);
        if ($bin === false || $bin === '' || @getimagesizefromstring($bin) === false) {
            return $bad();
        }
        $hash = sha1($data);
        if ($row->wp_hash !== $hash) {
            DB::table('desktop_settings')->where('user_id', $uid)->update(['wallpaper' => $data, 'wp_hash' => $hash, 'updated_at' => now()]);
        }

        return $this->apiSuccess(['wp' => $hash], 'Wallpaper saved successfully.');
    }

    // -- factory reset ----------------------------------------------------------------------------------

    /** GET reset — حالة المهلة + هل فيه امتحان شغّال (الواجهة بتقفل المسح وقتها). */
    public function resetStatus(Request $request): JsonResponse
    {
        $uid = $this->uid($request);

        return $this->apiSuccess($this->resets->status($uid) + ['exam_active' => $this->resets->examActive($uid)], 'Reset status retrieved successfully.');
    }

    /**
     * "إعادة تهيئة الجهاز": كل ملفات "جهازي" بتتنقل للسلة وتتمسح نهائيًا بعد 24 ساعة (لحد وقتها: تراجع)، وإعدادات سطح المكتب
     * والويدجت والخلفية وباسورد القفل بترجع للافتراضي (اللقطة محفوظة للتراجع). الحساب والجلسات بيفضلوا زي ما هم.
     * الحماية: لازم confirm=RESET + باسورد الحساب (حساب Google بدون باسورد: الجلسة كفاية)، والغلط بيتحسب في OtpGuard.
     * ممنوعة أثناء امتحان شغّال (409).
     */
    public function reset(Request $request): JsonResponse
    {
        $uid = $this->uid($request);
        $user = $this->user($uid);
        $ar = $this->ar($user);
        if (!in_array((string) $request->attributes->get('uip_role'), (array) config('drive.roles'), true)) {
            return $this->apiError('My PC is not available for your account type.', null, 403);
        }
        if ((string) $request->input('confirm', '') !== 'RESET') {
            return $this->apiError($ar ? 'لازم تأكّد إعادة التهيئة.' : 'Please confirm the reset.', ['confirm' => ['required']], 422);
        }
        if ($this->resets->examActive($uid)) {
            return $this->apiError($ar ? 'فيه امتحان شغّال دلوقتي. سلّم الامتحان الأول وبعدها أعد التهيئة.' : 'An exam is in progress. Submit it first, then reset.', ['exam' => ['active']], 409);
        }
        if ($b = $this->blocked($uid, $ar)) {
            return $b;
        }
        if (!$this->accountOk($user, (string) $request->input('account_password', ''))) {
            return $this->wrong($uid, $ar, $ar ? 'باسورد الحساب غير صحيح.' : 'Your account password is incorrect.', 'account_password');
        }
        OtpGuard::recordSuccess($this->lockScope($uid));

        try {
            $removed = $this->resets->reset($uid);
        } catch (\Throwable $e) {
            Log::error('desktop reset failed', ['uid' => $uid, 'error' => $e->getMessage()]);

            return $this->apiError($ar ? 'حصلت مشكلة أثناء إعادة التهيئة. حاول تاني.' : 'Something went wrong while resetting. Please try again.', null, 500);
        }
        $this->otp->forget(self::OTP_PURPOSE, $uid);
        Log::info('desktop reset', ['uid' => $uid, 'removed' => $removed]);

        return $this->apiSuccess($this->resets->status($uid) + ['removed' => $removed], 'Your PC was reset.');
    }

    /** POST reset/undo — رجّع الملفات والإعدادات (جوه الـ 24 ساعة بس). */
    public function resetUndo(Request $request): JsonResponse
    {
        $uid = $this->uid($request);
        try {
            $restored = $this->resets->undo($uid);
        } catch (\App\Support\DriveException $e) {
            $c = $e->getCode();

            return $this->apiError($e->getMessage(), null, $c >= 400 && $c < 600 ? $c : 422);
        }
        Log::info('desktop reset undone', ['uid' => $uid, 'restored' => $restored]);

        return $this->apiSuccess(['restored' => $restored], 'Your PC was restored.');
    }

    // -- lock password ----------------------------------------------------------------------------------

    public function lockSet(Request $request): JsonResponse
    {
        $uid = $this->uid($request);
        $user = $this->user($uid);
        $ar = $this->ar($user);
        if ($bad = $this->badNewPassword($request)) {
            return $bad;
        }
        if ($b = $this->blocked($uid, $ar)) {
            return $b;
        }
        $row = $this->row($uid);
        // لازم تثبت إنك صاحب الحساب: باسورد الحساب، أو (لو القفل متفعّل) باسورد القفل الحالي
        $ok = $this->accountOk($user, (string) $request->input('account_password', ''))
            || $this->lockOk($row, (string) $request->input('current_password', ''));
        if (!$ok) {
            return $this->wrong($uid, $ar, $ar ? 'باسورد الحساب (أو باسورد القفل الحالي) غير صحيح.' : 'Your account password (or the current lock password) is incorrect.', 'account_password');
        }
        DB::table('desktop_settings')->where('user_id', $uid)->update(['lock_hash' => Hash::make((string) $request->input('password')), 'lock_changed_at' => now(), 'updated_at' => now()]);
        OtpGuard::recordSuccess($this->lockScope($uid));
        $this->otp->forget(self::OTP_PURPOSE, $uid);

        return $this->apiSuccess(['lockEnabled' => true], 'Lock password saved successfully.');
    }

    public function lockVerify(Request $request): JsonResponse
    {
        $uid = $this->uid($request);
        $ar = $this->ar($this->user($uid));
        if ($b = $this->blocked($uid, $ar)) {
            return $b;
        }
        $row = $this->row($uid);
        if (empty($row->lock_hash)) {
            return $this->apiSuccess(['ok' => true, 'lockEnabled' => false], 'No lock password is set.');
        }
        if (!$this->lockOk($row, (string) $request->input('password', ''))) {
            return $this->wrong($uid, $ar, $ar ? 'الباسورد غير صحيح.' : 'Incorrect password.');
        }
        OtpGuard::recordSuccess($this->lockScope($uid));

        return $this->apiSuccess(['ok' => true, 'lockEnabled' => true], 'Unlocked.');
    }

    public function lockDisable(Request $request): JsonResponse
    {
        $uid = $this->uid($request);
        $user = $this->user($uid);
        $ar = $this->ar($user);
        if ($b = $this->blocked($uid, $ar)) {
            return $b;
        }
        $row = $this->row($uid);
        $given = (string) $request->input('password', '');
        if (!empty($row->lock_hash) && !$this->lockOk($row, $given) && !($given !== '' && !empty($user->password_hash) && Hash::check($given, $user->password_hash))) {
            return $this->wrong($uid, $ar, $ar ? 'الباسورد غير صحيح.' : 'Incorrect password.');
        }
        DB::table('desktop_settings')->where('user_id', $uid)->update(['lock_hash' => null, 'lock_changed_at' => now(), 'updated_at' => now()]);
        OtpGuard::recordSuccess($this->lockScope($uid));

        return $this->apiSuccess(['lockEnabled' => false], 'Lock password removed.');
    }

    /** "نسيت الباسورد": رمز 6 أرقام على إيميل الحساب (نفس بنية رمز نسيان باسورد الحساب). */
    public function lockOtp(Request $request): JsonResponse
    {
        $uid = $this->uid($request);
        $user = $this->user($uid);
        $ar = $this->ar($user);
        if (!$user || !$user->email) {
            return $this->apiError($ar ? 'الحساب مفيهوش إيميل.' : 'This account has no email address.', null, 422);
        }
        if (empty($this->row($uid)->lock_hash)) {
            return $this->apiError($ar ? 'مفيش باسورد قفل متفعّل.' : 'No lock password is set.', null, 422);
        }
        $wait = $this->otp->secondsUntilResend(self::OTP_PURPOSE, $uid);
        if ($wait > 0) {
            return $this->apiSuccess(['retry_after' => $wait, 'email' => $this->mask((string) $user->email)], 'A code was sent recently.');
        }
        $code = $this->otp->issue(self::OTP_PURPOSE, $uid);
        if ($code === '') {
            return $this->apiError(OtpGuard::message($ar), null, 429);
        }
        $sent = $this->mail->sendDesktopLockOtp((string) $user->email, (string) ($user->full_name ?? ''), $code, OtpService::TTL_MINUTES, $request->ip(), $ar ? 'ar' : 'en');
        if (!$sent) {
            $this->otp->forget(self::OTP_PURPOSE, $uid);
            Log::error('Desktop lock OTP email failed', ['user_id' => $uid, 'error' => $this->mail->getLastError()]);

            return $this->apiError($ar ? 'مقدرناش نبعت الإيميل دلوقتي. جرّب بعد شوية.' : 'We could not send the email right now. Try again shortly.', null, 502);
        }

        return $this->apiSuccess(['retry_after' => OtpService::RESEND_AFTER_SECONDS, 'email' => $this->mask((string) $user->email)], 'Code sent.');
    }

    public function lockReset(Request $request): JsonResponse
    {
        $uid = $this->uid($request);
        $ar = $this->ar($this->user($uid));
        $request->validate(['code' => 'required|string|max:12']);
        if ($bad = $this->badNewPassword($request)) {
            return $bad;
        }
        $res = $this->otp->check(self::OTP_PURPOSE, $uid, (string) $request->input('code'));
        if ($res !== OtpService::OK) {
            return $this->apiError(OtpService::errorMessage($res, $ar), ['code' => [$res]], $res === OtpService::LOCKED ? 429 : 422);
        }
        $this->row($uid);
        DB::table('desktop_settings')->where('user_id', $uid)->update(['lock_hash' => Hash::make((string) $request->input('password')), 'lock_changed_at' => now(), 'updated_at' => now()]);
        $this->otp->forget(self::OTP_PURPOSE, $uid);
        OtpGuard::recordSuccess($this->lockScope($uid));

        return $this->apiSuccess(['lockEnabled' => true], 'Lock password reset successfully.');
    }
}
