<?php

namespace App\Console\Commands;

use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TenantMigrate extends Command
{
    protected $signature = 'tenant:migrate {--tenant= : id أو slug (الافتراضي: كل العملاء المعزولين الجاهزين)}';
    protected $description = 'يشغّل الـ migrations الجديدة على قاعدة كل عميل معزول.';

    public function handle(TenantProvisioner $p): int
    {
        $q = DB::connection(TenantContext::centralConnection())->table('tenants')->where('mode', 'isolated')->where('db_status', 'ready');
        if ($t = $this->option('tenant')) {
            $q->where(fn ($w) => $w->where('id', $t)->orWhere('slug', $t));
        }
        $failed = 0;
        foreach ($q->get() as $tenant) {
            $this->line("→ {$tenant->slug} ({$tenant->db_name})");
            try {
                TenantContext::run($tenant, fn () => $p->migrateCurrent());
            } catch (\Throwable $e) {
                $failed++;
                $this->error("  failed: {$e->getMessage()}");
            }
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
