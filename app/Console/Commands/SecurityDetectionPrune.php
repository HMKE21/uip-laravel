<?php

namespace App\Console\Commands;

use App\Services\SecurityDetectionService;
use Illuminate\Console\Command;

class SecurityDetectionPrune extends Command
{
    protected $signature = 'security:detection-prune {--days= : keep this many days of detection events (default: the retention setting, 14)}';
    protected $description = 'Delete old threat-detection events and expired auto-blocks';

    public function handle(SecurityDetectionService $engine): int
    {
        $r = $engine->prune($this->option('days') ? (int) $this->option('days') : null);
        $this->info("Deleted {$r['events']} detection events and {$r['blocks']} expired IP blocks.");

        return self::SUCCESS;
    }
}
