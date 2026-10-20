<?php

namespace App\Console\Commands;

use App\Services\DriveService;
use Illuminate\Console\Command;

class DrivePurgeTrash extends Command
{
    protected $signature = 'drive:purge-trash';
    protected $description = 'Permanently delete My PC recycle-bin items older than DRIVE_TRASH_DAYS.';

    public function handle(DriveService $drive): int
    {
        $this->info('Purged ' . $drive->purgeExpired() . ' expired item(s).');
        return self::SUCCESS;
    }
}
