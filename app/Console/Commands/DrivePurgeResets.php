<?php

namespace App\Console\Commands;

use App\Services\DesktopResetService;
use Illuminate\Console\Command;

class DrivePurgeResets extends Command
{
    protected $signature = 'drive:purge-resets';
    protected $description = 'Permanently delete the files of My PC resets whose 24-hour undo window has passed.';

    public function handle(DesktopResetService $resets): int
    {
        $this->info('Finalized ' . $resets->finalizeExpired() . ' reset(s).');
        return self::SUCCESS;
    }
}
