<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * دمج بيانات حساب محذوف (المصدر) في حساب شغّال (الهدف).
 *  - اللي بيتنقل بيتحدد في config/account_recovery.php (مجموعات).
 *  - الهوية والأمان مبتتنقلش أبدًا.
 *  - لو فيه تعارض unique (الهدف عنده صف مشابه) الصفوف المتعارضة بتتساب مع القديم ومبتوقعش العملية.
 *  - كل عملية بتتسجّل في account_merges.
 */
class AccountMergeService
{
    /** @return array<int,array{key:string,label:string,default:bool,self:bool}> */
    public function groups(string $locale = 'ar', bool $forAdmin = false): array
    {
        $out = [];
        foreach ((array) config('account_recovery.groups', []) as $key => $g) {
            $selfOk = ($g['self'] ?? true) !== false;
            if (!$forAdmin && !$selfOk) {
                continue;
            }
            $out[] = [
                'key'     => $key,
                'label'   => $locale === 'ar' ? ($g['label_ar'] ?? $key) : ($g['label_en'] ?? $key),
                'default' => in_array($key, (array) config('account_recovery.default_groups', []), true),
                'self'    => $selfOk,
            ];
        }

        return $out;
    }

    /** يفلتر المطلوب على المعروف (ولو مش أدمن: على المسموح للذاتي بس)؛ فاضي => الافتراضي. */
    public function normalizeGroups(?array $requested, bool $forAdmin = false): array
    {
        $allowed = array_column($this->groups('en', $forAdmin), 'key');
        $requested = $requested === null || $requested === []
            ? (array) config('account_recovery.default_groups', [])
            : $requested;

        return array_values(array_intersect($allowed, array_map('strval', $requested)));
    }

    /** معاينة: كام صف في كل مجموعة هيتنقل (من غير ما ننقل حاجة). */
    public function preview(int $fromId, int $intoId, string $locale = 'ar', bool $forAdmin = false): array
    {
        $maps = $this->studentMap($fromId, $intoId);
        $out = [];
        foreach ($this->groups($locale, $forAdmin) as $g) {
            $count = 0;
            foreach ((array) config("account_recovery.groups.{$g['key']}.move", []) as [$table, $col, $kind]) {
                if (!$this->has($table, $col)) {
                    continue;
                }
                $from = $kind === 'student' ? ($maps['from'] ?? null) : $fromId;
                if ($from) {
                    $count += (int) DB::table($table)->where($col, $from)->count();
                }
            }
            $out[] = $g + ['count' => $count];
        }

        return $out;
    }

    /**
     * @param array<int,string> $groups
     * @return array{groups:array,moved:array,left_behind:array,filled:array}
     */
    public function merge(int $fromId, int $intoId, array $groups, ?int $actorId = null, string $mode = 'self'): array
    {
        if ($fromId === $intoId) {
            throw new \InvalidArgumentException('Cannot merge an account into itself.');
        }
        $forAdmin = $mode === 'admin';
        $groups = $this->normalizeGroups($groups, $forAdmin);
        $moved = [];
        $left = [];
        $filled = [];

        DB::transaction(function () use ($fromId, $intoId, $groups, &$moved, &$left, &$filled) {
            $maps = $this->studentMap($fromId, $intoId);

            foreach ($groups as $key) {
                $cfg = (array) config("account_recovery.groups.{$key}", []);

                foreach ((array) ($cfg['fill_user'] ?? []) as $col) {
                    if ($this->fill('users', 'id', $fromId, $intoId, $col)) {
                        $filled[] = "users.{$col}";
                    }
                }
                if (!empty($maps['from']) && !empty($maps['into'])) {
                    foreach ((array) ($cfg['fill_student'] ?? []) as $col) {
                        if ($this->fill('students', 'id', $maps['from'], $maps['into'], $col)) {
                            $filled[] = "students.{$col}";
                        }
                    }
                }

                foreach ((array) ($cfg['move'] ?? []) as [$table, $col, $kind]) {
                    if (!$this->has($table, $col)) {
                        continue;
                    }
                    if ($kind === 'student') {
                        if (empty($maps['from']) || empty($maps['into'])) {
                            continue;
                        }
                        [$from, $into] = [$maps['from'], $maps['into']];
                    } else {
                        [$from, $into] = [$fromId, $intoId];
                    }
                    [$m, $l] = $this->moveColumn($table, $col, $from, $into);
                    if ($m) {
                        $moved["{$table}.{$col}"] = $m;
                    }
                    if ($l) {
                        $left["{$table}.{$col}"] = $l;
                    }
                }
            }

            DB::table('users')->where('id', $fromId)->update(['merged_into_user_id' => $intoId]);
            DB::table('account_merges')->insert([
                'from_user_id'         => $fromId,
                'into_user_id'         => $intoId,
                'performed_by_user_id' => $actorId,
                'mode'                 => $mode,
                'groups'               => json_encode($groups),
                'moved'                => json_encode($moved),
                'left_behind'          => json_encode($left),
                'created_at'           => now(),
            ]);
        });

        return ['groups' => $groups, 'moved' => $moved, 'left_behind' => $left, 'filled' => $filled];
    }

    /** @return array{from:?int,into:?int} student ids للحسابين */
    private function studentMap(int $fromId, int $intoId): array
    {
        if (!Schema::hasTable('students')) {
            return ['from' => null, 'into' => null];
        }

        return [
            'from' => ($v = DB::table('students')->where('user_id', $fromId)->value('id')) ? (int) $v : null,
            'into' => ($v = DB::table('students')->where('user_id', $intoId)->value('id')) ? (int) $v : null,
        ];
    }

    /** @return array{0:int,1:int} [اتنقل, اتساب] */
    private function moveColumn(string $table, string $col, int $from, int $into): array
    {
        $total = (int) DB::table($table)->where($col, $from)->count();
        if ($total === 0) {
            return [0, 0];
        }
        try {
            // savepoint: تعارض unique يرجّع للنقطة دي بدل ما يبوّظ العملية كلها.
            DB::transaction(fn () => DB::table($table)->where($col, $from)->update([$col => $into]));

            return [$total, 0];
        } catch (\Throwable $e) {
            // نرجع لنقل صف صف (لو الجدول له id) وكل صف متعارض بيتساب.
            if (!$this->has($table, 'id')) {
                return [0, $total];
            }
        }
        $moved = 0;
        foreach (DB::table($table)->where($col, $from)->pluck('id') as $id) {
            try {
                DB::transaction(fn () => DB::table($table)->where('id', $id)->update([$col => $into]));
                $moved++;
            } catch (\Throwable $e) {
                // سيبه مع الحساب القديم
            }
        }

        return [$moved, $total - $moved];
    }

    /** ينسخ عمود للهدف لو فاضي عنده وموجود عند المصدر. */
    private function fill(string $table, string $keyCol, int $fromKey, int $intoKey, string $col): bool
    {
        if (!$this->has($table, $col)) {
            return false;
        }
        $src = DB::table($table)->where($keyCol, $fromKey)->value($col);
        $dst = DB::table($table)->where($keyCol, $intoKey)->value($col);
        $empty = fn ($v) => $v === null || $v === '' || $v === '[]' || $v === '{}';
        if ($empty($src) || !$empty($dst)) {
            return false;
        }
        DB::table($table)->where($keyCol, $intoKey)->update([$col => $src]);

        return true;
    }

    private function has(string $table, string $col): bool
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, $col);
    }
}
