<?php

namespace App\Repositories;

use App\Models\DataExplorerActivity;
use App\Models\DataExplorerFavorite;
use Illuminate\Support\Facades\DB;

/**
 * منقولة كاملة من app/Repositories/DataExplorerRepository.php القديمة —
 * بند 24 batch 4 (Data Explorer، enhancement spec section 2). كتالوج
 * الـ datasets (projects/users/analytics_records/innovation_statistics/
 * ai_analysis/security_logs) مش جدول جديد — نفس الست جداول اللي
 * report_templates.data_source (migration 042) بيرجع لها بالفعل. الريبو
 * ده بيفحص أعمدتها الحقيقية لايف عن طريق INFORMATION_SCHEMA (MySQL) أو
 * PRAGMA table_info (SQLite) عشان قايمة الأعمدة والأنواع مايبعدوش عن
 * السكيما الحقيقية، وبعدين بيبني استعلامات preview آمنة فوقها.
 *
 * ⚠️ batch 3 كان بورت جزئي متعمد (catalog()/dataset()/columns()/
 * visibleColumnNames() بس، كتبعية لـ AdvancedAnalyticsService/
 * DataQualityService). الكلاس ده دلوقتي البورت الكامل — بيضيف preview()
 * (بحث+فلاتر+ترتيب+group/aggregate اختياري+عينة عشوائية+تصفح صفحات)،
 * metadata()، والـ favorites/recently-opened (جداول data_explorer_
 * favorites/data_explorer_activity، migration 074).
 *
 * SQL-injection posture: نفس القديمة بالظبط — كل identifier (اسم
 * جدول/عمود) في أي استعلام خام بيتفحص ضد config/data_explorer_datasets.php
 * (للجدول) وقايمة الأعمدة المفحوصة لايف (للأعمدة) قبل ما يتلزق في
 * الـ SQL، وبيتحط بين backticks دايمًا. أي VALUE بتتبعت كـ bound
 * parameter دايمًا. اتجاه الترتيب وعمليات الفلترة بتتقارن بقوائم مسموحة
 * صغيرة، عمرها ماتتاخد حرفيًا من المستخدم.
 */
class DataExplorerRepository
{
    /** عمليات فلترة مسموحة -> SQL fragment (القيمة نفسها بتتبعت منفصل). */
    private const OPERATORS = [
        'eq'       => '=',
        'neq'      => '!=',
        'gt'       => '>',
        'gte'      => '>=',
        'lt'       => '<',
        'lte'      => '<=',
        'contains' => 'LIKE',
        'starts'   => 'LIKE',
        'null'     => 'IS NULL',
        'not_null' => 'IS NOT NULL',
    ];

    /** @return array<string,array> الكتالوج الكامل من الـ config، مفهرس بمفتاح الـ dataset */
    public function catalog(): array
    {
        return config('data_explorer_datasets', []);
    }

    /** @return array<int,string> أسماء الجداول (lowercase) اللي بتستخدم soft-delete وبتتفلتر تلقائيًا بـ deleted_at IS NULL */
    public function softDeleteTables(): array
    {
        $out = [];
        foreach ($this->catalog() as $entry) {
            if (!empty($entry['soft_deletes'])) {
                $out[] = strtolower($entry['table']);
            }
        }
        return $out;
    }

    /** @return array|null صف الكتالوج لمفتاح dataset معين، أو null لو مش في الـ allow-list */
    public function dataset(string $key): ?array
    {
        $entry = $this->catalog()[$key] ?? null;
        return $entry ? array_merge(['key' => $key], $entry) : null;
    }

    private function driver(): string
    {
        $default = config('database.default', 'mysql');
        return config("database.connections.{$default}.driver", $default);
    }

    /**
     * فحص أعمدة حي لجدول dataset معين.
     * @return array<int,array{name:string,type:string,nullable:bool,is_primary:bool}>
     */
    public function columns(string $table): array
    {
        if ($this->driver() === 'sqlite') {
            $rows = DB::select('PRAGMA table_info(' . $this->quoteIdent($table) . ')');
            return array_map(fn ($r) => [
                'name'       => $r->name,
                'type'       => strtoupper((string) $r->type),
                'nullable'   => !((int) $r->notnull),
                'is_primary' => (bool) $r->pk,
            ], $rows);
        }

        $rows = DB::select(
            'SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_KEY
               FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
              ORDER BY ORDINAL_POSITION',
            [$table]
        );
        return array_map(fn ($r) => [
            'name'       => $r->COLUMN_NAME,
            'type'       => strtoupper((string) $r->DATA_TYPE),
            'nullable'   => $r->IS_NULLABLE === 'YES',
            'is_primary' => $r->COLUMN_KEY === 'PRI',
        ], $rows);
    }

    /** أسماء الأعمدة بس، بعد إخفاء الأعمدة الحساسة المعرّفة لهذا الـ dataset. */
    public function visibleColumnNames(array $dataset): array
    {
        $names = array_column($this->columns($dataset['table']), 'name');
        return array_values(array_diff($names, $dataset['sensitive_columns'] ?? []));
    }

    private function quoteIdent(string $ident): string
    {
        // الكولرز allow-listed بس اللي بيمروا identifiers فعلًا عدت من
        // فحص table()/column allow-list — ده بس خط دفاع أخير بيشيل أي
        // حرف مش identifier عادي.
        $clean = preg_replace('/[^A-Za-z0-9_]/', '', $ident);
        return '`' . $clean . '`';
    }

    /**
     * معاينة dataset: بحث + فلاتر + ترتيب + group/aggregate اختياري +
     * عينة عشوائية + تصفح صفحات، كله محصور في أعمدة مسموحة من فحص حي.
     *
     * @param array $opts {
     *   @var string|null $search       بحث LIKE عبر كل الأعمدة النصية الظاهرة
     *   @var array       $filters      قايمة ['column'=>string,'op'=>string,'value'=>mixed]
     *   @var string|null $sort         اسم عمود
     *   @var string      $sort_dir     'asc'|'desc'
     *   @var string|null $group_by     اسم عمود -> بترجع عدّادات مجمّعة بدل صفوف خام
     *   @var bool        $sample       true -> ORDER BY عشوائي بدل الترتيب
     *   @var int         $page
     *   @var int         $per_page
     * }
     */
    public function preview(array $dataset, array $opts = []): array
    {
        $table = $dataset['table'];
        $columns = $this->columns($table);
        $columnNames = array_column($columns, 'name');
        $visible = array_values(array_diff($columnNames, $dataset['sensitive_columns'] ?? []));
        $textTypes = ['VARCHAR', 'TEXT', 'CHAR', 'LONGTEXT', 'MEDIUMTEXT', 'ENUM'];
        $textColumns = array_values(array_map(
            fn ($c) => $c['name'],
            array_filter($columns, fn ($c) => in_array($c['type'], $textTypes, true) && in_array($c['name'], $visible, true))
        ));

        $where = [];
        $params = [];

        // الصفوف المحذوفة (soft-delete) مش بتظهر في المعاينة ولا العدّادات.
        if (!empty($dataset['soft_deletes'])) {
            $where[] = $this->quoteIdent('deleted_at') . ' IS NULL';
        }

        $search = trim((string) ($opts['search'] ?? ''));
        if ($search !== '' && $textColumns) {
            $likeParts = [];
            foreach ($textColumns as $col) {
                $likeParts[] = $this->quoteIdent($col) . ' LIKE ?';
                $params[] = '%' . $search . '%';
            }
            $where[] = '(' . implode(' OR ', $likeParts) . ')';
        }

        foreach ((array) ($opts['filters'] ?? []) as $filter) {
            $col = $filter['column'] ?? '';
            $op = $filter['op'] ?? '';
            if (!in_array($col, $visible, true) || !isset(self::OPERATORS[$op])) {
                continue;
            }
            $sqlOp = self::OPERATORS[$op];
            if ($op === 'null' || $op === 'not_null') {
                $where[] = $this->quoteIdent($col) . ' ' . $sqlOp;
            } elseif ($op === 'contains') {
                $where[] = $this->quoteIdent($col) . " {$sqlOp} ?";
                $params[] = '%' . ($filter['value'] ?? '') . '%';
            } elseif ($op === 'starts') {
                $where[] = $this->quoteIdent($col) . " {$sqlOp} ?";
                $params[] = ($filter['value'] ?? '') . '%';
            } else {
                $where[] = $this->quoteIdent($col) . " {$sqlOp} ?";
                $params[] = $filter['value'] ?? null;
            }
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        // وضع التجميع/aggregate: GROUP BY عمود ظاهر واحد، COUNT(*) لكل مجموعة.
        $groupBy = $opts['group_by'] ?? null;
        if ($groupBy && in_array($groupBy, $visible, true)) {
            $rows = DB::select(
                'SELECT ' . $this->quoteIdent($groupBy) . ' AS group_value, COUNT(*) AS group_count
                   FROM ' . $this->quoteIdent($table) . " {$whereSql}
                  GROUP BY " . $this->quoteIdent($groupBy) . '
                  ORDER BY group_count DESC
                  LIMIT 200',
                $params
            );
            return [
                'mode'     => 'grouped',
                'group_by' => $groupBy,
                'rows'     => array_map(fn ($r) => (array) $r, $rows),
                'columns'  => ['group_value', 'group_count'],
                'total'    => count($rows),
            ];
        }

        $total = (int) (DB::selectOne(
            'SELECT COUNT(*) AS c FROM ' . $this->quoteIdent($table) . " {$whereSql}",
            $params
        )->c ?? 0);

        $perPage = max(1, min(200, (int) ($opts['per_page'] ?? 25)));
        $page = max(1, (int) ($opts['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        $selectCols = implode(', ', array_map(fn ($c) => $this->quoteIdent($c), $visible)) ?: '*';

        if (!empty($opts['sample'])) {
            $rand = $this->driver() === 'sqlite' ? 'RANDOM()' : 'RAND()';
            $orderSql = "ORDER BY {$rand}";
        } else {
            $sort = $opts['sort'] ?? $dataset['default_sort'] ?? null;
            if (!in_array($sort, $visible, true)) {
                $sort = $visible[0] ?? null;
            }
            $dir = strtolower((string) ($opts['sort_dir'] ?? $dataset['default_sort_dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
            $orderSql = $sort ? ('ORDER BY ' . $this->quoteIdent($sort) . " {$dir}") : '';
        }

        $sql = "SELECT {$selectCols} FROM " . $this->quoteIdent($table) . " {$whereSql} {$orderSql} LIMIT {$perPage} OFFSET {$offset}";
        $rows = DB::select($sql, $params);

        return [
            'mode'     => 'rows',
            'rows'     => array_map(fn ($r) => (array) $r, $rows),
            'columns'  => $visible,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /** عدد الصفوف + أول/آخر طابع زمني (لو الجدول عنده created_at) لتاب الميتاداتا. */
    public function metadata(array $dataset): array
    {
        $table = $dataset['table'];
        $columns = $this->columns($table);
        $columnNames = array_column($columns, 'name');

        $softWhere = !empty($dataset['soft_deletes']) ? ' WHERE ' . $this->quoteIdent('deleted_at') . ' IS NULL' : '';

        $rowCount = (int) (DB::selectOne('SELECT COUNT(*) AS c FROM ' . $this->quoteIdent($table) . $softWhere)->c ?? 0);

        $earliest = $latest = null;
        if (in_array('created_at', $columnNames, true)) {
            $earliest = DB::selectOne('SELECT MIN(created_at) AS v FROM ' . $this->quoteIdent($table) . $softWhere)->v ?? null;
            $latest = DB::selectOne('SELECT MAX(created_at) AS v FROM ' . $this->quoteIdent($table) . $softWhere)->v ?? null;
        }

        return [
            'row_count'       => $rowCount,
            'column_count'    => count($columnNames),
            'earliest_at'     => $earliest,
            'latest_at'       => $latest,
            'sensitive_count' => count($dataset['sensitive_columns'] ?? []),
        ];
    }

    // -- Favorites --------------------------------------------------------

    public function favoriteKeys(int $userId): array
    {
        return DataExplorerFavorite::where('user_id', $userId)->pluck('dataset_key')->all();
    }

    public function toggleFavorite(int $userId, string $datasetKey): bool
    {
        $existing = DataExplorerFavorite::where('user_id', $userId)->where('dataset_key', $datasetKey)->first();

        if ($existing) {
            $existing->delete();
            return false; // بقى مش مفضّل دلوقتي
        }

        DataExplorerFavorite::create(['user_id' => $userId, 'dataset_key' => $datasetKey]);
        return true; // بقى مفضّل دلوقتي
    }

    // -- Recently opened ----------------------------------------------------

    public function recordOpen(int $userId, string $datasetKey): void
    {
        DataExplorerActivity::create(['user_id' => $userId, 'dataset_key' => $datasetKey]);
    }

    /** @return array<int,array{dataset_key:string,opened_at:string}> آخر datasets اتفتحت، الأحدث أولًا */
    public function recentlyOpened(int $userId, int $limit = 6): array
    {
        $rows = DB::select(
            'SELECT dataset_key, MAX(opened_at) AS opened_at
               FROM data_explorer_activity
              WHERE user_id = ?
              GROUP BY dataset_key
              ORDER BY opened_at DESC
              LIMIT ' . max(1, min(50, $limit)),
            [$userId]
        );
        return array_map(fn ($r) => (array) $r, $rows);
    }
}
