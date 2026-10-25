<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DriveAdminSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * /api/v1/desktop/virtual-mode — "الوضع الافتراضي" (UIP OS).
 *
 *  GET  → { available, enabled, reason }
 *         available: الميزة شغّالة + الدور مسموح + "جهازي" مفتوح للحساب ده (مش مقفول من الأدمن ولا الحساب محظور منه).
 *         enabled  : اختيار الطالب المحفوظ (يتابعه على أي جهاز). بيرجع false دايمًا لو available=false.
 *  PUT  { enabled: bool } → نفس الشكل بعد الحفظ. 403 لو الوضع مش متاح للحساب.
 *
 * برّه middleware uip.drive عن قصد: التفضيل ده لازم يتقرأ حتى لو "جهازي" مقفول، عشان الفرونت يعرف يرجّع الطالب للمنصة العادية
 * بدل ما يحبسه في سطح مكتب مش شغّال. الفحص بتاع إتاحة "جهازي" بيتعمل هنا يدويًا بـ DriveAdminSettings::denial().
 * إعادة تهيئة الجهاز مبتمسحش العمود ده (DesktopResetService بيحدّث أعمدة محددة بس).
 */
class DesktopVirtualModeApiController extends Controller
{
    private function uid(Request $request): int
    {
        return (int) $request->attributes->get('uip_user_id');
    }

    private function role(Request $request): string
    {
        return (string) $request->attributes->get('uip_role');
    }

    private function hasColumn(): bool
    {
        return Schema::hasTable('desktop_settings') && Schema::hasColumn('desktop_settings', 'virtual_mode');
    }

    /** @return array{0:bool,1:?string} [available, reason] */
    private function availability(int $uid, string $role): array
    {
        if (!config('virtual_mode.enabled')) {
            return [false, 'disabled'];
        }
        if (!in_array($role, (array) config('virtual_mode.roles', []), true)) {
            return [false, 'role'];
        }
        if (!$this->hasColumn()) {
            return [false, 'unmigrated'];
        }
        if ($d = DriveAdminSettings::denial($uid, $role)) {
            return [false, (string) ($d[2] ?? 'drive')];
        }

        return [true, null];
    }

    private function payload(int $uid, string $role): array
    {
        [$available, $reason] = $this->availability($uid, $role);
        $enabled = $available && (bool) DB::table('desktop_settings')->where('user_id', $uid)->value('virtual_mode');

        return ['available' => $available, 'enabled' => $enabled, 'reason' => $reason];
    }

    public function show(Request $request): JsonResponse
    {
        return $this->apiSuccess($this->payload($this->uid($request), $this->role($request)));
    }

    public function update(Request $request): JsonResponse
    {
        $uid = $this->uid($request);
        $role = $this->role($request);

        $data = $request->validate(['enabled' => 'required|boolean']);

        [$available, $reason] = $this->availability($uid, $role);
        // الإيقاف مسموح دايمًا (عشان الطالب يقدر يخرج من الوضع حتى لو "جهازي" اتقفل)؛ التفعيل محتاج available.
        if ($data['enabled'] && !$available) {
            return $this->apiError('Virtual mode is not available for your account.', ['reason' => $reason], 403);
        }
        if (!$this->hasColumn()) {
            return $this->apiSuccess(['available' => false, 'enabled' => false, 'reason' => 'unmigrated']);
        }

        DB::table('desktop_settings')->insertOrIgnore(['user_id' => $uid, 'created_at' => now(), 'updated_at' => now()]);
        // updated_ms مقصود إنه مبيتغيرش: ده مش جزء من مزامنة إعدادات الشكل (الأحدث بيكسب) فميخلّيش جهاز تاني يعتبر إعداداته قديمة.
        DB::table('desktop_settings')->where('user_id', $uid)->update(['virtual_mode' => $data['enabled'] ? 1 : 0, 'updated_at' => now()]);

        return $this->apiSuccess($this->payload($uid, $role));
    }
}
