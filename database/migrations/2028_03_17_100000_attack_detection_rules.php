<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Security Portal — بند 10 (Intrusion Defense) الجزء 1: كشف هجمات الـ API.
 *
 * مفيش جداول جديدة: الأحداث attack_probe / path_probe بتتخزّن في security_detection_events الموجود (event_type VARCHAR(32)).
 * الـ migration دي بس بتزرع قواعد افتراضية في security_detection_rules. اللي بيحظر (block_ip) متقفّل افتراضيًا
 * لحد ما الأدمن يراجع (NAT الجامعة). آمنة لإعادة التشغيل.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('security_detection_rules')) {
            return;
        }

        $rules = [
            [
                'rule_key' => 'injection_attempts', 'rule_type' => 'attack_probe_ip', 'enabled' => 1, 'severity' => 'high',
                'name_en' => 'Attack attempts from one IP (SQLi / XSS / traversal)', 'name_ar' => 'محاولات اختراق من IP واحد (SQLi / XSS / Traversal)',
                'description' => 'One IP sending requests that carry attack signatures: SQL injection, XSS, path traversal, command injection, SSRF, known scanner tools or sensitive-path probes (.env, wp-login…).',
                'threshold' => 3, 'window_minutes' => 10, 'cooldown_minutes' => 30, 'actions' => ['alert'], 'block_minutes' => 60, 'config' => null,
            ],
            [
                'rule_key' => 'injection_attempts_autoblock', 'rule_type' => 'attack_probe_ip', 'enabled' => 0, 'severity' => 'critical',
                'name_en' => 'Auto-block persistent attackers', 'name_ar' => 'حظر تلقائي للمهاجمين المستمرين',
                'description' => 'Much higher threshold than the alert-only rule; blocks the IP. Review shared/campus NAT addresses (exempt list) before enabling.',
                'threshold' => 10, 'window_minutes' => 10, 'cooldown_minutes' => 60, 'actions' => ['alert', 'block_ip'], 'block_minutes' => 120, 'config' => null,
            ],
            [
                'rule_key' => 'endpoint_scanning', 'rule_type' => 'path_scan', 'enabled' => 1, 'severity' => 'warning',
                'name_en' => 'Endpoint scanning (many non-existent paths)', 'name_ar' => 'فحص مسارات (طلبات كتير لمسارات مش موجودة)',
                'description' => 'One IP requesting many endpoints that do not exist (404/405) — directory brute-forcing or vulnerability scanning.',
                'threshold' => 20, 'window_minutes' => 5, 'cooldown_minutes' => 30, 'actions' => ['alert'], 'block_minutes' => 60, 'config' => null,
            ],
            [
                'rule_key' => 'endpoint_scanning_autoblock', 'rule_type' => 'path_scan', 'enabled' => 0, 'severity' => 'high',
                'name_en' => 'Auto-block aggressive scanners', 'name_ar' => 'حظر تلقائي للفاحصين العدوانيين',
                'description' => 'Blocks an IP that probes a very large number of non-existent endpoints. Off by default.',
                'threshold' => 80, 'window_minutes' => 5, 'cooldown_minutes' => 60, 'actions' => ['alert', 'block_ip'], 'block_minutes' => 120, 'config' => null,
            ],
            [
                'rule_key' => 'credential_stuffing', 'rule_type' => 'credential_stuffing', 'enabled' => 1, 'severity' => 'high',
                'name_en' => 'Credential stuffing (distributed failed sign-ins)', 'name_ar' => 'حشو بيانات الاعتماد (فشل دخول موزّع)',
                'description' => 'A surge of failed sign-ins from many different addresses against many different accounts, which a per-IP or per-account rule cannot see.',
                'threshold' => 30, 'window_minutes' => 10, 'cooldown_minutes' => 30, 'actions' => ['alert'], 'block_minutes' => 30,
                'config' => ['min_ips' => 8, 'min_accounts' => 8],
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
        if (!Schema::hasTable('security_detection_rules')) {
            return;
        }
        DB::table('security_detection_rules')->whereIn('rule_key', [
            'injection_attempts', 'injection_attempts_autoblock', 'endpoint_scanning', 'endpoint_scanning_autoblock', 'credential_stuffing',
        ])->delete();
        DB::table('security_detection_events')->whereIn('event_type', ['attack_probe', 'path_probe'])->delete();
    }
};
