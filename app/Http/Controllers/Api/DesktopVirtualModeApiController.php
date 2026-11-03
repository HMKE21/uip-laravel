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
 * الوضع مُثبَّت (pinned): UIPE OS هو المنصة لكل دور مسموح، ومفيش اختيار ولا رجوع للمنصة القديمة.
 *  GET  → { available, enabled, reason }
 *         available: الميزة شغّالة + الدور مسموح + "جهازي" مفتوح للحساب ده (مش مقفول من الأدمن ولا الحساب محظور منه).
 *         enabled  : = available دايمًا (لو "جهازي" اتقفل بنرجع المنصة العادية عشان محدش يتحبس في سطح مكتب مقفول).
 *  PUT  → بيرجع نفس رد GET وبيتجاهل اللي اتبعت (متساب عشان النسخ القديمة من الفرونت متكسرش).
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
        if ($d = DriveAdminSettings::denial($uid, $role)) {
            return [false, (string) ($d[2] ?? 'drive')];
        }

        return [true, null];
    }

    private function payload(int $uid, string $role): array
    {
        [$available, $reason] = $this->availability($uid, $role);
        return ['available' => $available, 'enabled' => $available, 'reason' => $reason, 'pinned' => true];
    }

    public function show(Request $request): JsonResponse
    {
        return $this->apiSuccess($this->payload($this->uid($request), $this->role($request)));
    }

    /** الوضع مثبّت: مفيش حاجة تتحفظ. بيرجع الحالة الحالية بس. */
    public function update(Request $request): JsonResponse
    {
        return $this->apiSuccess($this->payload($this->uid($request), $this->role($request)));
    }
}
