<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Security Portal — Threat Detection Engine (بند 1).
 *
 *  - security_detection_rules   : القواعد (threshold + window + actions) اللي الأدمن بيظبطها.
 *  - security_detection_events  : تدفّق أحداث خفيف (failed_login / file_download / login_success / role_granted)
 *                                 بنعدّ منه جوّه الـ window. بيتنضّف دوريًا (security:detection-prune).
 *  - security_detection_hits    : كل مرة قاعدة اتفعّلت (سجل + cooldown + إحصائيات).
 *  - security_user_baselines    : "المعروف" لكل مستخدم (دول/أجهزة) لقواعد "دولة/جهاز جديد".
 *  - blocked_ips                : + expires_at / source / rule_id => حظر تلقائي بانتهاء.
 *  - security_alerts.type       : من ENUM لـ VARCHAR عشان أنواع التنبيهات الجديدة.
 *
 * آمنة لإعادة التشغيل (كل خطوة بتتأكد الأول).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('security_detection_rules')) {
            Schema::create('security_detection_rules', function (Blueprint $t) {
                $t->id();
                $t->string('rule_key', 80)->unique();
                $t->string('rule_type', 40);
                $t->string('name_en', 160);
                $t->string('name_ar', 160)->nullable();
                $t->text('description')->nullable();
                $t->boolean('enabled')->default(true);
                $t->string('severity', 12)->default('warning');
                $t->unsignedInteger('threshold')->default(10);
                $t->unsignedInteger('window_minutes')->default(10);
                $t->unsignedInteger('cooldown_minutes')->default(30);
                $t->json('actions')->nullable();
                $t->unsignedInteger('block_minutes')->default(30); // 0 = دائم
                $t->json('config')->nullable();
                $t->boolean('is_system')->default(false);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->unsignedInteger('trigger_count')->default(0);
                $t->dateTime('last_triggered_at')->nullable();
                $t->dateTime('created_at')->useCurrent();
                $t->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
                $t->index(['enabled', 'rule_type'], 'idx_det_rules_enabled');
            });
        }

        if (!Schema::hasTable('security_detection_events')) {
            Schema::create('security_detection_events', function (Blueprint $t) {
                $t->id();
                $t->string('event_type', 32);
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('ip_address', 45)->nullable();
                $t->string('identifier', 190)->nullable();
                $t->string('country', 120)->nullable();
                $t->unsignedBigInteger('bytes')->nullable();
                $t->json('meta')->nullable();
                $t->dateTime('created_at')->useCurrent();
                $t->index(['event_type', 'ip_address', 'created_at'], 'idx_det_ev_ip');
                $t->index(['event_type', 'user_id', 'created_at'], 'idx_det_ev_user');
                $t->index(['event_type', 'identifier', 'created_at'], 'idx_det_ev_ident');
                $t->index('created_at', 'idx_det_ev_created');
            });
        }

        if (!Schema::hasTable('security_detection_hits')) {
            Schema::create('security_detection_hits', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('rule_id')->nullable();
                $t->string('rule_key', 80);
                $t->string('subject_type', 12);
                $t->string('subject_value', 190);
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('ip_address', 45)->nullable();
                $t->unsignedInteger('observed')->default(0);
                $t->unsignedInteger('threshold')->default(0);
                $t->string('severity', 12)->default('warning');
                $t->json('actions_taken')->nullable();
                $t->unsignedBigInteger('alert_id')->nullable();
                $t->dateTime('created_at')->useCurrent();
                $t->index(['rule_id', 'subject_value', 'created_at'], 'idx_det_hit_subject');
                $t->index('created_at', 'idx_det_hit_created');
            });
        }

        if (!Schema::hasTable('security_user_baselines')) {
            Schema::create('security_user_baselines', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id');
                $t->string('kind', 12); // country | device
                $t->string('value', 190);
                $t->unsignedInteger('seen_count')->default(1);
                $t->dateTime('first_seen_at')->useCurrent();
                $t->dateTime('last_seen_at')->useCurrent();
                $t->unique(['user_id', 'kind', 'value'], 'uq_user_baseline');
                $t->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

        // الحظر التلقائي بانتهاء.
        if (Schema::hasTable('blocked_ips')) {
            Schema::table('blocked_ips', function (Blueprint $t) {
                if (!Schema::hasColumn('blocked_ips', 'expires_at')) {
                    $t->dateTime('expires_at')->nullable();
                    $t->index('expires_at', 'idx_blocked_ips_expires');
                }
                if (!Schema::hasColumn('blocked_ips', 'source')) {
                    $t->string('source', 12)->default('manual');
                }
                if (!Schema::hasColumn('blocked_ips', 'rule_id')) {
                    $t->unsignedBigInteger('rule_id')->nullable();
                }
            });
        }

        // أنواع التنبيهات الجديدة (password_spray, mass_download, ...) — العمود كان ENUM بـ 8 قيم ثابتة.
        if (Schema::hasTable('security_alerts')) {
            try {
                Schema::table('security_alerts', function (Blueprint $t) {
                    $t->string('type', 50)->nullable(false)->change();
                });
            } catch (\Throwable $e) {
                $driver = DB::connection()->getDriverName();
                if (in_array($driver, ['mysql', 'mariadb'], true)) {
                    DB::statement('ALTER TABLE security_alerts MODIFY `type` VARCHAR(50) NOT NULL');
                } elseif ($driver === 'pgsql') {
                    DB::statement('ALTER TABLE security_alerts DROP CONSTRAINT IF EXISTS security_alerts_type_check');
                    DB::statement('ALTER TABLE security_alerts ALTER COLUMN "type" TYPE VARCHAR(50)');
                }
            }
        }

        $this->seedRules();
        $this->backfillBaselines();
    }

    public function down(): void
    {
        Schema::dropIfExists('security_user_baselines');
        Schema::dropIfExists('security_detection_hits');
        Schema::dropIfExists('security_detection_events');
        Schema::dropIfExists('security_detection_rules');

        if (Schema::hasTable('blocked_ips')) {
            DB::table('blocked_ips')->where('source', 'auto')->delete();
            Schema::table('blocked_ips', function (Blueprint $t) {
                if (Schema::hasColumn('blocked_ips', 'expires_at')) {
                    $t->dropIndex('idx_blocked_ips_expires');
                    $t->dropColumn('expires_at');
                }
                if (Schema::hasColumn('blocked_ips', 'source')) {
                    $t->dropColumn('source');
                }
                if (Schema::hasColumn('blocked_ips', 'rule_id')) {
                    $t->dropColumn('rule_id');
                }
            });
        }
        // security_alerts.type بيفضل VARCHAR (الرجوع لـ ENUM هيكسر أي صفوف بأنواع جديدة).
    }

    /** القواعد الافتراضية. اللي بيحجب (block) متقفّل افتراضيًا لحد ما الأدمن يراجع (NAT الجامعة). */
    private function seedRules(): void
    {
        $rules = [
            [
                'rule_key' => 'brute_force_ip', 'rule_type' => 'failed_login_ip', 'enabled' => 1, 'severity' => 'high',
                'name_en' => 'Repeated failed logins from one IP', 'name_ar' => 'محاولات دخول فاشلة متكررة من عنوان IP واحد',
                'description' => 'Fires when one IP address fails to sign in too many times inside the window (brute force).',
                'threshold' => 10, 'window_minutes' => 10, 'cooldown_minutes' => 10, 'actions' => ['alert'], 'block_minutes' => 30, 'config' => null,
            ],
            [
                'rule_key' => 'brute_force_ip_autoblock', 'rule_type' => 'failed_login_ip', 'enabled' => 0, 'severity' => 'critical',
                'name_en' => 'Auto-block aggressive brute force', 'name_ar' => 'حظر تلقائي لهجمات التخمين العنيفة',
                'description' => 'Much higher threshold than the alert-only rule. Blocks the IP for a while. Review shared/campus NAT addresses (exempt list) before enabling.',
                'threshold' => 40, 'window_minutes' => 10, 'cooldown_minutes' => 30, 'actions' => ['alert', 'block_ip'], 'block_minutes' => 30, 'config' => null,
            ],
            [
                'rule_key' => 'password_spray', 'rule_type' => 'password_spray', 'enabled' => 1, 'severity' => 'high',
                'name_en' => 'Password spraying (many accounts, one IP)', 'name_ar' => 'رش كلمات المرور (حسابات كتير من IP واحد)',
                'description' => 'One IP failing to sign in to many DIFFERENT accounts — typical of credential stuffing / password spraying.',
                'threshold' => 8, 'window_minutes' => 15, 'cooldown_minutes' => 30, 'actions' => ['alert'], 'block_minutes' => 60, 'config' => null,
            ],
            [
                'rule_key' => 'account_under_attack', 'rule_type' => 'failed_login_user', 'enabled' => 1, 'severity' => 'warning',
                'name_en' => 'Account under attack (distributed failures)', 'name_ar' => 'حساب مستهدف (فشل دخول موزّع)',
                'description' => 'Many failed sign-ins against the same account, regardless of source IP.',
                'threshold' => 8, 'window_minutes' => 15, 'cooldown_minutes' => 30, 'actions' => ['alert'], 'block_minutes' => 30, 'config' => null,
            ],
            [
                'rule_key' => 'mass_download', 'rule_type' => 'mass_download', 'enabled' => 1, 'severity' => 'high',
                'name_en' => 'Mass file download', 'name_ar' => 'تحميل جماعي للملفات',
                'description' => 'One signed-in user downloading an unusual number of files in a short time (possible data exfiltration).',
                'threshold' => 40, 'window_minutes' => 10, 'cooldown_minutes' => 30, 'actions' => ['alert'], 'block_minutes' => 30, 'config' => ['max_mb' => 0],
            ],
            [
                'rule_key' => 'new_country_login', 'rule_type' => 'new_country', 'enabled' => 1, 'severity' => 'warning',
                'name_en' => 'Sign-in from a new country/location', 'name_ar' => 'دخول من دولة/مكان جديد',
                'description' => 'A successful sign-in from a location this user has never signed in from before.',
                'threshold' => 1, 'window_minutes' => 0, 'cooldown_minutes' => 240, 'actions' => ['alert'], 'block_minutes' => 30, 'config' => ['min_known' => 1],
            ],
            [
                'rule_key' => 'new_device_login', 'rule_type' => 'new_device', 'enabled' => 0, 'severity' => 'info',
                'name_en' => 'Sign-in from a new device', 'name_ar' => 'دخول من جهاز جديد',
                'description' => 'A successful sign-in from a browser/device this user has not used before. Noisy for students — off by default.',
                'threshold' => 1, 'window_minutes' => 0, 'cooldown_minutes' => 240, 'actions' => ['alert'], 'block_minutes' => 30, 'config' => ['min_known' => 1],
            ],
            [
                'rule_key' => 'privilege_escalation', 'rule_type' => 'privilege_escalation', 'enabled' => 1, 'severity' => 'critical',
                'name_en' => 'Elevated role granted', 'name_ar' => 'منح صلاحيات عالية',
                'description' => 'An administrative / security role was granted to an account.',
                'threshold' => 1, 'window_minutes' => 0, 'cooldown_minutes' => 5, 'actions' => ['alert'], 'block_minutes' => 30,
                'config' => ['roles' => ['admin', 'security_admin', 'security_officer'], 'severity_overrides' => ['security_officer' => 'high']],
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

    /**
     * عشان "دولة/جهاز جديد" ما يبقاش أعمى لحد أول دخول بعد التحديث: نملّي الـ baseline من الجلسات القديمة.
     * (القيمة بتطابق location_label بالظبط زي ما الـ engine بيحسبها.)
     */
    private function backfillBaselines(): void
    {
        try {
            if (!Schema::hasTable('user_sessions') || !Schema::hasTable('security_user_baselines')) {
                return;
            }

            $this->backfillKind('country', 'location_label', "location_label <> 'Local network'");
            if (Schema::hasColumn('user_sessions', 'device_hash')) {
                $this->backfillKind('device', 'device_hash', null);
            }
        } catch (\Throwable $e) {
            // اختياري — فشله مبيمنعش الـ migration.
        }
    }

    private function backfillKind(string $kind, string $column, ?string $extraWhere): void
    {
        $q = DB::table('user_sessions')
            ->whereNotNull($column)->where($column, '<>', '')
            ->whereIn('user_id', DB::table('users')->select('id'))
            ->selectRaw("user_id, {$column} as v, COUNT(*) as c, MIN(created_at) as f, MAX(created_at) as l")
            ->groupBy('user_id', $column);
        if ($extraWhere) {
            $q->whereRaw($extraWhere);
        }

        $batch = [];
        foreach ($q->cursor() as $row) {
            $batch[] = [
                'user_id' => (int) $row->user_id, 'kind' => $kind, 'value' => mb_substr((string) $row->v, 0, 190),
                'seen_count' => (int) $row->c, 'first_seen_at' => $row->f ?: now(), 'last_seen_at' => $row->l ?: now(),
            ];
            if (count($batch) >= 500) {
                DB::table('security_user_baselines')->insertOrIgnore($batch);
                $batch = [];
            }
        }
        if ($batch) {
            DB::table('security_user_baselines')->insertOrIgnore($batch);
        }
    }
};
