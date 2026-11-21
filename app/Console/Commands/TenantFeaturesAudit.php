<?php

namespace App\Console\Commands;

use App\Services\Tenancy\TenantFeatures;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

/** يعدّي على كل routes الـ API ويعرض أي مسار لسه مش متصنّف في config/tenant_features.php (هيترفض عند العملاء). */
class TenantFeaturesAudit extends Command
{
    protected $signature = 'tenant:features-audit {--strict : يرجّع فشل (للـ CI) لو فيه مسارات مش متصنّفة} {--summary : عدد المسارات لكل ميزة}';
    protected $description = 'يفحص تغطية config/tenant_features.php لكل مسارات الـ API.';

    public function handle(): int
    {
        TenantFeatures::resetCaches();
        $counts = [];
        $unmapped = [];
        foreach (Route::getRoutes() as $route) {
            $p = TenantFeatures::normalizePath('/' . ltrim($route->uri(), '/'));
            if ($p === null || $p === '') {
                continue;
            }
            $owner = TenantFeatures::classify($p);
            if ($owner === null) {
                $first = explode('/', $p);
                $key = in_array($first[0], ['dev', 'scim'], true) && isset($first[1]) ? $first[0] . '/' . $first[1] : $first[0];
                $unmapped[$key] = ($unmapped[$key] ?? 0) + 1;
                continue;
            }
            $counts[$owner] = ($counts[$owner] ?? 0) + 1;
        }

        if ($this->option('summary')) {
            ksort($counts);
            $this->table(['Owner', 'Routes'], array_map(fn ($k, $v) => [$k, $v], array_keys($counts), $counts));
        }
        if (!$unmapped) {
            $this->info('كل المسارات متصنّفة ✅');
            return self::SUCCESS;
        }
        ksort($unmapped);
        $this->warn('مسارات مش متصنّفة (بتترفض عند العملاء لحد ما تضيفها في config/tenant_features.php):');
        $this->table(['Prefix', 'Routes'], array_map(fn ($k, $v) => [$k, $v], array_keys($unmapped), $unmapped));
        return $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }
}
