<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * بند 8 من نواقص بورتال الأمان — شاشة "أمان الامتحانات" (تجميع أحداث الأمان عبر كل الامتحانات):
 *  - صلاحية جديدة: security.exams.view (قراءة بس — الشاشة مفيهاش أي إجراء بيغيّر بيانات).
 *    security_admin + security_officer + admin.
 *  - index على exam_security_events(occurred_at) و (student_id, occurred_at): الاستعلامات الجديدة بتفلتر بالمدى الزمني
 *    عبر كل الامتحانات، والـ indexes الموجودة (attempt+time / exam+type) مبتخدمش الحالة دي.
 * مفيش جداول جديدة — الشاشة بتقرا exam_security_events / exam_attempts / exam_similarity_flags الموجودة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('exam_security_events')) {
            $this->addIndex('exam_security_events', ['occurred_at'], 'idx_exam_sec_events_time');
            $this->addIndex('exam_security_events', ['student_id', 'occurred_at'], 'idx_exam_sec_events_student_time');
        }

        if (Schema::hasTable('permissions') && Schema::hasTable('role_permission')) {
            DB::table('permissions')->insertOrIgnore([
                ['slug' => 'security.exams.view', 'module' => 'security', 'description' => 'View exam security monitoring: suspicious attempts, repeat offenders and shared networks across exams'],
            ]);
            $perm = DB::table('permissions')->pluck('id', 'slug');
            $role = DB::table('roles')->pluck('id', 'slug');
            foreach (['security_admin', 'security_officer', 'admin'] as $roleSlug) {
                if (isset($role[$roleSlug], $perm['security.exams.view'])) {
                    DB::table('role_permission')->insertOrIgnore(['role_id' => $role[$roleSlug], 'permission_id' => $perm['security.exams.view']]);
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('exam_security_events')) {
            foreach (['idx_exam_sec_events_time', 'idx_exam_sec_events_student_time'] as $name) {
                try {
                    Schema::table('exam_security_events', fn (Blueprint $t) => $t->dropIndex($name));
                } catch (\Throwable $e) {
                    // الـ index مش موجود — عادي.
                }
            }
        }
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->where('slug', 'security.exams.view')->delete();
        }
    }

    private function addIndex(string $table, array $columns, string $name): void
    {
        try {
            Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
        } catch (\Throwable $e) {
            // الـ index موجود قبل كده (إعادة تشغيل) — نتجاهل.
        }
    }
};
