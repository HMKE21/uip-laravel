<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/AdvancedAnalyticsRepository.php القديمة —
 * بند 24 batch 3 (Advanced Analytics، enhancement spec section 11). كل
 * استعلام هنا شغال بس على نفس الست catalog datasets اللي
 * DataExplorerRepository بيعرضها (config data_explorer_datasets.php) —
 * مفيش سطح "شغّل أي SQL" جديد. أسماء الأعمدة المستخدمة في
 * SELECT/GROUP BY/WHERE دايمًا بتتفحص ضد فحص INFORMATION_SCHEMA/PRAGMA
 * الحي قبل ما تتلزق، نفس اتفاقية DataExplorerRepository بالظبط.
 * Geographic distribution وcohort retention استعلامات مخصصة (مش أدوات
 * عامة لكل dataset) فوق universities/projects وusers/projects على
 * الترتيب، بنفس منطق ForecastRepository.
 */
class AdvancedAnalyticsRepository
{
    private const NUMERIC_TYPES = ['INT', 'INTEGER', 'BIGINT', 'SMALLINT', 'TINYINT', 'MEDIUMINT', 'DECIMAL', 'FLOAT', 'DOUBLE', 'REAL', 'NUMERIC'];

    private const DATE_TYPES = ['DATE', 'DATETIME', 'TIMESTAMP'];

    private function driver(): string
    {
        $default = config('database.default', 'mysql');
        return config("database.connections.{$default}.driver", $default);
    }

    private function quoteIdent(string $ident): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_]/', '', $ident);
        return '`' . $clean . '`';
    }

    /** @return array<int,array{name:string,type:string}> فحص أعمدة حي، نفس مصدر Data Explorer */
    public function columns(string $table): array
    {
        if ($this->driver() === 'sqlite') {
            $rows = DB::select('PRAGMA table_info(' . $this->quoteIdent($table) . ')');
            return array_map(fn ($r) => ['name' => $r->name, 'type' => strtoupper((string) $r->type)], $rows);
        }

        $rows = DB::select(
            'SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$table]
        );
        return array_map(fn ($r) => ['name' => $r->COLUMN_NAME, 'type' => strtoupper((string) $r->DATA_TYPE)], $rows);
    }

    public function numericColumns(string $table, array $sensitive = []): array
    {
        return array_values(array_map(fn ($c) => $c['name'], array_filter(
            $this->columns($table),
            fn ($c) => in_array($c['type'], self::NUMERIC_TYPES, true) && !in_array($c['name'], $sensitive, true)
        )));
    }

    public function dateColumns(string $table, array $sensitive = []): array
    {
        return array_values(array_map(fn ($c) => $c['name'], array_filter(
            $this->columns($table),
            fn ($c) => in_array($c['type'], self::DATE_TYPES, true) && !in_array($c['name'], $sensitive, true)
        )));
    }

    public function categoricalColumns(string $table, array $sensitive = []): array
    {
        $textTypes = ['VARCHAR', 'CHAR', 'ENUM', 'TEXT'];
        return array_values(array_map(fn ($c) => $c['name'], array_filter(
            $this->columns($table),
            fn ($c) => in_array($c['type'], $textTypes, true) && !in_array($c['name'], $sensitive, true)
        )));
    }

    /**
     * إحصائيات وصفية لعمود رقمي واحد: count/min/max/mean/median وanحراف
     * معياري للعينة. متحسوبة في PHP من القيم الحقيقية (بحد أقصى 5000
     * صف، الأحدث أولًا) بدل SQL STDDEV، عشان الدالة دي مش متاحة في
     * SQLite وده بيخلي رقم الـ driver-ين متحسوب بنفس الطريقة.
     */
    public function statistics(string $table, string $column): array
    {
        $col = $this->quoteIdent($column);
        $rows = DB::select(
            "SELECT {$col} AS v FROM " . $this->quoteIdent($table) . " WHERE {$col} IS NOT NULL ORDER BY {$col} DESC LIMIT 5000"
        );
        $values = array_map(fn ($r) => (float) $r->v, $rows);

        $n = count($values);
        if ($n === 0) {
            return ['count' => 0, 'min' => null, 'max' => null, 'mean' => null, 'median' => null, 'stddev' => null];
        }

        sort($values);
        $mean = array_sum($values) / $n;
        $variance = $n > 1 ? array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / ($n - 1) : 0.0;
        $mid = intdiv($n, 2);
        $median = $n % 2 === 0 ? ($values[$mid - 1] + $values[$mid]) / 2 : $values[$mid];

        return [
            'count'  => $n,
            'min'    => round($values[0], 2),
            'max'    => round($values[$n - 1], 2),
            'mean'   => round($mean, 2),
            'median' => round($median, 2),
            'stddev' => round(sqrt($variance), 2),
        ];
    }

    /**
     * معامل ارتباط بيرسون بين كل زوج من الأعمدة الرقمية المعطاة، محسوب
     * في PHP فوق نفس العينة الحقيقية المحدودة (متزاوجة صف-بصف، فأي
     * null في أي عمود بيسقط الصف من الاتنين).
     * @return array<string,array<string,float|null>> matrix[colA][colB] => r
     */
    public function correlationMatrix(string $table, array $numericColumns): array
    {
        $numericColumns = array_slice(array_values($numericColumns), 0, 8);
        if (count($numericColumns) < 2) {
            return [];
        }

        $selectCols = implode(', ', array_map(fn ($c) => $this->quoteIdent($c), $numericColumns));
        $rows = DB::select(
            "SELECT {$selectCols} FROM " . $this->quoteIdent($table) . ' ORDER BY 1 DESC LIMIT 3000'
        );
        $rows = array_map(fn ($r) => (array) $r, $rows);

        $matrix = [];
        foreach ($numericColumns as $a) {
            foreach ($numericColumns as $b) {
                $matrix[$a][$b] = $a === $b ? 1.0 : $this->pearson($rows, $a, $b);
            }
        }
        return $matrix;
    }

    private function pearson(array $rows, string $a, string $b): ?float
    {
        $xs = [];
        $ys = [];
        foreach ($rows as $row) {
            if ($row[$a] === null || $row[$b] === null) {
                continue;
            }
            $xs[] = (float) $row[$a];
            $ys[] = (float) $row[$b];
        }
        $n = count($xs);
        if ($n < 3) {
            return null;
        }

        $xMean = array_sum($xs) / $n;
        $yMean = array_sum($ys) / $n;
        $num = 0.0;
        $denX = 0.0;
        $denY = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dx = $xs[$i] - $xMean;
            $dy = $ys[$i] - $yMean;
            $num += $dx * $dy;
            $denX += $dx ** 2;
            $denY += $dy ** 2;
        }
        $den = sqrt($denX * $denY);
        return $den > 0 ? round($num / $den, 3) : null;
    }

    /**
     * Pivot ثنائي الأبعاد: GROUP BY rowDim, colDim، بتجميع إما COUNT(*)
     * أو SUM/AVG لعمود رقمي، معاد تشكيله في شبكة داخل PHP. محدود بـ
     * 20x20 قيمة مميزة عشان الشبكة تفضل قابلة للاستخدام.
     */
    public function pivot(string $table, string $rowDim, string $colDim, ?string $valueCol, string $aggFn): array
    {
        $aggFn = in_array($aggFn, ['count', 'sum', 'avg'], true) ? $aggFn : 'count';
        $rowQ = $this->quoteIdent($rowDim);
        $colQ = $this->quoteIdent($colDim);

        if ($aggFn === 'count' || !$valueCol) {
            $aggSql = 'COUNT(*)';
        } else {
            $aggSql = strtoupper($aggFn) . '(' . $this->quoteIdent($valueCol) . ')';
        }

        $rows = DB::select(
            "SELECT {$rowQ} AS row_value, {$colQ} AS col_value, {$aggSql} AS agg_value
               FROM " . $this->quoteIdent($table) . "
              WHERE {$rowQ} IS NOT NULL AND {$colQ} IS NOT NULL
              GROUP BY {$rowQ}, {$colQ}
              ORDER BY agg_value DESC
              LIMIT 400"
        );

        $rowKeys = [];
        $colKeys = [];
        $cells = [];
        foreach ($rows as $r) {
            $rk = (string) $r->row_value;
            $ck = (string) $r->col_value;
            $rowKeys[$rk] = true;
            $colKeys[$ck] = true;
            $cells[$rk][$ck] = round((float) $r->agg_value, 2);
        }

        $rowKeys = array_slice(array_keys($rowKeys), 0, 20);
        $colKeys = array_slice(array_keys($colKeys), 0, 20);

        return ['row_keys' => $rowKeys, 'col_keys' => $colKeys, 'cells' => $cells];
    }

    /** عدد صفوف شهري للـ $months الأخيرة، zero-filled — نفس اتفاقية ForecastRepository::zeroFill(). */
    public function timeSeries(string $table, string $dateColumn, int $months = 12): array
    {
        $driver = $this->driver();
        $col = $this->quoteIdent($dateColumn);
        $monthExpr = $driver === 'sqlite' ? "strftime('%Y-%m', {$col})" : "DATE_FORMAT({$col}, '%Y-%m')";

        $rows = DB::select(
            "SELECT {$monthExpr} AS month, COUNT(*) AS total
               FROM " . $this->quoteIdent($table) . "
              WHERE {$col} >= DATE(?)
              GROUP BY month ORDER BY month ASC",
            [date('Y-m-01', strtotime('-' . ($months - 1) . ' months'))]
        );

        $byMonth = array_column(array_map(fn ($r) => (array) $r, $rows), 'total', 'month');
        $series = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $key = date('Y-m', strtotime("-{$i} months"));
            $series[] = ['month' => $key, 'value' => (float) ($byMonth[$key] ?? 0)];
        }
        return $series;
    }

    /** عدد صفوف الـ $days الأخيرة مقابل الـ $days اللي قبلها، للمقارنة فترة-مقابل-فترة. */
    public function comparative(string $table, string $dateColumn, int $days = 30): array
    {
        $col = $this->quoteIdent($dateColumn);

        $recent = (int) (DB::selectOne(
            "SELECT COUNT(*) AS c FROM " . $this->quoteIdent($table) . " WHERE {$col} >= DATE(?)",
            [date('Y-m-d', strtotime("-{$days} days"))]
        )->c ?? 0);

        $prior = (int) (DB::selectOne(
            "SELECT COUNT(*) AS c FROM " . $this->quoteIdent($table) . " WHERE {$col} >= DATE(?) AND {$col} < DATE(?)",
            [date('Y-m-d', strtotime('-' . ($days * 2) . ' days')), date('Y-m-d', strtotime("-{$days} days"))]
        )->c ?? 0);

        $changePct = $prior > 0 ? round((($recent - $prior) / $prior) * 100, 1) : null;

        return ['recent_period' => $recent, 'prior_period' => $prior, 'change_pct' => $changePct, 'days' => $days];
    }

    /**
     * Cohort retention لـ dataset الـ 'users' تحديدًا (مش عام): لكل
     * cohort تسجيل شهري، نسبة مستخدمي الـ cohort ده اللي عندهم مشروع
     * واحد على الأقل اتعمل في الشهور 0-5 بعد التسجيل. استعلام حقيقي
     * مخصص — نفس منطق ForecastRepository.
     */
    public function userCohortRetention(int $cohorts = 6, int $offsetMonths = 6): array
    {
        $out = [];

        for ($c = $cohorts - 1; $c >= 0; $c--) {
            $cohortMonth = date('Y-m', strtotime("-{$c} months"));
            $cohortStart = $cohortMonth . '-01';
            $cohortEnd = date('Y-m-d', strtotime($cohortStart . ' +1 month'));

            $cohortSize = (int) (DB::selectOne(
                'SELECT COUNT(*) AS c FROM users WHERE created_at >= ? AND created_at < ? AND deleted_at IS NULL',
                [$cohortStart, $cohortEnd]
            )->c ?? 0);

            $offsets = [];
            for ($o = 0; $o <= min($offsetMonths - 1, $c); $o++) {
                $winStart = date('Y-m-d', strtotime($cohortStart . " +{$o} months"));
                $winEnd = date('Y-m-d', strtotime($cohortStart . ' +' . ($o + 1) . ' months'));

                $active = $cohortSize > 0 ? (int) (DB::selectOne(
                    'SELECT COUNT(DISTINCT p.owner_id) AS c FROM projects p
                       JOIN users u ON u.id = p.owner_id
                      WHERE u.created_at >= ? AND u.created_at < ?
                        AND p.created_at >= ? AND p.created_at < ?
                        AND p.deleted_at IS NULL',
                    [$cohortStart, $cohortEnd, $winStart, $winEnd]
                )->c ?? 0) : 0;

                $offsets[] = [
                    'offset_month' => $o,
                    'active'       => $active,
                    'pct'          => $cohortSize > 0 ? round(($active / $cohortSize) * 100, 1) : null,
                ];
            }

            $out[] = ['cohort_month' => $cohortMonth, 'cohort_size' => $cohortSize, 'offsets' => $offsets];
        }

        return $out;
    }

    /**
     * تجميع جغرافي مخصص: جامعات موثّقة لكل دولة، مع عدد المشاريع
     * المملوكة لطلاب/باحثين في تلك الجامعات. بيانات حقيقية فوق
     * universities.country/city (migration 004) وprojects.university_id
     * — مش جزء من كتالوج Data Explorer العام (لأن 'universities' مش من
     * الست datasets)، بس استعلام حقيقي مخصص زي forecast/cohort فوق.
     */
    public function geographicDistribution(): array
    {
        $rows = DB::select(
            "SELECT u.country AS country,
                    COUNT(DISTINCT u.id) AS university_count,
                    COUNT(DISTINCT p.id) AS project_count
               FROM universities u
               LEFT JOIN projects p ON p.university_id = u.id AND p.deleted_at IS NULL
              WHERE u.country IS NOT NULL AND u.country <> ''
              GROUP BY u.country
              ORDER BY project_count DESC, university_count DESC
              LIMIT 50"
        );

        return array_map(fn ($r) => [
            'country'          => $r->country,
            'university_count' => (int) $r->university_count,
            'project_count'    => (int) $r->project_count,
        ], $rows);
    }
}
