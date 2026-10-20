<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Roles used by the code (uip_role === 'faculty' / 'academic_staff' / 'supervisor')
 * but never seeded. Without them, FacultyAccountService silently creates
 * faculty users with NO role. Safe to run more than once.
 */
class AcademicRolesSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'faculty' => [
                'name_ar' => 'كلية',
                'name_en' => 'Faculty',
                'description' => 'Faculty account managing departments, academic staff and student approvals',
                'permissions' => ['project.approve', 'project.view_all', 'analytics.view', 'reports.generate', 'messaging.use', 'notifications.manage'],
            ],
            'academic_staff' => [
                'name_ar' => 'عضو هيئة تدريس',
                'name_en' => 'Academic Staff',
                'description' => 'Doctor / teaching staff: exams, question banks, grading and student follow-up',
                'permissions' => ['project.view_all', 'messaging.use', 'notifications.manage'],
            ],
            'supervisor' => [
                'name_ar' => 'مشرف',
                'name_en' => 'Supervisor',
                'description' => 'Project supervisor: reviews, approves and grades assigned student projects',
                'permissions' => ['project.approve', 'project.view_all', 'messaging.use', 'notifications.manage'],
            ],
        ];

        foreach ($roles as $slug => $r) {
            DB::table('roles')->updateOrInsert(
                ['slug' => $slug],
                ['name_ar' => $r['name_ar'], 'name_en' => $r['name_en'], 'description' => $r['description']]
            );

            $roleId = DB::table('roles')->where('slug', $slug)->value('id');
            $permIds = DB::table('permissions')->whereIn('slug', $r['permissions'])->pluck('id');

            foreach ($permIds as $permId) {
                DB::table('role_permission')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permId]);
            }
        }
    }
}
