<?php

namespace App\Console\Commands;

use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantFeatures;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * إدارة ميزات عميل من الـ CLI (بديل لوحة الأدمن).
 *   php artisan tenant:feature demo list
 *   php artisan tenant:feature demo enable messaging meetings
 *   php artisan tenant:feature demo enable all
 *   php artisan tenant:feature demo disable meetings --note="انتهى الاشتراك"
 */
class TenantFeature extends Command
{
    protected $signature = 'tenant:feature {tenant : id أو slug} {action : list|enable|disable|reject} {features?* : مفاتيح الميزات أو all} {--note=}';
    protected $description = 'يعرض/يفعّل/يعطّل ميزات عميل معزول.';

    public function handle(): int
    {
        $key = (string) $this->argument('tenant');
        $t = DB::connection(TenantContext::centralConnection())->table('tenants')->where('mode', 'isolated')
            ->where(fn ($w) => $w->where('id', $key)->orWhere('slug', $key))->first();
        if (!$t) {
            $this->error('العميل مش موجود (أو مش معزول).');
            return self::FAILURE;
        }
        $action = (string) $this->argument('action');
        if ($action === 'list') {
            $this->table(['Feature', 'Status', 'Note'], array_map(fn ($r) => [$r['feature'], $r['status'], $r['decision_note'] ?? $r['request_note']], TenantFeatures::stateFor((int) $t->id)));
            return self::SUCCESS;
        }
        $status = ['enable' => 'enabled', 'disable' => 'disabled', 'reject' => 'rejected'][$action] ?? null;
        if (!$status) {
            $this->error('action لازم يكون list|enable|disable|reject.');
            return self::INVALID;
        }
        $features = (array) $this->argument('features');
        if ($features === ['all']) {
            $features = array_keys(TenantFeatures::catalog());
        }
        if (!$features) {
            $this->error('حدد ميزة واحدة على الأقل (أو all).');
            return self::INVALID;
        }
        $bad = array_diff($features, array_keys(TenantFeatures::catalog()));
        if ($bad) {
            $this->error('ميزات مش معروفة: ' . implode(', ', $bad) . ' — المتاح: ' . implode(', ', array_keys(TenantFeatures::catalog())));
            return self::INVALID;
        }
        foreach ($features as $f) {
            TenantFeatures::decide((int) $t->id, $f, $status, null, $this->option('note') ?: null);
            $this->line("{$f} → {$status}");
        }
        return self::SUCCESS;
    }
}
