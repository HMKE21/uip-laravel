<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\DisposableEmail;
use Illuminate\Console\Command;

/**
 * Read-only report of EXISTING accounts whose e-mail would be refused today (temp-mail domain, +alias,
 * dot-padded Gmail). Nothing is changed: review the list and deal with each account yourself.
 */
class AuditSuspiciousEmails extends Command
{
    protected $signature = 'mail:audit-suspicious-users {--unverified : only accounts whose e-mail is not verified yet}';
    protected $description = 'List existing users whose e-mail looks temporary / generated (read-only)';

    public function handle(): int
    {
        $rows = [];
        $query = User::query()->select(['id', 'email', 'status', 'email_verified_at', 'created_at']);
        if ($this->option('unverified')) {
            $query->whereNull('email_verified_at');
        }
        $query->orderBy('id')->chunkById(500, function ($users) use (&$rows) {
            foreach ($users as $u) {
                $email = (string) $u->email;
                if ($email === '' || str_starts_with($email, 'deleted-')) {
                    continue;
                }
                if (DisposableEmail::isDisposable($email)) {
                    $why = 'temp-mail domain';
                } elseif (DisposableEmail::isSuspiciousMailbox($email)) {
                    $why = 'alias / generated pattern';
                } else {
                    continue;
                }
                $rows[] = [$u->id, $email, $u->status, $u->email_verified_at ? 'yes' : 'no', (string) $u->created_at, $why];
            }
        });

        if (!$rows) {
            $this->info('No suspicious e-mail addresses found.');
            return self::SUCCESS;
        }
        $this->table(['id', 'email', 'status', 'verified', 'created_at', 'reason'], $rows);
        $this->warn(count($rows) . ' account(s) flagged. Nothing was changed.');
        return self::SUCCESS;
    }
}
