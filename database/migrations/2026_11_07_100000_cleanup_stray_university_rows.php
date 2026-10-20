<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تنضيف صفوف `universities` الشاردة اللي كانت لسه بتظهر في قوايم الجامعات
 * (التسجيل / فلتر المستخدمين) بعد حذفها:
 *  - صف مالكه حساب محذوف (deleted_at) => soft delete للصف.
 *  - صف مالكه حساب مش بدور "university" ومفيهوش أي طلاب/كليات => soft delete.
 * الصفوف بتتعمل soft delete (قابلة للاسترجاع) ومفاتيح الاسم بتتفرّغ.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('universities') || !Schema::hasColumn('universities', 'deleted_at')) {
            return;
        }

        $uniRoleOwners = DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('r.slug', 'university')->select('ur.user_id');

        $stray = DB::table('universities')->whereNull('deleted_at')->where(function ($w) use ($uniRoleOwners) {
            $w->whereIn('user_id', DB::table('users')->whereNotNull('deleted_at')->select('id'))
              ->orWhere(function ($x) use ($uniRoleOwners) {
                  $x->whereNotIn('user_id', $uniRoleOwners)
                    ->whereNotIn('id', DB::table('students')->whereNotNull('university_id')->select('university_id'))
                    ->whereNotIn('id', DB::table('faculties')->select('university_id'));
              });
        })->pluck('id')->all();

        if ($stray) {
            DB::table('universities')->whereIn('id', $stray)->update([
                'deleted_at' => now(), 'name_key_ar' => null, 'name_key_en' => null,
            ]);
        }
    }

    public function down(): void
    {
        // غير قابل للعكس عمدًا (الصفوف قابلة للاسترجاع).
    }
};
