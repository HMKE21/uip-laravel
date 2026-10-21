<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * صلاحية جديدة "ai.code_assist" (مساعد الكود داخل محرر MyPC). بتتمنح للطالب (المستخدم الأساسي
 * للمحرر) وللأدمن (كل الكتالوج). أي دور تاني الأدمن يديها له من صفحة /admin/roles.
 * INSERT IGNORE — مفيش حاجة موجودة بتتعدّل.
 */
return new class extends Migration
{
    private const SLUG = 'ai.code_assist';

    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore([
            'slug'        => self::SLUG,
            'module'      => 'ai',
            'description' => 'Use the AI code assistant inside the MyPC code editor (explain / fix errors)',
        ]);

        $permId = DB::table('permissions')->where('slug', self::SLUG)->value('id');
        if (!$permId) {
            return;
        }

        $roleIds = DB::table('roles')->whereIn('slug', ['student', 'admin'])->pluck('id');
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
