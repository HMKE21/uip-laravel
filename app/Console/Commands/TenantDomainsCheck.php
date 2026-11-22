<?php

namespace App\Console\Commands;

use App\Services\Tenancy\TenantDomainAutomation;
use Illuminate\Console\Command;

/** تفعيل دومينات العملاء تلقائيًا (فحص DNS + طلب/فحص شهادة SSL). بيشتغل كل دقيقة من الـ scheduler. */
class TenantDomainsCheck extends Command
{
    protected $signature = 'tenant:domains-check {--limit= : أقصى عدد دومينات في الدورة}';
    protected $description = 'Auto-verify pending customer domains and check/request their SSL certificates';

    public function handle(): int
    {
        $r = TenantDomainAutomation::run($this->option('limit') !== null ? (int) $this->option('limit') : null);
        $this->info("checked={$r['checked']} activated={$r['activated']} ssl={$r['ssl']}");
        return self::SUCCESS;
    }
}
