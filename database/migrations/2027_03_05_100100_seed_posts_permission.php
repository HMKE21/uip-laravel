<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * صلاحية posts.use (استخدام المنشورات) لكل الأدوار — نفس نمط 2026_10_23_120000_resync_role_permissions_baseline:
 * INSERT IGNORE فقط، مبيمسحش ولا بيعدّل أي صلاحية موجودة. الأدمن يقدر يسحبها من أي دور من /admin/roles.
 * posts.moderate للأدمن بس (مراجعة البلاغات والمنشورات المعلّقة).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore([
            ['slug' => 'posts.use', 'module' => 'posts', 'description' => 'Use social posts (read, publish, react, comment)'],
            ['slug' => 'posts.moderate', 'module' => 'posts', 'description' => 'Moderate social posts (reports, pending approval, settings)'],
        ]);

        $permIds = DB::table('permissions')->whereIn('slug', ['posts.use', 'posts.moderate'])->pluck('id', 'slug');
        $roles = DB::table('roles')->pluck('id', 'slug');

        foreach ($roles as $slug => $roleId) {
            DB::table('role_permission')->insertOrIgnore(['role_id' => (int) $roleId, 'permission_id' => (int) $permIds['posts.use']]);
            if ($slug === 'admin') {
                DB::table('role_permission')->insertOrIgnore(['role_id' => (int) $roleId, 'permission_id' => (int) $permIds['posts.moderate']]);
            }
        }
    }

    public function down(): void
    {
        // عمدًا فاضية (نفس سياسة migrations الصلاحيات التانية).
    }
};
