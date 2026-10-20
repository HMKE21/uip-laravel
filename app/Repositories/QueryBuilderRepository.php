<?php

namespace App\Repositories;

use App\Models\QueryHistory;
use App\Models\SavedQuery;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * منقولة من app/Repositories/QueryBuilderRepository.php القديمة — بند
 * 24 batch 4 (SQL Query Builder، enhancement spec section 3). الوصول
 * لبيانات saved_queries/query_history (migration 082)، بالإضافة لمحرك
 * التنفيذ المشترك بين الـ builder المرئي (drag & drop) ومحرر SQL الخام.
 * ده مش سطح "شغّل أي SQL" عام — بيعيد استخدام نفس allow-list بتاعة Data
 * Explorer / Data Quality / Advanced Analytics (config/data_explorer_
 * datasets.php، مفحوصة لايف عبر DataExplorerRepository)، فمستخدم يقدر
 * يقرا جدول من الشاشات دي أصلًا هو بس اللي يقدر يستعلم عليه هنا، ومفيش
 * غيره.
 *
 * موقف SQL-injection/authorization — اقرأ ده قبل ما تلمس buildSql()/
 * validateRawSql():
 *  - وضع الـ BUILDER (buildSql): كل identifier (جدول، عمود، هدف join)
 *    بيتفحص ضد الكتالوج المسموح والأعمدة المفحوصة لايف، وبعدين بيتحط
 *    بين backticks. أي VALUE (عمليات WHERE/HAVING) دايمًا bound
 *    parameter، عمرها ما بتتلزق. اتجاه الترتيب، نوع الـ join،
 *    والعمليات بتتقارن بقوائم مسموحة صغيرة، عمرهم ماياخدوا حرفيًا.
 *  - وضع RAW SQL (validateRawSql): نص المستخدم هو الاستعلام نفسه (مفيش
 *    params منفصلة غير موثوقة بتتلزق فيه)، فالخطر مش injection كلاسيكي
 *    — الخطر هو وصول غير مصرّح للبيانات. بنفرض: statement واحد بالظبط
 *    (مفيش `;`-chaining)، SELECT بس (مفيش INSERT/UPDATE/DELETE/DROP/
 *    ALTER/TRUNCATE/CREATE/REPLACE/GRANT/CALL/INTO OUTFILE/LOAD_FILE/
 *    EXEC)، وكل جدول متسمّى بعد FROM/JOIN لازم يترجم لجدول dataset مسموح.
 *    الأعمدة الحساسة (config `sensitive_columns`، زي users.password_hash)
 *    بترفض من الأول — أي إشارة لاسم عمود حساس في أي حتة من نص
 *    الـ statement بترمي قبل ما الاستعلام يتنفذ خالص (نفس فحص
 *    FORBIDDEN_KEYWORDS المستوى-نصي فوق)، مش مجرد فلترة من النتيجة
 *    بعدين، فـ alias أو لف العمود (`SELECT password_hash AS x`,
 *    `SELECT UPPER(password_hash)`) مايقدرش يتخطاها. redactSensitive()
 *    على نتيجة الاستعلام باقية كطبقة دفاع تانية defense-in-depth لوضع
 *    الـ BUILDER (اللي قايمة أعمدته أصلًا اتفحصت ضد allow-list وقت
 *    البناء، فده backstop إضافي هناك مش خط الدفاع الوحيد زي ما كان
 *    لوضع raw).
 *  - كل تنفيذ بياخد حد أقصى صفوف (self::MAX_ROWS) بيتضاف لما الاستعلام
 *    مايكونش معلن LIMIT بتاعه هو (أصغر).
 */
class QueryBuilderRepository
{
    private const MAX_ROWS = 500;

    /** كلمات نوع-statement اللي بتستبعد استعلام خام فورًا. */
    private const FORBIDDEN_KEYWORDS = [
        'insert', 'update', 'delete', 'drop', 'alter', 'truncate', 'create',
        'replace', 'grant', 'revoke', 'call', 'exec', 'execute', 'merge',
        'into outfile', 'into dumpfile', 'load_file', 'load data',
        'set ', 'lock ', 'unlock ', 'attach', 'detach', 'pragma',
    ];

    private const JOIN_TYPES = ['INNER', 'LEFT', 'RIGHT'];

    private const OPERATORS = [
        'eq' => '=', 'neq' => '!=', 'gt' => '>', 'gte' => '>=',
        'lt' => '<', 'lte' => '<=', 'contains' => 'LIKE', 'starts' => 'LIKE',
        'null' => 'IS NULL', 'not_null' => 'IS NOT NULL',
    ];

    private const AGGREGATES = ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'];

    public function __construct(private DataExplorerRepository $explorer)
    {
    }

    // -- Allow-list / metadata --------------------------------------------

    /** @return array<string,array> صفوف الكتالوج مفهرسة بمفتاح الـ dataset، كل واحد معاه 'columns' حية */
    public function catalog(): array
    {
        $catalog = $this->explorer->catalog();
        $out = [];
        foreach ($catalog as $key => $entry) {
            $entry['key'] = $key;
            $entry['columns'] = $this->explorer->visibleColumnNames($entry + ['table' => $entry['table']]);
            $entry['column_types'] = $this->explorer->columns($entry['table']);
            $out[$key] = $entry;
        }
        return $out;
    }

    /** @return array<int,string> كل اسم جدول حقيقي مسموح */
    private function allowedTables(): array
    {
        return array_column($this->explorer->catalog(), 'table');
    }

    /** صف كتالوج الـ dataset لاسم جدول حقيقي، أو null لو مش مسموح. */
    private function datasetForTable(string $table): ?array
    {
        foreach ($this->explorer->catalog() as $key => $entry) {
            if (strcasecmp($entry['table'], $table) === 0) {
                return $entry + ['key' => $key];
            }
        }
        return null;
    }

    /**
     * @return array<int,string> كل sensitive_columns عبر كل dataset مسموح،
     *   مثلًا ['password_hash', 'remember_token'] — بتُستخدم في
     *   validateRawSql() عشان ترفض استعلام خام من الأول بدل ما تحاول
     *   تعمل redact لنتيجة الاستعلام بعد كده (اللي SELECT بـ alias أو لف،
     *   زي `SELECT password_hash AS x`، ممكن يتخطاها).
     */
    private function allSensitiveColumns(): array
    {
        $out = [];
        foreach ($this->explorer->catalog() as $entry) {
            foreach ($entry['sensitive_columns'] ?? [] as $col) {
                $out[] = $col;
            }
        }
        return array_values(array_unique($out));
    }

    private function quoteIdent(string $ident): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_]/', '', $ident);
        return '`' . $clean . '`';
    }

    // -- Builder mode: طلب مهيكل -> SQL آمن -------------------------------

    /**
     * بتحوّل حمولة builder اتفحصت لـ SQL معلّم بـ params. بترمي
     * RuntimeException (الرسالة آمنة تتعرض للمستخدم) على أي حاجة مش
     * allow-listed.
     *
     * @param array $spec {
     *   @var string $table        جدول الـ dataset الأساسي
     *   @var array  $columns      ['col', ...] أو ['agg'=>'COUNT','column'=>'col','alias'=>'x']
     *   @var array  $joins        [['type'=>'INNER','table'=>t,'left'=>'a.col','right'=>'b.col'], ...]
     *   @var array  $where        [['column'=>c,'op'=>o,'value'=>v], ...]
     *   @var array  $group_by     ['col', ...]
     *   @var array  $having       [['column'=>c,'agg'=>'COUNT','op'=>o,'value'=>v], ...]
     *   @var array  $order_by     [['column'=>c,'dir'=>'asc'|'desc'], ...]
     *   @var int    $limit
     * }
     * @return array{sql:string,params:array}
     */
    public function buildSql(array $spec): array
    {
        $table = (string) ($spec['table'] ?? '');
        if (!in_array($table, $this->allowedTables(), true)) {
            throw new RuntimeException('Unknown or disallowed table.');
        }
        $baseDataset = $this->datasetForTable($table);
        $baseVisible = $this->explorer->visibleColumnNames($baseDataset);

        // الجداول المتاحة في الاستعلام ده: الأساسي + أي هدف join، كل
        // واحد بأعمدته المسموحة الخاصة، مفهرسة باسم الجدول عشان
        // WHERE/SELECT/GROUP BY/ORDER BY تقدر تفحص "table.column".
        $tablesInPlay = [$table => $baseVisible];

        // جداول soft-delete: الصفوف المحذوفة بتتستبعد تلقائيًا (WHERE، أو ON لو LEFT JOIN).
        $softTables = $this->explorer->softDeleteTables();
        $softWhere = [];
        if (in_array(strtolower($table), $softTables, true)) {
            $softWhere[] = $this->quoteIdent($table) . '.' . $this->quoteIdent('deleted_at') . ' IS NULL';
        }

        $joinSql = '';
        $joinCount = 0;
        foreach ((array) ($spec['joins'] ?? []) as $join) {
            $joinTable = (string) ($join['table'] ?? '');
            $joinType = strtoupper((string) ($join['type'] ?? 'INNER'));
            if (!in_array($joinTable, $this->allowedTables(), true)) {
                throw new RuntimeException('Unknown or disallowed join table.');
            }
            if (!in_array($joinType, self::JOIN_TYPES, true)) {
                $joinType = 'INNER';
            }
            $joinDataset = $this->datasetForTable($joinTable);
            $tablesInPlay[$joinTable] = $this->explorer->visibleColumnNames($joinDataset);

            [$leftTable, $leftCol] = $this->splitQualified((string) ($join['left'] ?? ''), $table);
            [$rightTable, $rightCol] = $this->splitQualified((string) ($join['right'] ?? ''), $joinTable);
            $this->assertColumnAllowed($tablesInPlay, $leftTable, $leftCol);
            $this->assertColumnAllowed($tablesInPlay, $rightTable, $rightCol);

            $joinSql .= " {$joinType} JOIN " . $this->quoteIdent($joinTable)
                . ' ON ' . $this->quoteIdent($leftTable) . '.' . $this->quoteIdent($leftCol)
                . ' = ' . $this->quoteIdent($rightTable) . '.' . $this->quoteIdent($rightCol);
            if (in_array(strtolower($joinTable), $softTables, true)) {
                $softCond = $this->quoteIdent($joinTable) . '.' . $this->quoteIdent('deleted_at') . ' IS NULL';
                if ($joinType === 'LEFT') {
                    $joinSql .= ' AND ' . $softCond;
                } else {
                    $softWhere[] = $softCond;
                }
            }
            $joinCount++;
            if ($joinCount > 4) {
                throw new RuntimeException('Too many joins (max 4).');
            }
        }

        // قايمة SELECT
        $selectParts = [];
        $columns = (array) ($spec['columns'] ?? []);
        foreach ($columns as $col) {
            if (is_array($col)) {
                $agg = strtoupper((string) ($col['agg'] ?? ''));
                if (!in_array($agg, self::AGGREGATES, true)) {
                    continue;
                }
                $colName = (string) ($col['column'] ?? '');
                if ($colName === '*' && $agg === 'COUNT') {
                    $selectParts[] = 'COUNT(*)' . $this->aliasSql($col['alias'] ?? 'count');
                    continue;
                }
                [$t, $c] = $this->splitQualified($colName, $table);
                $this->assertColumnAllowed($tablesInPlay, $t, $c);
                $selectParts[] = "{$agg}(" . $this->quoteIdent($t) . '.' . $this->quoteIdent($c) . ')'
                    . $this->aliasSql($col['alias'] ?? ($agg . '_' . $c));
            } else {
                [$t, $c] = $this->splitQualified((string) $col, $table);
                $this->assertColumnAllowed($tablesInPlay, $t, $c);
                $selectParts[] = $this->quoteIdent($t) . '.' . $this->quoteIdent($c);
            }
        }
        if (!$selectParts) {
            foreach ($baseVisible as $c) {
                $selectParts[] = $this->quoteIdent($table) . '.' . $this->quoteIdent($c);
            }
        }

        // WHERE
        $where = $softWhere;
        $params = [];
        foreach ((array) ($spec['where'] ?? []) as $cond) {
            $colName = (string) ($cond['column'] ?? '');
            $op = (string) ($cond['op'] ?? '');
            if ($colName === '' || !isset(self::OPERATORS[$op])) {
                continue;
            }
            [$t, $c] = $this->splitQualified($colName, $table);
            $this->assertColumnAllowed($tablesInPlay, $t, $c);
            $sqlOp = self::OPERATORS[$op];
            $ident = $this->quoteIdent($t) . '.' . $this->quoteIdent($c);
            if ($op === 'null' || $op === 'not_null') {
                $where[] = "{$ident} {$sqlOp}";
            } elseif ($op === 'contains') {
                $where[] = "{$ident} LIKE ?";
                $params[] = '%' . ($cond['value'] ?? '') . '%';
            } elseif ($op === 'starts') {
                $where[] = "{$ident} LIKE ?";
                $params[] = ($cond['value'] ?? '') . '%';
            } else {
                $where[] = "{$ident} {$sqlOp} ?";
                $params[] = $cond['value'] ?? null;
            }
        }

        // GROUP BY
        $groupSql = '';
        $groupCols = [];
        foreach ((array) ($spec['group_by'] ?? []) as $col) {
            [$t, $c] = $this->splitQualified((string) $col, $table);
            $this->assertColumnAllowed($tablesInPlay, $t, $c);
            $groupCols[] = $this->quoteIdent($t) . '.' . $this->quoteIdent($c);
        }
        if ($groupCols) {
            $groupSql = ' GROUP BY ' . implode(', ', $groupCols);
        }

        // HAVING (مقارنات aggregate بس)
        $having = [];
        foreach ((array) ($spec['having'] ?? []) as $cond) {
            $agg = strtoupper((string) ($cond['agg'] ?? ''));
            $op = (string) ($cond['op'] ?? '');
            if (!in_array($agg, self::AGGREGATES, true) || !isset(self::OPERATORS[$op]) || $op === 'null' || $op === 'not_null') {
                continue;
            }
            $colName = (string) ($cond['column'] ?? '');
            if ($colName === '*' && $agg === 'COUNT') {
                $having[] = 'COUNT(*) ' . self::OPERATORS[$op] . ' ?';
            } else {
                [$t, $c] = $this->splitQualified($colName, $table);
                $this->assertColumnAllowed($tablesInPlay, $t, $c);
                $having[] = "{$agg}(" . $this->quoteIdent($t) . '.' . $this->quoteIdent($c) . ') ' . self::OPERATORS[$op] . ' ?';
            }
            $params[] = $cond['value'] ?? null;
        }
        $havingSql = $having ? (' HAVING ' . implode(' AND ', $having)) : '';

        // ORDER BY
        $orderParts = [];
        foreach ((array) ($spec['order_by'] ?? []) as $ord) {
            $colName = (string) ($ord['column'] ?? '');
            if ($colName === '') {
                continue;
            }
            [$t, $c] = $this->splitQualified($colName, $table);
            $this->assertColumnAllowed($tablesInPlay, $t, $c);
            $dir = strtolower((string) ($ord['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
            $orderParts[] = $this->quoteIdent($t) . '.' . $this->quoteIdent($c) . " {$dir}";
        }
        $orderSql = $orderParts ? (' ORDER BY ' . implode(', ', $orderParts)) : '';

        $limit = max(1, min(self::MAX_ROWS, (int) ($spec['limit'] ?? 100)));

        $sql = 'SELECT ' . implode(', ', $selectParts)
            . ' FROM ' . $this->quoteIdent($table)
            . $joinSql
            . ($where ? (' WHERE ' . implode(' AND ', $where)) : '')
            . $groupSql . $havingSql . $orderSql
            . ' LIMIT ' . $limit;

        return ['sql' => $sql, 'params' => $params];
    }

    private function aliasSql($alias): string
    {
        $alias = trim((string) $alias);
        if ($alias === '') {
            return '';
        }
        return ' AS ' . $this->quoteIdent($alias);
    }

    /** @return array{0:string,1:string} [table, column] */
    private function splitQualified(string $ref, string $defaultTable): array
    {
        if (str_contains($ref, '.')) {
            [$t, $c] = explode('.', $ref, 2);
            return [$t, $c];
        }
        return [$defaultTable, $ref];
    }

    private function assertColumnAllowed(array $tablesInPlay, string $table, string $column): void
    {
        if (!isset($tablesInPlay[$table]) || !in_array($column, $tablesInPlay[$table], true)) {
            throw new RuntimeException("Column '{$table}.{$column}' is not available.");
        }
    }

    // -- Raw SQL mode: فحص نص مكتوب يدويًا ---------------------------------

    /**
     * بتفحص statement خام من نوع SELECT ضد الـ allow-list. بترجع الـ SQL
     * (ممكن يكون متحدد بـ LIMIT) اللي هيتنفذ. بترمي RuntimeException
     * برسالة آمنة للمستخدم على أي مخالفة.
     */
    public function validateRawSql(string $sql): string
    {
        $trimmed = trim($sql);
        if ($trimmed === '') {
            throw new RuntimeException('Query is empty.');
        }

        // statement واحد بس — بتشيل فاصلة منقوطة زايدة في الآخر، وبترفض
        // لو فيه واحدة تانية باقية في أي حتة من الجسم.
        $trimmed = rtrim($trimmed);
        if (str_ends_with($trimmed, ';')) {
            $trimmed = rtrim(substr($trimmed, 0, -1));
        }
        if (str_contains($trimmed, ';')) {
            throw new RuntimeException('Only a single SELECT statement is allowed (no `;`-chained statements).');
        }

        $lower = strtolower($trimmed);
        if (!preg_match('/^\s*(select|with)\b/', $lower)) {
            throw new RuntimeException('Only SELECT queries are allowed.');
        }
        foreach (self::FORBIDDEN_KEYWORDS as $kw) {
            if (preg_match('/(^|[\s(])' . preg_quote($kw, '/') . '([\s(]|$)/', $lower)) {
                throw new RuntimeException("Statement contains a disallowed keyword: \"{$kw}\".");
            }
        }
        if (str_contains($lower, '--') || str_contains($lower, '/*')) {
            throw new RuntimeException('Comments are not allowed inside the query.');
        }

        // كل جدول متسمّى بعد FROM/JOIN لازم يكون allow-listed.
        $allowed = array_map('strtolower', $this->allowedTables());
        if (preg_match_all('/\b(?:from|join)\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?/i', $trimmed, $m)) {
            foreach (array_unique($m[1]) as $referenced) {
                if (!in_array(strtolower($referenced), $allowed, true)) {
                    throw new RuntimeException("Table \"{$referenced}\" is not available in the Query Builder's allow-listed datasets.");
                }
            }
        } else {
            throw new RuntimeException('Could not find a FROM clause referencing an allowed table.');
        }

        // بترفض أي إشارة لعمود حساس فورًا، قبل ما الاستعلام يتنفذ خالص —
        // فحص متعمد على مستوى النص كله (مش SELECT list بس)، نفس فحص
        // FORBIDDEN_KEYWORDS فوق، عشان alias أو wrapper تعبير
        // (`SELECT password_hash AS x`, `SELECT UPPER(password_hash)`)
        // مايقدرش يتخطاه. ده بديل لمحاولة redact نتيجة الاستعلام بعد
        // التنفيذ، اللي ممكن تتخطى بنفس الطريقة دي بالظبط.
        foreach ($this->allSensitiveColumns() as $sensitiveCol) {
            if (preg_match('/(^|[^a-zA-Z0-9_`])' . preg_quote(strtolower($sensitiveCol), '/') . '([^a-zA-Z0-9_`]|$)/', $lower)) {
                throw new RuntimeException("Column \"{$sensitiveCol}\" is not available through the Query Builder.");
            }
        }

        // joins بالفاصلة (FROM a, b) بتتخطى فحص الـ allow-list فوق (اللي بيشوف بس الاسم بعد FROM/JOIN) — مرفوضة.
        if (preg_match('/\b(?:from|join)\s+`?[a-zA-Z_][a-zA-Z0-9_]*`?(?:\s+(?:as\s+)?`?[a-zA-Z_][a-zA-Z0-9_]*`?)?\s*,/i', $trimmed)) {
            throw new RuntimeException('Comma-separated joins are not allowed; use explicit JOIN ... ON.');
        }

        $trimmed = $this->excludeSoftDeleted($trimmed);

        if (!preg_match('/\blimit\s+\d+/i', $trimmed)) {
            $trimmed .= ' LIMIT ' . self::MAX_ROWS;
        }

        return $trimmed;
    }

    /**
     * بتلف كل جدول soft-delete متسمّى بعد FROM/JOIN في subquery بيستبعد
     * deleted_at، بنفس الـ alias (أو اسم الجدول لو مفيش alias)، فالاستعلام
     * الخام عمره ما بيشوف صفوف محذوفة من غير ما المحلل يفتكر يفلتر.
     */
    private function excludeSoftDeleted(string $sql): string
    {
        $soft = $this->explorer->softDeleteTables();
        if (!$soft) {
            return $sql;
        }
        $reserved = ['where', 'on', 'inner', 'left', 'right', 'full', 'cross', 'natural', 'join', 'group',
            'order', 'limit', 'having', 'union', 'using', 'offset', 'window', 'intersect', 'except', 'as'];
        $pattern = '/\b(from|join)(\s+)`?(' . implode('|', array_map(fn ($t) => preg_quote($t, '/'), $soft))
            . ')`?(?![a-zA-Z0-9_`])(\s+(?:as\s+)?`?([a-zA-Z_][a-zA-Z0-9_]*)`?)?/i';

        return preg_replace_callback($pattern, function ($m) use ($reserved) {
            $table = strtolower($m[3]);
            $alias = $m[5] ?? '';
            $hasAlias = $alias !== '' && !in_array(strtolower($alias), $reserved, true);
            $name = $hasAlias ? $alias : $table;
            $tail = $hasAlias ? '' : ($m[4] ?? '');
            return $m[1] . $m[2] . '(SELECT * FROM `' . $table . '` WHERE `deleted_at` IS NULL) AS `' . $name . '`' . $tail;
        }, $sql);
    }

    /** بتشيل sensitive_columns بتاعة أي dataset مسموح من نتيجة الاستعلام، باسم المفتاح، أيًا كانت قايمة SELECT المستخدمة. */
    private function redactSensitive(array $rows): array
    {
        if (!$rows) {
            return $rows;
        }
        $sensitive = [];
        foreach ($this->explorer->catalog() as $entry) {
            foreach ($entry['sensitive_columns'] ?? [] as $col) {
                $sensitive[$col] = true;
            }
        }
        if (!$sensitive) {
            return $rows;
        }
        foreach ($rows as &$row) {
            foreach (array_keys($row) as $key) {
                if (isset($sensitive[$key])) {
                    unset($row[$key]);
                }
            }
        }
        return $rows;
    }

    /**
     * بتنفذ SELECT اتفحص بالفعل (من buildSql() أو validateRawSql())
     * وبترجع صفوف + توقيت. أي exception من قاعدة البيانات بتتلقط
     * وترمى تاني كـ RuntimeException برسالة آمنة — أخطاء الـ driver الخام
     * ممكن تسرب تفاصيل السكيما.
     */
    public function execute(string $sql, array $params = []): array
    {
        $start = microtime(true);
        try {
            $rows = DB::select($sql, $params);
        } catch (\Throwable $e) {
            throw new RuntimeException('Query failed: ' . $this->safeDbError($e->getMessage()));
        }
        $ms = (int) round((microtime(true) - $start) * 1000);
        $rows = $this->redactSensitive(array_map(fn ($r) => (array) $r, $rows));
        return ['rows' => $rows, 'columns' => $rows ? array_keys($rows[0]) : [], 'row_count' => count($rows), 'execution_time_ms' => $ms];
    }

    /**
     * بتقص خطأ PDO/Laravel الخام لحد الجزء المفيد بس. Laravel بيزوّد في آخر
     * الرسالة "(Connection: ..., Host: ..., Port: ..., Database: ..., SQL: ...)"
     * وده بيسرّب الهوست والداتابيز والاستعلام، فبيتشال دايمًا.
     */
    public function sanitizeDbError(string $message): string
    {
        if (!preg_match('/SQLSTATE\[([^\]]+)\]:?\s*(.*)$/s', $message, $m)) {
            return 'invalid query.';
        }
        if (preg_match('/\[(2002|2006|2013|1045|1044)\]|getaddrinfo|Connection refused|Access denied/i', $message)) {
            return 'database is temporarily unavailable.';
        }
        $msg = trim((string) preg_replace('/\s*\(Conn.*$/s', '', $m[2]));
        return $msg !== '' ? $msg : 'invalid query.';
    }

    private function safeDbError(string $message): string
    {
        return $this->sanitizeDbError($message);
    }

    // -- Saved queries CRUD --------------------------------------------

    public function createSavedQuery(int $userId, string $name, string $sqlText, ?string $description, ?array $builderState, ?string $datasetKey, bool $isShared): SavedQuery
    {
        return SavedQuery::create([
            'user_id'       => $userId,
            'name'          => $name,
            'description'   => $description,
            'sql_text'      => $sqlText,
            'builder_state' => $builderState !== null ? json_encode($builderState, JSON_UNESCAPED_UNICODE) : null,
            'dataset_key'   => $datasetKey,
            'is_template'   => 0,
            'is_shared'     => $isShared ? 1 : 0,
        ]);
    }

    /** @return array<int,array<string,mixed>> استعلامات المستخدم الخاصة + استعلامات الآخرين المشتركة، builder_state متفكوك */
    public function savedQueriesFor(int $userId): array
    {
        $rows = DB::select(
            'SELECT sq.*, u.full_name AS owner_name
               FROM saved_queries sq
               JOIN users u ON u.id = sq.user_id
              WHERE sq.user_id = ? OR sq.is_shared = 1
              ORDER BY sq.updated_at DESC',
            [$userId]
        );
        return array_map(function ($r) {
            $r = (array) $r;
            $r['builder_state'] = $r['builder_state'] ? json_decode($r['builder_state'], true) : null;
            $r['is_own'] = null; // بتتملى من الكولر بمعرف المستخدم الحالي لو محتاج
            return $r;
        }, $rows);
    }

    public function findSavedQuery($id): ?SavedQuery
    {
        return SavedQuery::find($id);
    }

    public function deleteSavedQuery(SavedQuery $query): bool
    {
        return (bool) $query->delete();
    }

    // -- Query history ----------------------------------------------------

    public function logHistory(int $userId, string $sqlText, string $status, ?string $error, ?int $rowCount, ?int $executionMs, $savedQueryId = null): QueryHistory
    {
        return QueryHistory::create([
            'user_id'           => $userId,
            'saved_query_id'    => $savedQueryId,
            'sql_text'          => $sqlText,
            'status'            => $status,
            'error_message'     => $error !== null ? mb_substr($error, 0, 500) : null,
            'row_count'         => $rowCount,
            'execution_time_ms' => $executionMs,
        ]);
    }

    /** @return array<int,array<string,mixed>> آخر التشغيلات لهذا المستخدم */
    public function historyFor(int $userId, int $limit = 30): array
    {
        $rows = DB::select(
            'SELECT * FROM query_history WHERE user_id = ? ORDER BY executed_at DESC LIMIT ' . max(1, min(200, $limit)),
            [$userId]
        );
        // صفوف قديمة اتسجلت قبل التنضيف ممكن تحتوي Host/Database، فبتتنضّف وقت العرض.
        return array_map(function ($r) {
            $r = (array) $r;
            if (!empty($r['error_message'])) {
                $em = (string) $r['error_message'];
                $r['error_message'] = str_contains($em, 'SQLSTATE')
                    ? 'Query failed: ' . $this->sanitizeDbError($em)
                    : trim((string) preg_replace('/\s*\(Conn.*$/s', '', $em));
            }
            return $r;
        }, $rows);
    }
}
