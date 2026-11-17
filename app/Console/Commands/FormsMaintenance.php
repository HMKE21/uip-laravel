<?php

namespace App\Console\Commands;

use App\Services\Forms\FormFileService;
use App\Services\Forms\FormSubmissionService;
use Illuminate\Console\Command;

/** Run every 5 minutes: auto-submit exams whose time is over + delete uploads nobody attached to an answer. */
class FormsMaintenance extends Command
{
    protected $signature = 'forms:maintenance';
    protected $description = 'Smart Forms: auto-submit expired exam attempts and prune orphan uploads.';

    public function handle(FormSubmissionService $subs, FormFileService $files): int
    {
        $a = $subs->autoSubmitExpired();
        $p = $files->pruneOrphans();
        $this->info("auto-submitted: {$a}, orphan uploads removed: {$p}");
        return self::SUCCESS;
    }
}
