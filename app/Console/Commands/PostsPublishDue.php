<?php

namespace App\Console\Commands;

use App\Services\PostService;
use Illuminate\Console\Command;

class PostsPublishDue extends Command
{
    protected $signature = 'posts:publish-due';
    protected $description = 'Publish scheduled social posts whose time has come.';

    public function handle(PostService $posts): int
    {
        $this->info('Published ' . $posts->publishDue() . ' scheduled post(s).');
        return self::SUCCESS;
    }
}
