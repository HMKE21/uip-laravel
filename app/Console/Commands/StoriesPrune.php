<?php

namespace App\Console\Commands;

use App\Services\StoryService;
use Illuminate\Console\Command;

class StoriesPrune extends Command
{
    protected $signature = 'stories:prune';
    protected $description = 'Delete stories (rows + media files) that expired more than a day ago.';

    public function handle(StoryService $stories): int
    {
        $this->info('Pruned ' . $stories->prune() . ' story(ies).');
        return self::SUCCESS;
    }
}
