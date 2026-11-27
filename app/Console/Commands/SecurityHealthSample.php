<?php

namespace App\Console\Commands;

use App\Services\SecurityDetectionService;
use App\Services\SecurityHealthService;
use Illuminate\Console\Command;

class SecurityHealthSample extends Command
{
    protected $signature = 'security:health-sample {--prune : only delete samples / 5xx counters older than the retention period (14 days)}';
    protected $description = 'Sample CPU / RAM / disk / database size / job queue, then evaluate the server-health detection rules';

    public function handle(SecurityHealthService $health, SecurityDetectionService $engine): int
    {
        if (!$health->available()) {
            $this->warn('Health tables are missing. Run the database migrations.');
            return self::SUCCESS;
        }

        if ($this->option('prune')) {
            $r = $health->prune();
            $this->info("Deleted {$r['samples']} samples and {$r['errors']} error counters.");
            return self::SUCCESS;
        }

        $row = $health->sample();
        $engine->evaluateHealth($health);
        if ($row && $this->output->isVerbose()) {
            $this->line(json_encode(array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('c') : $v, $row)));
        }

        return self::SUCCESS;
    }
}
