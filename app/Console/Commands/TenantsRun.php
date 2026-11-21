<?php

namespace App\Console\Commands;

use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantFeatures;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * يشغّل أمر artisan على كل عميل معزول (الخطوة 5) — عشان الأوامر المجدولة (نشر المنشورات، تنضيف السلة، الإيميلات…) بتشتغل على
 * قاعدة المنصة بس، وكل عميل ليه قاعدته.
 *
 *   php artisan tenants:run "posts:publish-due" --feature=feed
 *   php artisan tenants:run "mail:process --purge" --feature=mail
 *   php artisan tenants:run "activity:prune" --tenant=demo
 *
 * --feature  : يتخطّى العملاء اللي الميزة مش مفعّلة عندهم (توفير وقت: مفيش داعي نلف على 200 عميل عشان ميزة 3 منهم بس فاتحينها).
 * --tenant   : عميل واحد (id أو slug).
 * فشل عميل مبيوقّفش الباقي؛ الأمر بيرجّع FAILURE لو أي عميل فشل.
 */
class TenantsRun extends Command
{
    protected $signature = 'tenants:run {line : أمر artisan كامل بين علامتي تنصيص، مثال "mail:process --purge"} {--feature= : شغّل بس على العملاء اللي الميزة دي مفعّلة عندهم} {--tenant= : id أو slug}';
    protected $description = 'يشغّل أمر artisan على كل عميل معزول جاهز (بيفعّل قاعدة كل عميل لوحده).';

    public function handle(): int
    {
        $line = trim((string) $this->argument('line'));
        if ($line === '' || str_starts_with($line, 'tenants:run')) {
            $this->error('Give a command to run, e.g. "posts:publish-due".');
            return self::INVALID;
        }
        $feature = trim((string) $this->option('feature'));
        if ($feature !== '' && !array_key_exists($feature, TenantFeatures::catalog())) {
            $this->error("Unknown feature '{$feature}'.");
            return self::INVALID;
        }

        $q = DB::connection(TenantContext::centralConnection())->table('tenants')
            ->where('mode', 'isolated')->where('db_status', 'ready')->where('status', 'active');
        if ($t = $this->option('tenant')) {
            $q->where(fn ($w) => $w->where('id', $t)->orWhere('slug', $t));
        }

        $failed = 0;
        $ran = 0;
        foreach ($q->orderBy('id')->get(['id'])->pluck('id') as $id) {
            if ($feature !== '' && !TenantFeatures::isEnabled((int) $id, $feature)) {
                continue;
            }
            $tenant = TenantContext::findIsolated((int) $id);
            if (!$tenant) {
                continue;
            }
            try {
                $code = TenantContext::run($tenant, fn () => Artisan::call($line));
                $ran++;
                if ($code !== 0) {
                    $failed++;
                    $this->error("tenant {$id}: '{$line}' exited with {$code}");
                } elseif ($this->output->isVerbose()) {
                    $this->line("tenant {$id}: ok");
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->error("tenant {$id}: " . $e->getMessage());
            }
        }

        if ($this->output->isVerbose()) {
            $this->info("ran on {$ran} tenant(s), {$failed} failed");
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
