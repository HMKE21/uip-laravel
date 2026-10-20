<?php

namespace App\Console\Commands;

use App\Services\UniversityMergeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Merges universities that share a name: students and all other data move to the university that
 * is kept, the extra one is soft-deleted (restorable). Use --dry-run first.
 */
class UniversitiesMergeDuplicates extends Command
{
    protected $signature = 'universities:merge-duplicates {--dry-run : Show what would be moved, change nothing}';
    protected $description = 'Merge universities with the same name: move their students/data to one and delete the extras';

    public function handle(UniversityMergeService $service): int
    {
        $groups = $service->findGroups();
        if (!$groups) {
            $this->info('No duplicate universities.');
            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        $failed = 0;
        foreach ($groups as $ids) {
            $keep = $service->pickKeeper($ids);
            $rows = DB::table('universities')->whereIn('id', $ids)->get()->keyBy('id');
            $this->warn('Duplicate group: ' . implode(' | ', array_map(fn ($i) => "#$i " . ($rows[$i]->official_name_en ?: $rows[$i]->official_name_ar), $ids)));
            $this->line("  keeping #$keep");
            foreach ($ids as $dup) {
                if ($dup === $keep) {
                    continue;
                }
                $r = $service->merge($keep, $dup, $dry);
                if ($r['ok']) {
                    $this->info("  #$dup -> #$keep " . ($dry ? '(dry run) would move: ' : 'moved: ') . (json_encode($r['moved']) ?: '{}'));
                } else {
                    $failed++;
                    $this->error("  #$dup NOT merged: {$r['error']}");
                }
            }
        }

        // Sanity: after a real run no collisions may remain.
        if (!$dry && !$failed && $service->findGroups()) {
            $this->error('Duplicates still remain after merging.');
            return self::FAILURE;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
