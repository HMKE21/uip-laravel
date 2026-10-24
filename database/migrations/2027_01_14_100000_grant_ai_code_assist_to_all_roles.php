<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "جهازي" (My PC) بقى متاح لكل الأدوار، فمساعد الكود (ai.code_assist) بيتمنح لكل الأدوار كأساس.
 * الأدمن يقدر يسحبه من أي دور من /admin/roles. INSERT IGNORE — مفيش حاجة موجودة بتتعدّل.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permId = DB::table('permissions')->where('slug', 'ai.code_assist')->value('id');
        if (!$permId) {
            return;
        }
        foreach (DB::table('roles')->pluck('id') as $roleId) {
            DB::table('role_permission')->insertOrIgnore(['role_id' => (int) $roleId, 'permission_id' => (int) $permId]);
        }
    }

    public function down(): void
    {
        // مقصود: مفيش رجوع — مش هنسحب صلاحية ممكن الأدمن يكون ضبطها بنفسه.
    }
};
