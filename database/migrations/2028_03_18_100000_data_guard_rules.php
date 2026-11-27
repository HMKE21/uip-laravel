<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Security Portal — بند 1: مراقبة الداتا.
 *
 *  - security_detection_events.amount : عدد العناصر في الحدث (الحذف/التعديل الجماعي = عدد الـ ids) — الباقي بيعدّ 1.
 *  - قواعد افتراضية: تصدير/حذف/تعديل جماعي، تغيير صلاحيات/سياسات، ودخول من جهاز/بلد جديد للأدمن.
 *    قواعد "الاستجابة" (إنهاء جلسات/قفل حساب) متقفّلة افتراضيًا لحد ما الأدمن يراجع العتبات.
 * آمنة لإعادة التشغيل.
 */
return new class extends Migration
{
    private const KEYS = [
        'mass_export', 'mass_export_response', 'mass_delete', 'mass_delete_response', 'mass_modify',
        'admin_permission_change', 'sensitive_change_burst', 'admin_new_device', 'admin_new_country',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('security_detection_rules') || !Schema::hasTable('security_detection_events')) {
            return;
        }

        if (!Schema::hasColumn('security_detection_events', 'amount')) {
            Schema::table('security_detection_events', function (Blueprint $t) {
                $t->unsignedInteger('amount')->nullable();
            });
        }

        $admins = ['admin', 'security_admin', 'security_officer'];
        $rules = [
            [
                'rule_key' => 'mass_export', 'rule_type' => 'mass_export', 'enabled' => 1, 'severity' => 'high',
                'name_en' => 'Mass data export', 'name_ar' => 'تصدير بيانات بكميات كبيرة',
                'description' => 'One signed-in user running many data exports in a short time (possible data exfiltration).',
                'threshold' => 10, 'window_minutes' => 10, 'cooldown_minutes' => 30, 'actions' => ['alert'], 'block_minutes' => 30, 'config' => null,
            ],
            [
                'rule_key' => 'mass_export_response', 'rule_type' => 'mass_export', 'enabled' => 0, 'severity' => 'critical',
                'name_en' => 'Mass export — end sessions', 'name_ar' => 'تصدير ضخم — إنهاء الجلسات',
                'description' => 'Much higher threshold; also ends all of the user\'s sessions. Off by default.',
                'threshold' => 30, 'window_minutes' => 10, 'cooldown_minutes' => 60, 'actions' => ['alert', 'revoke_sessions'], 'block_minutes' => 30, 'config' => null,
            ],
            [
                'rule_key' => 'mass_delete', 'rule_type' => 'mass_delete', 'enabled' => 1, 'severity' => 'high',
                'name_en' => 'Mass deletion', 'name_ar' => 'حذف جماعي',
                'description' => 'One signed-in user deleting a large number of items in a short time (bulk deletes count by item; purge/wipe count as 25).',
                'threshold' => 50, 'window_minutes' => 10, 'cooldown_minutes' => 30, 'actions' => ['alert'], 'block_minutes' => 30, 'config' => null,
            ],
            [
                'rule_key' => 'mass_delete_response', 'rule_type' => 'mass_delete', 'enabled' => 0, 'severity' => 'critical',
                'name_en' => 'Mass deletion — end sessions and lock account', 'name_ar' => 'حذف جماعي ضخم — إنهاء الجلسات وقفل الحساب',
                'description' => 'Very high threshold; ends sessions and locks the account (never locks admin / security_admin). Off by default.',
                'threshold' => 200, 'window_minutes' => 10, 'cooldown_minutes' => 60, 'actions' => ['alert', 'revoke_sessions', 'lock_account'], 'block_minutes' => 60, 'config' => null,
            ],
            [
                'rule_key' => 'mass_modify', 'rule_type' => 'mass_modify', 'enabled' => 1, 'severity' => 'warning',
                'name_en' => 'Mass modification / import', 'name_ar' => 'تعديل جماعي / استيراد',
                'description' => 'One signed-in user bulk-updating or importing a large number of records in a short time.',
                'threshold' => 100, 'window_minutes' => 10, 'cooldown_minutes' => 30, 'actions' => ['alert'], 'block_minutes' => 30, 'config' => null,
            ],
            [
                'rule_key' => 'admin_permission_change', 'rule_type' => 'sensitive_change', 'enabled' => 1, 'severity' => 'high',
                'name_en' => 'Role / permission / security policy changed', 'name_ar' => 'تغيير أدوار / صلاحيات / سياسات أمنية',
                'description' => 'Any change to roles, role permissions, security policies, detection rules or network rules. Alerts on every change.',
                'threshold' => 1, 'window_minutes' => 60, 'cooldown_minutes' => 5, 'actions' => ['alert'], 'block_minutes' => 30, 'config' => null,
            ],
            [
                'rule_key' => 'sensitive_change_burst', 'rule_type' => 'sensitive_change', 'enabled' => 0, 'severity' => 'critical',
                'name_en' => 'Burst of sensitive changes — end sessions', 'name_ar' => 'تغييرات حساسة متتالية — إنهاء الجلسات',
                'description' => 'Many permission / policy changes in a short time (a compromised admin account rewriting access). Ends sessions. Off by default.',
                'threshold' => 8, 'window_minutes' => 10, 'cooldown_minutes' => 60, 'actions' => ['alert', 'revoke_sessions'], 'block_minutes' => 30, 'config' => null,
            ],
            [
                'rule_key' => 'admin_new_device', 'rule_type' => 'new_device', 'enabled' => 1, 'severity' => 'high',
                'name_en' => 'Admin sign-in from a new device', 'name_ar' => 'دخول أدمن من جهاز جديد',
                'description' => 'A privileged account (admin / security roles) signing in from a device it has not used before. Students are not covered.',
                'threshold' => 1, 'window_minutes' => 0, 'cooldown_minutes' => 240, 'actions' => ['alert'], 'block_minutes' => 30,
                'config' => ['min_known' => 1, 'roles' => $admins],
            ],
            [
                'rule_key' => 'admin_new_country', 'rule_type' => 'new_country', 'enabled' => 1, 'severity' => 'high',
                'name_en' => 'Admin sign-in from a new country/location', 'name_ar' => 'دخول أدمن من بلد/مكان جديد',
                'description' => 'A privileged account (admin / security roles) signing in from a location it has never signed in from.',
                'threshold' => 1, 'window_minutes' => 0, 'cooldown_minutes' => 240, 'actions' => ['alert'], 'block_minutes' => 30,
                'config' => ['min_known' => 1, 'roles' => $admins],
            ],
        ];

        foreach ($rules as $r) {
            if (DB::table('security_detection_rules')->where('rule_key', $r['rule_key'])->exists()) {
                continue;
            }
            DB::table('security_detection_rules')->insert([
                'rule_key'         => $r['rule_key'],
                'rule_type'        => $r['rule_type'],
                'name_en'          => $r['name_en'],
                'name_ar'          => $r['name_ar'],
                'description'      => $r['description'],
                'enabled'          => $r['enabled'],
                'severity'         => $r['severity'],
                'threshold'        => $r['threshold'],
                'window_minutes'   => $r['window_minutes'],
                'cooldown_minutes' => $r['cooldown_minutes'],
                'actions'          => json_encode($r['actions']),
                'block_minutes'    => $r['block_minutes'],
                'config'           => $r['config'] === null ? null : json_encode($r['config']),
                'is_system'        => 1,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('security_detection_rules')) {
            DB::table('security_detection_rules')->whereIn('rule_key', self::KEYS)->delete();
        }
        if (Schema::hasTable('security_detection_events')) {
            DB::table('security_detection_events')->whereIn('event_type', ['data_export', 'data_delete', 'data_bulk_update', 'config_change'])->delete();
            if (Schema::hasColumn('security_detection_events', 'amount')) {
                Schema::table('security_detection_events', function (Blueprint $t) {
                    $t->dropColumn('amount');
                });
            }
        }
    }
};
