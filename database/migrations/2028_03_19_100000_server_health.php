<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Security Portal — بند 2: صحة السيرفر.
 *
 *  - security_health_samples : قياس كل دقيقة (CPU / RAM / ديسك / حجم DB / طابور الـ jobs). بيتنضّف بعد 14 يوم.
 *  - security_health_errors  : عدّاد أخطاء 5xx لكل (دقيقة، route، status).
 *  - قواعد افتراضية (تنبيه بس) في security_detection_rules: ارتفاع CPU/RAM/ديسك، أخطاء 5xx، تراكم/تأخّر/فشل الـ queue،
 *    نمو قاعدة البيانات، وقفزات مفاجئة عن المعدل الطبيعي. حد حجم DB المطلق متقفّل لحد ما الأدمن يحدّد الرقم المناسب.
 * آمنة لإعادة التشغيل.
 */
return new class extends Migration
{
    private const KEYS = [
        'cpu_high', 'ram_high', 'disk_high', 'disk_critical', 'db_size_limit', 'db_growth', 'queue_backlog', 'queue_stalled',
        'queue_failures', 'http_5xx', 'cpu_spike', 'ram_spike', 'http_5xx_spike', 'queue_spike',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('security_health_samples')) {
            Schema::create('security_health_samples', function (Blueprint $t) {
                $t->id();
                $t->decimal('cpu_percent', 5, 1)->nullable();
                $t->decimal('load1', 7, 2)->nullable();
                $t->decimal('load5', 7, 2)->nullable();
                $t->decimal('cores', 6, 2)->nullable();
                $t->decimal('mem_percent', 5, 1)->nullable();
                $t->unsignedInteger('mem_used_mb')->nullable();
                $t->unsignedInteger('mem_total_mb')->nullable();
                $t->decimal('disk_percent', 5, 1)->nullable();
                $t->decimal('disk_free_gb', 10, 2)->nullable();
                $t->decimal('disk_total_gb', 10, 2)->nullable();
                $t->decimal('db_size_mb', 12, 1)->nullable();
                $t->unsignedInteger('queue_pending')->nullable();
                $t->unsignedInteger('queue_failed')->nullable();
                $t->unsignedInteger('queue_oldest_age_s')->nullable();
                $t->dateTime('created_at')->useCurrent();
                $t->index('created_at', 'idx_health_samples_created');
            });
        }

        if (!Schema::hasTable('security_health_errors')) {
            Schema::create('security_health_errors', function (Blueprint $t) {
                $t->id();
                $t->dateTime('minute_at');
                $t->string('route', 120);
                $t->unsignedSmallInteger('status');
                $t->unsignedInteger('hits')->default(0);
                $t->unique(['minute_at', 'route', 'status'], 'uq_health_errors');
                $t->index('minute_at', 'idx_health_errors_minute');
            });
        }

        if (!Schema::hasTable('security_detection_rules')) {
            return;
        }

        $spike = fn (int $min) => ['min_value' => $min, 'baseline_minutes' => 60];
        $rules = [
            ['cpu_high', 'server_cpu', 1, 'warning', 'High CPU usage', 'ارتفاع استهلاك CPU',
                'Average CPU usage stayed above the level for the whole window.', 85, 5, 30, null],
            ['ram_high', 'server_ram', 1, 'warning', 'High memory usage', 'ارتفاع استهلاك RAM',
                'Average memory usage stayed above the level for the whole window.', 90, 5, 30, null],
            ['disk_high', 'server_disk', 1, 'warning', 'Disk filling up', 'الديسك بيمتلي',
                'Disk usage passed the warning level.', 85, 5, 360, null],
            ['disk_critical', 'server_disk', 1, 'critical', 'Disk almost full', 'الديسك على وشك الامتلاء',
                'Disk usage passed the critical level — uploads and logs may start failing.', 95, 5, 60, null],
            ['db_size_limit', 'db_size', 0, 'warning', 'Database size limit', 'حد حجم قاعدة البيانات',
                'Alerts when the database passes the size you set (MB). Off by default — set the limit that fits your storage plan, then enable.', 5120, 5, 720, null],
            ['db_growth', 'db_growth', 1, 'warning', 'Database growing fast', 'قاعدة البيانات بتكبر بسرعة',
                'The database grew by more than this many MB within the window (runaway logs, imports, or a loop writing rows).', 500, 60, 360, null],
            ['queue_backlog', 'queue_backlog', 1, 'warning', 'Job queue backlog', 'تراكم في طابور الـ jobs',
                'Too many jobs are waiting in the queue (the worker is slow or stopped).', 500, 5, 30, null],
            ['queue_stalled', 'queue_stalled', 1, 'high', 'Queue jobs waiting too long', 'jobs مستنية في الطابور وقت طويل',
                'The oldest waiting job has been there for this many minutes — the queue worker is probably not running.', 10, 5, 30, null],
            ['queue_failures', 'queue_failures', 1, 'warning', 'Queue jobs failing', 'jobs بتفشل في الطابور',
                'Several jobs failed within the window (see the failed_jobs table).', 10, 15, 30, null],
            ['http_5xx', 'http_5xx', 1, 'high', 'Server errors (5xx)', 'أخطاء السيرفر (5xx)',
                'Many requests ended with a 5xx server error within the window.', 20, 5, 30, null],
            ['cpu_spike', 'cpu_spike', 1, 'warning', 'Sudden CPU spike', 'قفزة مفاجئة في CPU',
                'CPU jumped well above its normal level of the previous hour (even if it is below the fixed alert level).', 100, 5, 60, $spike(50)],
            ['ram_spike', 'ram_spike', 1, 'warning', 'Sudden memory spike', 'قفزة مفاجئة في RAM',
                'Memory usage jumped well above its normal level of the previous hour.', 50, 5, 60, $spike(60)],
            ['http_5xx_spike', 'http_5xx_spike', 1, 'high', 'Sudden surge of 5xx errors', 'قفزة مفاجئة في أخطاء 5xx',
                'The 5xx error rate jumped well above the previous hour (minimum 10 errors in the window).', 300, 5, 60, $spike(10)],
            ['queue_spike', 'queue_spike', 1, 'warning', 'Sudden queue growth', 'قفزة مفاجئة في طابور الـ jobs',
                'The queue grew far beyond its normal size of the previous hour (minimum 100 waiting jobs).', 200, 5, 60, $spike(100)],
        ];

        foreach ($rules as [$key, $type, $enabled, $severity, $nameEn, $nameAr, $desc, $threshold, $window, $cooldown, $config]) {
            if (DB::table('security_detection_rules')->where('rule_key', $key)->exists()) {
                continue;
            }
            DB::table('security_detection_rules')->insert([
                'rule_key'         => $key,
                'rule_type'        => $type,
                'name_en'          => $nameEn,
                'name_ar'          => $nameAr,
                'description'      => $desc,
                'enabled'          => $enabled,
                'severity'         => $severity,
                'threshold'        => $threshold,
                'window_minutes'   => $window,
                'cooldown_minutes' => $cooldown,
                'actions'          => json_encode(['alert']),
                'block_minutes'    => 0,
                'config'           => $config === null ? null : json_encode($config),
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
        Schema::dropIfExists('security_health_errors');
        Schema::dropIfExists('security_health_samples');
    }
};
