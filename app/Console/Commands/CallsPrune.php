<?php

namespace App\Console\Commands;

use App\Services\CallService;
use Illuminate\Console\Command;

class CallsPrune extends Command
{
    protected $signature = 'calls:prune';
    protected $description = 'Close stuck calls and delete call rows (+ signals) older than CALLS_PRUNE_DAYS.';

    public function handle(CallService $calls): int
    {
        $this->info('Pruned ' . $calls->prune() . ' call(s).');
        return self::SUCCESS;
    }
}
