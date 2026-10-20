<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * الصلاحيات في صفحة الأدوار (/admin/roles) بقت بتتنفّذ فعليًا على السيرفر
 * (uip.can middleware). عشان أول deploy ميقفلش حاجة شغالة النهارده بالغلط:
 *
 *  1) بنضيف صلاحية "ai.assistant_use" (المساعد الذكي) — كانت مفيش صلاحية
 *     مستقلة ليه، وبتتمنح لكل الأدوار الحالية زي ما هو متاح لهم دلوقتي.
 *  2) بنمنح (INSERT IGNORE — مبنمسحش ولا بنعدّل أي حاجة موجودة) الصلاحيات
 *     اللي كل دور بيستخدمها فعلًا النهارده، لو كانت ناقصة. ده مهم خصوصًا
 *     لدور اللي عدّاده 0 (زي University) لأن من غير كده هيفقد الرسائل
 *     والتحليلات بمجرد ما الإنفورس يشتغل.
 *
 * بعد المايجريشن ده الأدمن يقدر يسحب أي صلاحية من الصفحة ويتنفّذ فورًا.
 * المايجريشن مبيسحبش أي صلاحية أبدًا (down() مش بتمسح منح، عمدًا).
 */
return new class extends Migration
{
    /** @var array<string, string[]> role slug => permission slugs اللي بيستخدمها فعلًا النهارده */
    private array $baseline = [
        'student' => ['project.create', 'project.edit_own', 'ai.request_analysis'],
        'university' => ['project.approve', 'project.view_all', 'analytics.view', 'reports.generate'],
        'faculty' => ['project.approve'],
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

    /** كل الأدوار بتستخدم الرسائل والإشعارات والمساعد الذكي النهارده. */
    private array $everyone = ['messaging.use', 'notifications.manage', 'ai.assistant_use'];

    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore([
            'slug'        => 'ai.assistant_use',
            'module'      => 'ai',
            'description' => 'Use the AI assistant (chat widget)',
        ]);

        $permIds = DB::table('permissions')->pluck('id', 'slug');
        $roleIds = DB::table('roles')->pluck('id', 'slug');

        $grant = function (int $roleId, string $permSlug) use ($permIds) {
            if (!isset($permIds[$permSlug])) {
                return; // الصلاحية مش موجودة في الكتالوج ده — اتخطّاها
            }
            DB::table('role_permission')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => (int) $permIds[$permSlug],
            ]);
        };

        // admin = كل الكتالوج (بما فيه الصلاحية الجديدة).
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
        // عمدًا فاضية: مش هنسحب صلاحيات الأدمن كان ممكن يكون ظبطها بنفسه بعد المايجريشن.
    }
};
