<?php

namespace App\Console\Commands;

use App\Services\AuditRetentionService;
use Illuminate\Console\Command;

class AuditRetention extends Command
{
    protected $signature = 'audit:retention {--dry-run : only show what would be removed}';
    protected $description = 'Apply the audit/security log retention policy (archives, anchors the hash chain, then prunes)';

    public function handle(AuditRetentionService $retention): int
    {
        if ($this->option('dry-run')) {
            $p = $retention->preview();
            $this->info("Would remove {$p['audit_rows']} audit row(s) older than {$p['cutoff']} and {$p['log_files']} log file(s).");

            return self::SUCCESS;
        }
        try {
            $r = $retention->run(null, true);
        } catch (\RuntimeException $e) {
            $this->warn($e->getMessage());

            return self::SUCCESS; // legal hold / disabled = مش خطأ
        }
        $this->info("Removed {$r['audit_rows_deleted']} audit row(s) and {$r['log_files_deleted']} log file(s).");

        return self::SUCCESS;
    }
}
