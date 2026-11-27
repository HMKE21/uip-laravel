<?php

namespace App\Console\Commands;

use App\Services\AuditChainService;
use Illuminate\Console\Command;

class AuditVerify extends Command
{
    protected $signature = 'audit:verify {--scheduled : record the run as scheduled}';
    protected $description = 'Verify the audit log hash chain and alert the security team if it was tampered with';

    public function handle(AuditChainService $chain): int
    {
        $chain->sealPending();
        $r = $chain->verify($this->option('scheduled') ? 'scheduled' : 'manual');
        $this->info("Audit chain: {$r['status']} — {$r['checked_rows']} row(s) checked, {$r['issues_total']} issue(s).");
        foreach (array_slice($r['issues'], 0, 10) as $i) {
            $this->warn("  #{$i['seq']} [{$i['type']}] {$i['detail']}");
        }

        return $r['status'] === 'broken' ? self::FAILURE : self::SUCCESS;
    }
}
