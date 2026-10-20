<?php

namespace App\Services;

use App\Repositories\UniversityRepository;
use App\Support\UniversityName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One university = one name. Finds universities that share a (normalised) name and merges
 * each group into ONE: every row that points at a duplicate (students, supervisors, academic
 * staff, faculties/departments, groups, projects, exams, announcements, ...) is moved
 * to the university that is kept, then the duplicate is soft-deleted (restorable).
 *
 * The move is one DB transaction per duplicate, so a failure (e.g. the same supervisor e-mail
 * existing in both universities) rolls that merge back untouched and is reported.
 */
class UniversityMergeService
{
    /** Tables that hold the faculty/department tree itself — handled explicitly. */
    private const TREE_TABLES = ['faculties', 'departments'];

    /**
     * Groups of live universities that collide on a normalised name (Arabic or English, cross-language too).
     *
     * @return array<int,int[]> list of id groups, each with 2+ ids
     */
    public function findGroups(): array
    {
        $rows = DB::table('universities')->whereNull('deleted_at')->get(['id', 'official_name_ar', 'official_name_en']);

        $parent = [];
        $find = function (int $x) use (&$parent, &$find): int {
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x = $parent[$x];
            }
            return $x;
        };
        $owner = [];
        foreach ($rows as $r) {
            $id = (int) $r->id;
            $parent[$id] = $id;
        }
        foreach ($rows as $r) {
            $id = (int) $r->id;
            foreach (array_unique([UniversityName::normalize($r->official_name_ar), UniversityName::normalize($r->official_name_en)]) as $k) {
                if ($k === '') {
                    continue;
                }
                if (isset($owner[$k])) {
                    $parent[$find($id)] = $find($owner[$k]);
                } else {
                    $owner[$k] = $id;
                }
            }
        }

        $groups = [];
        foreach (array_keys($parent) as $id) {
            $groups[$find($id)][] = $id;
        }

        return array_values(array_filter($groups, fn ($g) => count($g) > 1));
    }

    /**
     * Which university of a group survives: verified first, then the one with most students, then the oldest.
     */
    public function pickKeeper(array $ids): int
    {
        $rows = DB::table('universities')->whereIn('id', $ids)->get(['id', 'verification_status']);
        $students = DB::table('students')->whereIn('university_id', $ids)
            ->selectRaw('university_id, COUNT(*) as c')->groupBy('university_id')->pluck('c', 'university_id');

        $sorted = $rows->sort(function ($a, $b) use ($students) {
            return [($b->verification_status === 'verified') <=> ($a->verification_status === 'verified'),
                    (int) ($students[$b->id] ?? 0) <=> (int) ($students[$a->id] ?? 0),
                    $a->id <=> $b->id];
        })->values();

        return (int) $sorted->first()->id;
    }

    /**
     * Merge every duplicate group.
     *
     * @return array<int,array<string,mixed>> one report per merged duplicate
     */
    public function mergeAll(bool $dryRun = false): array
    {
        $reports = [];
        foreach ($this->findGroups() as $ids) {
            $keep = $this->pickKeeper($ids);
            foreach ($ids as $dup) {
                if ($dup === $keep) {
                    continue;
                }
                $reports[] = $this->merge($keep, $dup, $dryRun);
            }
        }
        return $reports;
    }

    /**
     * Move everything from $dupId into $keepId and soft-delete $dupId.
     *
     * @return array{keep:int,duplicate:int,ok:bool,error:?string,moved:array<string,int>,dry_run:bool}
     */
    public function merge(int $keepId, int $dupId, bool $dryRun = false): array
    {
        $report = ['keep' => $keepId, 'duplicate' => $dupId, 'ok' => false, 'error' => null, 'moved' => [], 'dry_run' => $dryRun];

        if ($keepId === $dupId
            || !DB::table('universities')->where('id', $keepId)->whereNull('deleted_at')->exists()
            || !DB::table('universities')->where('id', $dupId)->whereNull('deleted_at')->exists()) {
            $report['error'] = 'Both universities must exist, be different and not be deleted.';
            return $report;
        }

        try {
            DB::transaction(function () use ($keepId, $dupId, $dryRun, &$report) {
                $report['moved'] = $this->moveEverything($keepId, $dupId);
                if ($dryRun) {
                    throw new class extends \RuntimeException {};
                }
            });
        } catch (\Throwable $e) {
            if ($dryRun && $e instanceof \RuntimeException && $e->getMessage() === '') {
                $report['ok'] = true;
                return $report;
            }
            $report['error'] = $e->getMessage();
            return $report;
        }

        // Nothing is attached to the duplicate any more, so this suspends nobody; it deletes
        // the duplicate's university account too and closes its sessions.
        $res = app(UniversityRepository::class)->softDelete($dupId);
        $report['ok'] = (bool) $res['ok'];
        $report['error'] = $report['ok'] ? null : 'Could not delete the duplicate university.';

        return $report;
    }

    /** @return array<string,int> table => rows moved */
    private function moveEverything(int $keepId, int $dupId): array
    {
        $moved = [];
        $tables = array_map(fn ($t) => is_array($t) ? $t['name'] : $t->name ?? $t, Schema::getTables());

        // -- 1. faculty / department tree ---------------------------------------
        $facMap = $depMap = [];
        $keepFaculties = DB::table('faculties')->where('university_id', $keepId)->get()->keyBy('slug');

        foreach (DB::table('faculties')->where('university_id', $dupId)->get() as $fac) {
            $twin = $keepFaculties[$fac->slug] ?? null;
            if (!$twin) {
                continue; // no twin: the generic step moves it as is
            }
            $facMap[$fac->id] = $twin->id;

            $twinDeps = DB::table('departments')->where('faculty_id', $twin->id)->get()->keyBy('slug');
            foreach (DB::table('departments')->where('faculty_id', $fac->id)->get() as $dep) {
                $depTwin = $twinDeps[$dep->slug] ?? null;
                if (!$depTwin) {
                    DB::table('departments')->where('id', $dep->id)->update(['faculty_id' => $twin->id]);
                    continue;
                }
                $depMap[$dep->id] = $depTwin->id;
                // Keep the emptied twin as an archived row (nothing is hard-deleted).
                DB::table('departments')->where('id', $dep->id)->update(['status' => 'inactive', 'slug' => $dep->slug . '-merged-' . $dep->id]);
            }

            // The faculty twin is archived, not deleted; its slug is freed for the unique key.
            DB::table('faculties')->where('id', $fac->id)->update([
                'status' => 'archived', 'slug' => $fac->slug . '-merged-' . $fac->id, 'university_id' => $keepId,
            ]);
        }

        foreach (['faculty_id' => $facMap, 'department_id' => $depMap] as $col => $map) {
            if (!$map) {
                continue;
            }
            foreach ($tables as $table) {
                if (in_array($table, self::TREE_TABLES, true) || !Schema::hasColumn($table, $col)) {
                    continue;
                }
                foreach ($map as $old => $new) {
                    $n = DB::table($table)->where($col, $old)->update([$col => $new]);
                    if ($n) {
                        $moved["$table.$col"] = ($moved["$table.$col"] ?? 0) + $n;
                    }
                }
            }
        }

        // -- 2. student groups: same name in both => keep both, label the moved one ---------
        $keepGroupNames = DB::table('student_groups')->where('university_id', $keepId)->pluck('name')->all();
        foreach (DB::table('student_groups')->where('university_id', $dupId)->get() as $g) {
            if (in_array($g->name, $keepGroupNames, true)) {
                DB::table('student_groups')->where('id', $g->id)->update(['name' => mb_substr($g->name . ' (' . $g->id . ')', 0, 200)]);
            }
        }

        // -- 3. everything that points at the university (students first in the report) ----
        foreach ($tables as $table) {
            if ($table === 'universities' || !Schema::hasColumn($table, 'university_id')) {
                continue;
            }
            $n = DB::table($table)->where('university_id', $dupId)->update(['university_id' => $keepId]);
            if ($n) {
                $moved[$table] = $n;
            }
        }

        // Anyone suspended "because of" the duplicate is now under the kept university.
        if (Schema::hasColumn('users', 'suspended_by_university_id')) {
            DB::table('users')->where('suspended_by_university_id', $dupId)->update(['suspended_by_university_id' => $keepId]);
        }

        return $moved;
    }
}
