<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/DataQualityRepository.php القديمة — بند
 * 24 batch 3 (Data Quality Center، enhancement spec section 6). مفيش
 * جداول جديدة — كل فحص شغال حي فوق نفس الست جداول catalog اللي Data
 * Explorer بيعرضها بالفعل (بتستخدم DataExplorerRepository لفحص
 * الأعمدة/الميتاداتا عشان منطق الفحص عمره ميبعدش عن السكيما الحقيقية).
 * مفيش حاجة هنا متخزّنة مؤقتًا أو محاكاة: completeness/duplicates/
 * outliers/validity/freshness متحسوبة جديدة في كل طلب، نفس اتفاقية
 * "حي، مش مخزّن مؤقتًا" بتاعة Data Explorer وData Segments.
 */
class DataQualityRepository
{
    /** أجزاء من اسم العمود بتاخد فحص صيغة حقيقي بدل فحص null بس. */
    private const EMAIL_HINTS = ['email'];

    private const URL_HINTS = ['url', 'link'];

    public function __construct(private DataExplorerRepository $explorer)
    {
    }

    private function quoteIdent(string $ident): string
    {
        return '`' . preg_replace('/[^A-Za-z0-9_]/', '', $ident) . '`';
    }

    /**
     * هل العمود نصي؟ مقارنة العمود بـ '' بتبوّظ (SQLSTATE 1525) على
     * DATETIME/DATE/TIMESTAMP/TIME في MySQL strict، فمقارنة الفاضي
     * بتتطبق على الأعمدة النصية بس؛ الباقي بيتفحص بـ IS NULL فقط.
     */
    private function isTextType(string $type): bool
    {
        $type = strtoupper($type);
        foreach (['CHAR', 'TEXT', 'ENUM', 'CLOB'] as $hint) {
            if (str_contains($type, $hint)) {
                return true;
            }
        }
        return false;
    }

    /** نفس فحص الـ driver بتاع DataExplorerRepository — لازم لتبديل RAND()/RANDOM() في outliers(). */
    private function driver(): string
    {
        $default = config('database.default', 'mysql');
        return config("database.connections.{$default}.driver", $default);
    }

    /** Percentile بالـ linear interpolation (طريقة Tukey/"exclusive" القياسية) على مصفوفة رقمية متفرزة بالفعل. */
    private function percentile(array $sorted, float $p): float
    {
        $n = count($sorted);
        if ($n === 1) {
            return $sorted[0];
        }
        $rank = $p * ($n - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);
        if ($low === $high) {
            return $sorted[$low];
        }
        $fraction = $rank - $low;
        return $sorted[$low] + ($sorted[$high] - $sorted[$low]) * $fraction;
    }

    /** تقرير جودة كامل لـ dataset واحد: completeness/duplicates/outliers/validity/freshness ودرجة إجمالية 0-100. */
    public function report(array $dataset): array
    {
        $table = $dataset['table'];
        $columns = $this->explorer->columns($table);
        $visible = $this->explorer->visibleColumnNames($dataset);

        $totalRows = (int) (DB::selectOne('SELECT COUNT(*) AS c FROM ' . $this->quoteIdent($table))->c ?? 0);

        $completeness = $this->completeness($table, $columns, $visible, $totalRows);
        $duplicates = $this->duplicates($table, $this->contentColumns($columns, $visible), $totalRows);
        $outliers = $this->outliers($table, $columns, $visible, $totalRows);
        $validity = $this->validity($table, $columns, $visible, $totalRows);
        $freshness = $this->freshness($table, $columns);

        $scoreParts = array_filter([
            $completeness['pct'],
            $duplicates['pct'],
            $validity['pct'],
            $freshness['pct'],
        ], fn ($v) => $v !== null);
        $score = $scoreParts ? array_sum($scoreParts) / count($scoreParts) : null;

        return [
            'table'        => $table,
            'total_rows'   => $totalRows,
            'completeness' => $completeness,
            'duplicates'   => $duplicates,
            'outliers'     => $outliers,
            'validity'     => $validity,
            'freshness'    => $freshness,
            'score'        => $score !== null ? round($score, 1) : null,
        ];
    }

    /** % من القيم غير الفارغة/غير null عبر الأعمدة الظاهرة، بالإضافة لأسوأ الحالات. */
    private function completeness(string $table, array $columns, array $visible, int $totalRows): array
    {
        if (!$visible || $totalRows === 0) {
            return ['pct' => null, 'columns' => []];
        }

        $types = [];
        foreach ($columns as $c) {
            $types[$c['name']] = (string) ($c['type'] ?? '');
        }

        $perColumn = [];
        foreach ($visible as $col) {
            $ident = $this->quoteIdent($col);
            // مقارنة '' بتتطبق على الأعمدة النصية بس (DATETIME بيرمي 1525)
            $where = $this->isTextType($types[$col] ?? '')
                ? "{$ident} IS NULL OR {$ident} = ''"
                : "{$ident} IS NULL";
            $missing = (int) (DB::selectOne(
                "SELECT COUNT(*) AS c FROM " . $this->quoteIdent($table) . " WHERE {$where}"
            )->c ?? 0);
            $perColumn[] = [
                'column'      => $col,
                'missing'     => $missing,
                'missing_pct' => round(($missing / $totalRows) * 100, 1),
            ];
        }

        usort($perColumn, fn ($a, $b) => $b['missing_pct'] <=> $a['missing_pct']);
        $avgCompleteness = 100 - (array_sum(array_column($perColumn, 'missing_pct')) / count($perColumn));

        return [
            'pct'     => round($avgCompleteness, 1),
            'columns' => array_slice($perColumn, 0, 5), // أسوأ 5، للواجهة
        ];
    }

    /**
     * الأعمدة المهمة لكشف التكرار: بتستبعد المفتاح الأساسي، أعمدة
     * المعرّف بصيغة UUID، وأعمدة الطوابع الزمنية — دول فريدين/شبه
     * فريدين بالتصميم، فتضمينهم هيخلي كشف تكرار الصف الكامل عمره ميشتغلش
     * على جداول حقيقية.
     */
    private function contentColumns(array $columns, array $visible): array
    {
        $timestampNames = ['created_at', 'updated_at', 'deleted_at', 'published_at', 'opened_at', 'recorded_at', 'completed_at'];
        $out = [];
        foreach ($columns as $col) {
            if (!in_array($col['name'], $visible, true)) {
                continue;
            }
            if ($col['is_primary']) {
                continue;
            }
            $lower = strtolower($col['name']);
            if (str_contains($lower, 'uuid') || in_array($lower, $timestampNames, true)) {
                continue;
            }
            $out[] = $col['name'];
        }
        return $out;
    }

    /** كشف تكرار صف كامل بالضبط عبر الأعمدة المهمة (content). */
    private function duplicates(string $table, array $visible, int $totalRows): array
    {
        if (!$visible || $totalRows === 0) {
            return ['pct' => null, 'group_count' => 0, 'row_count' => 0];
        }

        $cols = implode(', ', array_map(fn ($c) => $this->quoteIdent($c), $visible));
        $groupCount = (int) (DB::selectOne(
            "SELECT COUNT(*) AS c FROM (
                SELECT {$cols} FROM " . $this->quoteIdent($table) . "
                GROUP BY {$cols} HAVING COUNT(*) > 1
             ) dup_groups"
        )->c ?? 0);

        $rowCount = (int) (DB::selectOne(
            "SELECT SUM(cnt) AS total FROM (
                SELECT COUNT(*) AS cnt FROM " . $this->quoteIdent($table) . "
                GROUP BY {$cols} HAVING COUNT(*) > 1
             ) dup_groups"
        )->total ?? 0);

        return [
            'pct'         => round(100 - (($rowCount / $totalRows) * 100), 1),
            'group_count' => $groupCount,
            'row_count'   => $rowCount,
        ];
    }

    /** شواذ إحصائية (|z-score| > 3) على الأعمدة الرقمية الظاهرة. */
    private function outliers(string $table, array $columns, array $visible, int $totalRows): array
    {
        if ($totalRows < 4) {
            return []; // مفيش بيانات كفاية لتوزيع له معنى
        }
        $numericTypes = ['INT', 'BIGINT', 'SMALLINT', 'DECIMAL', 'FLOAT', 'DOUBLE', 'NUMERIC', 'INTEGER', 'REAL'];
        $out = [];

        foreach ($columns as $col) {
            if (!in_array($col['name'], $visible, true)) {
                continue;
            }
            $typeMatches = false;
            foreach ($numericTypes as $t) {
                if (str_starts_with($col['type'], $t)) {
                    $typeMatches = true;
                    break;
                }
            }
            if (!$typeMatches || $col['is_primary']) {
                continue; // متخطى المفاتيح الأساسية — مش "قيمة" لها معنى
            }

            $ident = $this->quoteIdent($col['name']);

            // IQR (طريقة Tukey)، مش z-score: حد z-score/stddev مش موثوق
            // على جداول متوسطة-لصغيرة لأن قيمة واحدة متطرفة بتضخّم
            // stddev بتاعها لدرجة تخبي نفسها. الـ IQR متحسوب من نص
            // القيم الأوسط (50%)، فنقطة متطرفة واحدة مايقدرش يحرّف
            // الحدود اللي بتكشفه. أخذ عينة لحد 5000 قيمة بيخلي ده محدود
            // على الجداول الكبيرة (نفس المفاضلة اللي preview() بتاع
            // Data Explorer نفسه بيعملها).
            $values = array_map(
                fn ($r) => (float) $r->{$col['name']},
                DB::select(
                    "SELECT {$ident} FROM " . $this->quoteIdent($table) . "
                      WHERE {$ident} IS NOT NULL
                      ORDER BY " . ($this->driver() === 'sqlite' ? 'RANDOM()' : 'RAND()') . "
                      LIMIT 5000"
                )
            );
            if (count($values) < 4) {
                continue;
            }
            sort($values);
            $q1 = $this->percentile($values, 0.25);
            $q3 = $this->percentile($values, 0.75);
            $iqr = $q3 - $q1;
            if ($iqr <= 0.0) {
                continue; // مفيش انتشار في نص القيم الأوسط، مفيش حاجة لها معنى نعلّمها
            }

            $lower = $q1 - 1.5 * $iqr;
            $upper = $q3 + 1.5 * $iqr;
            $count = (int) (DB::selectOne(
                "SELECT COUNT(*) AS c FROM " . $this->quoteIdent($table) . " WHERE {$ident} < ? OR {$ident} > ?",
                [$lower, $upper]
            )->c ?? 0);

            if ($count > 0) {
                $mean = array_sum($values) / count($values);
                $out[] = ['column' => $col['name'], 'count' => $count, 'mean' => round($mean, 2), 'stddev' => round($iqr, 2)];
            }
        }

        return $out;
    }

    /** فحص صيغة على الأعمدة اللي بتتعرف كـ email/URL من اسمها. */
    private function validity(string $table, array $columns, array $visible, int $totalRows): array
    {
        if ($totalRows === 0) {
            return ['pct' => null, 'issues' => []];
        }

        $issues = [];
        $checkedCount = 0;
        $invalidTotal = 0;

        $types = [];
        foreach ($columns as $c) {
            $types[$c['name']] = (string) ($c['type'] ?? '');
        }

        foreach ($visible as $col) {
            if (!$this->isTextType($types[$col] ?? '')) {
                continue; // فحص الصيغة (LIKE / != '') للأعمدة النصية بس
            }
            $lower = strtolower($col);
            $ident = $this->quoteIdent($col);
            $isEmail = false;
            $isUrl = false;
            foreach (self::EMAIL_HINTS as $hint) {
                if (str_contains($lower, $hint)) {
                    $isEmail = true;
                    break;
                }
            }
            foreach (self::URL_HINTS as $hint) {
                if (str_contains($lower, $hint)) {
                    $isUrl = true;
                    break;
                }
            }
            if (!$isEmail && !$isUrl) {
                continue;
            }

            $checkedCount++;
            if ($isEmail) {
                $invalid = (int) (DB::selectOne(
                    "SELECT COUNT(*) AS c FROM " . $this->quoteIdent($table) . "
                      WHERE {$ident} IS NOT NULL AND {$ident} != ''
                        AND ({$ident} NOT LIKE '%_@_%.__%')"
                )->c ?? 0);
            } else {
                $invalid = (int) (DB::selectOne(
                    "SELECT COUNT(*) AS c FROM " . $this->quoteIdent($table) . "
                      WHERE {$ident} IS NOT NULL AND {$ident} != ''
                        AND ({$ident} NOT LIKE 'http://%' AND {$ident} NOT LIKE 'https://%')"
                )->c ?? 0);
            }
            if ($invalid > 0) {
                $issues[] = ['column' => $col, 'type' => $isEmail ? 'email' : 'url', 'invalid_count' => $invalid];
                $invalidTotal += $invalid;
            }
        }

        if ($checkedCount === 0) {
            return ['pct' => null, 'issues' => []]; // مفيش أعمدة صيغة معروفة — مش قابل للتطبيق
        }

        $pct = 100 - (($invalidTotal / max(1, $totalRows * $checkedCount)) * 100);
        return ['pct' => round(max(0, $pct), 1), 'issues' => $issues];
    }

    /** الحداثة من مدى created_at بتاع الـ dataset. */
    private function freshness(string $table, array $columns): array
    {
        $columnNames = array_column($columns, 'name');
        if (!in_array('created_at', $columnNames, true)) {
            return ['pct' => null, 'days_since_last' => null, 'latest_at' => null];
        }

        $latest = DB::selectOne('SELECT MAX(created_at) AS latest FROM ' . $this->quoteIdent($table))->latest ?? null;
        if (!$latest) {
            return ['pct' => null, 'days_since_last' => null, 'latest_at' => null];
        }

        $days = (int) floor((time() - strtotime($latest)) / 86400);
        $pct = match (true) {
            $days <= 7   => 100,
            $days <= 30  => 80,
            $days <= 90  => 60,
            $days <= 365 => 40,
            default      => 20,
        };

        return ['pct' => $pct, 'days_since_last' => $days, 'latest_at' => $latest];
    }
}
