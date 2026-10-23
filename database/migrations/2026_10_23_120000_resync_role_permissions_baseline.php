<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * إعادة منح الصلاحيات الأساسية لكل الأدوار (آمن لإعادة التشغيل).
 *
 * ليه: بعض الأدوار (زي University) ظهرت بعدد صلاحيات 0 أو ناقصة صلاحية واحدة (غالبًا ai.assistant_use)
 * في صفحة /admin/roles، فالرسائل والمساعد بيختفوا من السايدبار بمجرد ما الإنفورس (uip.can) يشتغل.
 *
 * بيعمل INSERT IGNORE فقط — مبيمسحش ولا بيعدّل أي صلاحية موجودة، ومبيلمسش user_permission_overrides.
 * نفس قائمة المايجريشن 2026_10_22_110000 + ai.code_assist للطالب + أساس supervisor و academic_staff.
 */
return new class extends Migration
{
    /** @var string[] كل الأدوار بتستخدمهم */
    private array $everyone = ['messaging.use', 'notifications.manage', 'ai.assistant_use'];

    /** @var array<string, string[]> role slug => permission slugs */
    private array $baseline = [
        'student' => ['project.create', 'project.edit_own', 'ai.request_analysis', 'ai.code_assist'],
        'university' => ['project.approve', 'project.view_all', 'analytics.view', 'reports.generate'],
        'faculty' => ['project.approve'],
        'supervisor' => ['project.approve', 'project.view_all'],
        'academic_staff' => ['project.view_all'],
        'data_analyst' => [
            'data_analysis.dashboard.view', 'data_analysis.export', 'data_analysis.reports.manage',
            'data_analysis.segments.manage', 'data_analysis.dashboards.customize',
            'data_analysis.trends.view', 'analytics.view', 'reports.generate',
        ],
        'security_admin' => [
            'security.dashboard.view', 'security.incidents.manage', 'security.sessions.manage',
            'security.vulnerabilities.manage', 'security.policies.manage', 'security.risk.view',
            'security.ip.manage', 'security.notifications.manage', 'security.reports.generate',
            'security.users.manage', 'logs.view_security', 'logs.view_audit',
        ],
        'security_officer' => [
            'security.dashboard.view', 'security.incidents.manage', 'security.sessions.manage',
            'security.vulnerabilities.manage', 'security.risk.view', 'security.notifications.manage',
            'security.reports.generate', 'logs.view_security', 'logs.view_audit',
        ],
    ];

    public function up(): void
    {
        // الصلاحيات الأحدث لازم تكون في الكتالوج حتى لو مايجريشناتها ماشتغلتش.
        DB::table('permissions')->insertOrIgnore([
            ['slug' => 'ai.assistant_use', 'module' => 'ai', 'description' => 'Use the AI assistant (chat widget)'],
            ['slug' => 'ai.code_assist', 'module' => 'ai', 'description' => 'Use the AI code assistant inside the MyPC code editor (explain / fix errors)'],
        ]);

        $permIds = DB::table('permissions')->pluck('id', 'slug');
        $roleIds = DB::table('roles')->pluck('id', 'slug');

        $grant = function (int $roleId, string $permSlug) use ($permIds): void {
            if (!isset($permIds[$permSlug])) {
                return; // الصلاحية مش موجودة في الكتالوج ده — اتخطّاها
            }
            DB::table('role_permission')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => (int) $permIds[$permSlug],
            ]);
        };

        // admin = الكتالوج كله
        if (isset($roleIds['admin'])) {
            foreach ($permIds as $slug => $_) {
                $grant((int) $roleIds['admin'], $slug);
            }
        }

        foreach ($roleIds as $slug => $roleId) {
            foreach ($this->everyone as $p) {
                $grant((int) $roleId, $p);
            }
            foreach ($this->baseline[$slug] ?? [] as $p) {
                $grant((int) $roleId, $p);
            }
        }
    }

    public function down(): void
    {
        // عمدًا فاضية: مش هنسحب صلاحيات الأدمن كان ممكن يكون ظبطها بنفسه.
    }
};
