<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * صلاحية جديدة "guides.manage" — تعديل صفحات الدليل بتاعة كل البوابات. بتتمنح للأدمن (كل الكتالوج).
 * أي دور تاني الأدمن يديها له من صفحة /admin/roles. INSERT IGNORE — مفيش حاجة موجودة بتتعدّل.
 */
return new class extends Migration
{
    private const SLUG = 'guides.manage';

    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore([
            'slug'        => self::SLUG,
            'module'      => 'guides',
            'description' => 'Edit the portal guide pages shown to every user (sections, pages, steps, FAQ)',
        ]);

        $permId = DB::table('permissions')->where('slug', self::SLUG)->value('id');
        if (!$permId) {
            return;
        }

        $roleIds = DB::table('roles')->where('slug', 'admin')->pluck('id');
        foreach ($roleIds as $roleId) {
            DB::table('role_permission')->insertOrIgnore([
                'role_id'       => (int) $roleId,
                'permission_id' => (int) $permId,
            ]);
        }
    }

    public function down(): void
    {
        $permId = DB::table('permissions')->where('slug', self::SLUG)->value('id');
        if ($permId) {
            DB::table('role_permission')->where('permission_id', $permId)->delete();
            DB::table('permissions')->where('id', $permId)->delete();
        }
    }
};
