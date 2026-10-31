<?php

namespace App\Console\Commands;

use App\Services\UserActivityService;
use Illuminate\Console\Command;

class ActivityPrune extends Command
{
    protected $signature = 'activity:prune {--days= : keep this many days (default ACTIVITY_LOG_RETENTION_DAYS, 90)}';
    protected $description = 'Delete per-user activity timeline rows older than the retention window';

    public function handle(UserActivityService $activity): int
    {
        $days = (int) ($this->option('days') ?: config('activity.retention_days', 90));
        $this->info('Deleted ' . $activity->prune($days) . " activity rows older than {$days} days.");

        return self::SUCCESS;
    }
}
