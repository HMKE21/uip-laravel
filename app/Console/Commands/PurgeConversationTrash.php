<?php

namespace App\Console\Commands;

use App\Services\ConversationTrashService;
use Illuminate\Console\Command;

/** بيحذف نهائيًا المحادثات اللي فضلت في سلة المحذوفات أكتر من 30 يوم (مجدول يوميًا في routes/console.php). */
class PurgeConversationTrash extends Command
{
    protected $signature = 'messaging:purge-trash';

    protected $description = 'Permanently deletes conversations that have been in the trash for more than 30 days.';

    public function handle(ConversationTrashService $trash): int
    {
        $n = $trash->purgeExpired();
        $this->info("Purged {$n} expired conversation(s) from the trash.");
        return self::SUCCESS;
    }
}
