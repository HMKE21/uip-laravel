<?php

namespace App\Console\Commands;

use App\Support\UniversityName;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Read-only report of universities that share a (normalised) name — clean these up by hand, then rely on the API guard. */
class UniversitiesFindDuplicates extends Command
{
    protected $signature = 'universities:duplicates';
    protected $description = 'List existing universities whose Arabic/English names collide after normalisation';

    public function handle(): int
    {
        $groups = [];
        foreach (DB::table('universities')->whereNull('deleted_at')->get() as $u) {
            foreach (array_unique([UniversityName::normalize($u->official_name_ar), UniversityName::normalize($u->official_name_en)]) as $k) {
                if ($k !== '') {
                    $groups[$k][$u->id] = $u;
                }
            }
        }
        $found = 0;
        foreach ($groups as $key => $rows) {
            if (count($rows) < 2) {
                continue;
            }
            $found++;
            $this->warn("Duplicate: \"$key\"");
            foreach ($rows as $u) {
                $this->line("  #{$u->id}  user_id={$u->user_id}  {$u->official_name_ar} / {$u->official_name_en}");
            }
        }
        $this->info($found ? "$found duplicate group(s). Merge or delete the extras (delete = soft delete, restorable)." : 'No duplicates.');
        return self::SUCCESS;
    }
}
