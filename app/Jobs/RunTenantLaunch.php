<?php

namespace App\Jobs;

use App\Services\Tenancy\TenantLauncher;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** إطلاق منصة في الخلفية (قاعدة بيانات + migrations + أدمن + قالب + ميزات). */
class RunTenantLaunch
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 900;
    public int $tries = 1;

    public function __construct(public int $launchId)
    {
    }

    public function handle(TenantLauncher $launcher): void
    {
        $launcher->run($this->launchId);
    }
}
