<?php

namespace App\Console\Commands;

use App\Services\EscalationService;
use Illuminate\Console\Command;

class EscalationTick extends Command
{
    protected $signature = 'escalation:tick';
    protected $description = 'Escalate unacknowledged alerts/incidents to level 2 and send due security digests';

    public function handle(EscalationService $esc): int
    {
        $r = $esc->tick();
        $this->info("Escalated {$r['escalated']}, acknowledged {$r['acked']}, digests sent {$r['digests']}.");

        return self::SUCCESS;
    }
}
