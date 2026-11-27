<?php

namespace App\Console\Commands;

use App\Services\AuditChainService;
use Illuminate\Console\Command;

class AuditSeal extends Command
{
    protected $signature = 'audit:seal {--max=0 : max rows to seal in this run (0 = all)}';
    protected $description = 'Seal audit log rows that are not part of the tamper-evident hash chain yet';

    public function handle(AuditChainService $chain): int
    {
        $n = $chain->sealPending((int) $this->option('max'));
        $chain->checkpoint('auto');
        $this->info("Sealed {$n} audit log row(s).");

        return self::SUCCESS;
    }
}
