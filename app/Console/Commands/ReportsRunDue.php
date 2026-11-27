<?php

namespace App\Console\Commands;

use App\Services\ReportSchedulerService;
use Illuminate\Console\Command;

class ReportsRunDue extends Command
{
    protected $signature = 'reports:run-due';
    protected $description = 'Generate and e-mail the scheduled reports (report_schedules) that are due';

    public function handle(ReportSchedulerService $scheduler): int
    {
        $n = $scheduler->runDue();
        $this->info("Ran {$n} scheduled report(s).");

        return self::SUCCESS;
    }
}
