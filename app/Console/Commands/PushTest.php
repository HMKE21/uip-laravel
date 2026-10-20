<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use App\Services\Push\FcmClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** php artisan push:test {user_id} — يبعت إشعار تجريبي (داخلي + Push) ويقولك الإعداد سليم ولا لأ. */
class PushTest extends Command
{
    protected $signature = 'push:test {user_id : رقم اليوزر}';
    protected $description = 'Send a test notification (in-app + FCM push) to a user and report the FCM setup';

    public function handle(FcmClient $fcm, NotificationService $notifications): int
    {
        $uid = (int) $this->argument('user_id');
        $this->line('FCM credentials: ' . ($fcm->isConfigured() ? 'OK' : 'MISSING (set FCM_CREDENTIALS_PATH or FCM_CREDENTIALS_JSON)'));
        $devices = DB::table('push_devices')->where('user_id', $uid)->count();
        $this->line("Registered devices for user #{$uid}: {$devices}");
        if ($devices === 0) {
            $this->warn('No device yet: open the app, sign in as this user and accept the notification permission.');
        }
        $notifications->notify($uid, 'system_test', 'UIP test notification', 'If you can read this on your phone, push works.', '/');
        $this->info('Notification created. Check the phone (close the app first to test the background path).');
        return self::SUCCESS;
    }
}
