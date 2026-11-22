<?php

namespace App\Services\Tenancy;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * مدير بيانات المساحة (Database Manager) لمالك المساحة المعزولة: متصفح جداول (Grid) + تعديل صف بصف + SQL للقراءة بس + هيكل وعلاقات + سجل تدقيق.
 *
 * طبقات الأمان:
 *  1) الصلاحية: ability "data" = owner بس (الأدوار المخصصة مبتاخدهاش أبدًا).
 *  2) القاعدة بتتحدد من السيرفر (db_name من صف المساحة + بادئة uipt_) — مفيش أي اسم قاعدة بييجي من الطلب. المساحات المشتركة (shared) مش مدعومة.
 *  3) اتصال مستقل باسم tenant_data (مبيلمسش الـ default connection)، وبيتقفل بعد كل طلب. ممكن مستخدم MySQL مخصص (tenancy.data.username).
 *  4) الجداول المخفية (باسوردات/توكنات/جلسات/كاش/جوبز) مش بتظهر ومش بتتقري ولا بتتذكر. الأعمدة الحساسة مبتتقريش ومبتتعدّلش.
 *  5) الكتابة صف واحد بالمفتاح الأساسي، في transaction، ومحظورة على جداول السجلات والداخليات (readonly_tables).
 *  6) الـ SQL editor: TenantDataGuard + جلسة read-only على الـ DB + timeout + سقف صفوف. اختيار fail-closed.
 *  7) كل كتابة وكل استعلام SQL بيتسجّل في سجل نشاط المساحة (data.insert / data.update / data.delete / data.sql) — أسماء الأعمدة بس، مش القيم.
 */
final class TenantDataService
{
    private const CONN = 'tenant_data';
    private const OPS = ['=' => '=', '!=' => '<>', '>' => '>', '<' => '<', '>=' => '>=', '<=' => '<='];

    public function __construct(private TenantService $tenants)
    {
    }

    private function fail(string $msg, int $code = 422): never
    {
        throw new \DomainException($msg, $code);
    }

    private function cfg(string $key, mixed $default = null): mixed
    {
        return config('tenancy.data.' . $key, $default);
    }

    /** @return string[] */
    private function hiddenPatterns(): array
    {
        return array_merge((array) $this->cfg('hidden_tables', []), ['sqlite_*']);
    }

    /** @return string[] */
    private function sensitivePatterns(): array
    {
        return (array) $this->cfg('sensitive_columns', []);
    }

    /* ------------------------------------------------------------------ connection */

    private function connect(object $t, bool $stream = false): ConnectionInterface
    {
        $db = (string) ($t->db_name ?? '');
        $prefix = (string) config('tenancy.isolation.db_prefix', 'uipt_');
        if (($t->mode ?? '') !== 'isolated' || ($t->db_status ?? '') !== 'ready' || $db === '' || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $db)
            || ($prefix !== '' && !str_starts_with($db, $prefix))) {
            $this->fail('This workspace does not have its own database.', 409);
        }
        $cfg = TenantContext::connectionConfig($db);
        if ($u = (string) $this->cfg('username', '')) {
            $cfg['username'] = $u;
            $cfg['password'] = (string) $this->cfg('password', '');
        }
        if ($stream && in_array($cfg['driver'] ?? '', ['mysql', 'mariadb'], true) && defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
            $cfg['options'] = ($cfg['options'] ?? []) + [\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false];
        }
        config(['database.connections.' . self::CONN => $cfg]);
        DB::purge(self::CONN);
        return DB::connection(self::CONN);
    }

    /** يشغّل $fn على قاعدة المساحة بعد التحقق من الصلاحية، ويقفل الاتصال بعدها. */
    private function run(int $userId, int $tenantId, callable $fn, bool $stream = false): mixed
    {
        if (!$this->cfg('enabled', true)) {
            $this->fail('The database manager is turned off on this platform.', 403);
        }
        [$t] = $this->tenants->authorize($userId, $tenantId, 'data');
        $c = $this->connect($t, $stream);
        try {
            return $fn($t, $c);
        } catch (QueryException $e) {
            $this->fail($this->dbMessage($e), 422);
        } finally {
            DB::purge(self::CONN);
        }
    }

    private function dbMessage(\Throwable $e): string
    {
        $m = $e->getPrevious() ? $e->getPrevious()->getMessage() : $e->getMessage();
        $m = (string) preg_replace('/\s*\(Connection:.*$/s', '', $m);
        return mb_substr(trim($m) !== '' ? $m : 'The database rejected this request.', 0, 300);
    }

    private function audit(object $t, int $userId, string $action, string $note): void
    {
        $this->tenants->audit($userId, (int) $t->id, $action, mb_substr($note, 0, 200));
    }

    /* ------------------------------------------------------------------ schema helpers */

    /** @return array<int,array{name:string,size:?int}> كل جداول القاعدة (حتى المخفية) */
    private function rawTables(): array
    {
        $out = [];
        foreach (Schema::connection(self::CONN)->getTables() as $tb) {
            $tb = (array) $tb;
            $out[] = ['name' => (string) $tb['name'], 'size' => isset($tb['size']) ? (int) $tb['size'] : null];
        }
        return $out;
    }

    /** @return array<int,array{name:string,size:?int}> */
    private function visibleTables(): array
    {
        $h = $this->hiddenPatterns();
        $list = array_values(array_filter($this->rawTables(), static fn ($x) => !TenantDataGuard::hiddenTable($x['name'], $h)));
        usort($list, static fn ($a, $b) => strcmp($a['name'], $b['name']));
        return $list;
    }

    private function kind(string $type): string
    {
        $t = strtolower($type);
        return match (true) {
            (bool) preg_match('/\bbool(ean)?\b|^tinyint\(1\)/', $t) => 'bool',
            (bool) preg_match('/blob|binary|bytea/', $t) => 'binary',
            (bool) preg_match('/\b(tinyint|smallint|mediumint|bigint|int|integer|serial|bigserial)\b/', $t) => 'int',
            (bool) preg_match('/decimal|numeric|float|double|real/', $t) => 'decimal',
            (bool) preg_match('/datetime|timestamp/', $t) => 'datetime',
            (bool) preg_match('/^date\b/', $t) => 'date',
            (bool) preg_match('/^time\b/', $t) => 'time',
            (bool) preg_match('/json/', $t) => 'json',
            (bool) preg_match('/text|clob/', $t) => 'text',
            default => 'string',
        };
    }

    /**
     * سياق جدول: التحقق إنه ظاهر وموجود + أعمدته + مفتاحه الأساسي.
     * @return array{name:string,cols:array<string,array<string,mixed>>,pk:string[],readonly:bool,reason:?string}
     */
    private function table(string $name, bool $forWrite = false): array
    {
        $found = null;
        foreach ($this->visibleTables() as $x) {
            if ($x['name'] === $name) {
                $found = $x['name'];
                break;
            }
        }
        if ($found === null) {
            $this->fail('Table not found.', 404);
        }
        $sens = $this->sensitivePatterns();
        $sc = Schema::connection(self::CONN);
        $pk = [];
        foreach ($sc->getIndexes($found) as $ix) {
            $ix = (array) $ix;
            if (!empty($ix['primary'])) {
                $pk = array_map('strval', (array) $ix['columns']);
                break;
            }
        }
        $cols = [];
        foreach ($sc->getColumns($found) as $col) {
            $col = (array) $col;
            $n = (string) $col['name'];
            $kind = $this->kind((string) ($col['type'] ?? $col['type_name'] ?? ''));
            $sensitive = TenantDataGuard::sensitiveColumn($n, $sens);
            $gen = !empty($col['generation']);
            $auto = !empty($col['auto_increment']);
            $cols[$n] = [
                'name' => $n, 'type' => (string) ($col['type'] ?? ''), 'kind' => $kind, 'nullable' => (bool) ($col['nullable'] ?? true),
                'default' => $sensitive ? null : ($col['default'] ?? null), 'auto_increment' => $auto, 'primary' => in_array($n, $pk, true),
                'sensitive' => $sensitive, 'generated' => $gen, 'hidden_value' => $sensitive || $kind === 'binary',
                'editable' => !$sensitive && !$gen && $kind !== 'binary',
            ];
        }
        $reason = null;
        if (!$this->cfg('allow_write', true)) {
            $reason = 'writes_off';
        } elseif (TenantDataGuard::matches($found, (array) $this->cfg('readonly_tables', []))) {
            $reason = 'protected';
        } elseif (!$pk) {
            $reason = 'no_primary_key';
        }
        if ($forWrite && $reason !== null) {
            $this->fail(match ($reason) {
                'writes_off' => 'Editing is turned off on this platform.',
                'protected' => 'This table is read-only (logs and platform internals).',
                default => 'This table has no primary key, so rows cannot be edited safely.',
            }, 403);
        }
        return ['name' => $found, 'cols' => $cols, 'pk' => $pk, 'readonly' => $reason !== null, 'reason' => $reason];
    }

    /** @param array<string,mixed> $key @return array<string,mixed> */
    private function cleanKey(array $tb, array $key): array
    {
        $pk = $tb['pk'];
        if (!$pk || array_keys($key) === [] || count($key) !== count($pk)) {
            $this->fail('The row key is incomplete.');
        }
        $out = [];
        foreach ($pk as $c) {
            if (!array_key_exists($c, $key) || (!is_scalar($key[$c]))) {
                $this->fail('The row key is incomplete.');
            }
            $out[$c] = $key[$c];
        }
        return $out;
    }

    private function cell(mixed $v, bool $full = false): mixed
    {
        if ($v === null || is_bool($v) || is_int($v) || is_float($v)) {
            return $v;
        }
        $s = (string) $v;
        if (!mb_check_encoding($s, 'UTF-8')) {
            return '[binary ' . strlen($s) . ' bytes]';
        }
        $max = $full ? (int) $this->cfg('full_cell_bytes', 65536) : (int) $this->cfg('cell_chars', 300);
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max) . '…' : $s;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function present(array $tb, array $row, bool $full = false): array
    {
        $out = [];
        foreach ($tb['cols'] as $n => $col) {
            $out[$n] = $col['sensitive'] ? TenantDataGuard::MASK : ($col['kind'] === 'binary' ? '[binary]' : $this->cell($row[$n] ?? null, $full));
        }
        return $out;
    }

    /** @return string[] أعمدة بتتقري فعليًا (من غير الحساسة والـ binary). */
    private function readable(array $tb): array
    {
        return array_keys(array_filter($tb['cols'], static fn ($c) => !$c['hidden_value']));
    }

    private function like(string $v): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $v) . '%';
    }

    /* ------------------------------------------------------------------ read */

    /** @return array<string,mixed> */
    public function overview(int $userId, int $tenantId): array
    {
        [$t] = $this->tenants->authorize($userId, $tenantId, 'data');
        $limits = [
            'page_max' => (int) $this->cfg('page_max', 100), 'sql_max_rows' => (int) $this->cfg('sql_max_rows', 500),
            'sql_timeout_ms' => (int) $this->cfg('sql_timeout_ms', 8000), 'sql_max_chars' => (int) $this->cfg('sql_max_chars', 20000),
        ];
        if (!$this->cfg('enabled', true)) {
            return ['available' => false, 'reason' => 'disabled', 'limits' => $limits];
        }
        if (($t->mode ?? '') !== 'isolated' || ($t->db_status ?? '') !== 'ready') {
            return ['available' => false, 'reason' => ($t->mode ?? '') === 'isolated' ? 'not_ready' : 'shared', 'limits' => $limits];
        }
        return $this->run($userId, $tenantId, function ($t, $c) use ($limits) {
            $ro = (array) $this->cfg('readonly_tables', []);
            $raw = $this->rawTables();
            $vis = $this->visibleTables();
            return [
                'available' => true, 'driver' => $c->getDriverName(), 'write' => (bool) $this->cfg('allow_write', true), 'limits' => $limits,
                'hidden_count' => count($raw) - count($vis),
                'tables' => array_map(static fn ($x) => ['name' => $x['name'], 'size' => $x['size'], 'readonly' => TenantDataGuard::matches($x['name'], $ro)], $vis),
            ];
        });
    }

    /** هيكل جدول: أعمدة + فهارس + علاقات (معرّفة وكمان مستنتجة من أسماء *_id) + DDL (MySQL). */
    public function schema(int $userId, int $tenantId, string $table): array
    {
        return $this->run($userId, $tenantId, function ($t, $c) use ($table) {
            $tb = $this->table($table);
            $sc = Schema::connection(self::CONN);
            $indexes = array_map(static fn ($ix) => ['name' => (string) ((array) $ix)['name'], 'columns' => array_map('strval', (array) ((array) $ix)['columns']),
                'unique' => !empty(((array) $ix)['unique']), 'primary' => !empty(((array) $ix)['primary']), 'type' => (string) (((array) $ix)['type'] ?? '')], $sc->getIndexes($tb['name']));
            $vis = array_column($this->visibleTables(), 'name');
            $fk = $this->foreignKeys($c, $vis);
            $out = [];
            $in = [];
            $declared = [];
            foreach ($fk as $f) {
                if ($f['table'] === $tb['name']) {
                    $out[] = $f + ['inferred' => false];
                    $declared = array_merge($declared, $f['columns']);
                }
                if ($f['ref_table'] === $tb['name']) {
                    $in[] = $f + ['inferred' => false];
                }
            }
            foreach ($tb['cols'] as $n => $col) {
                if (in_array($n, $declared, true) || $col['primary'] || !preg_match('/^(.+)_id$/', $n, $m)) {
                    continue;
                }
                foreach ([$m[1] . 's', preg_replace('/y$/', 'ies', $m[1]), $m[1] . 'es', $m[1]] as $cand) {
                    if ($cand && in_array($cand, $vis, true) && $cand !== $tb['name']) {
                        $out[] = ['name' => null, 'table' => $tb['name'], 'columns' => [$n], 'ref_table' => $cand, 'ref_columns' => ['id'], 'on_delete' => null, 'on_update' => null, 'inferred' => true];
                        break;
                    }
                }
            }
            return [
                'table' => $tb['name'], 'columns' => array_values($tb['cols']), 'primary_key' => $tb['pk'], 'indexes' => $indexes,
                'references' => $out, 'referenced_by' => $in, 'readonly' => $tb['readonly'], 'readonly_reason' => $tb['reason'], 'ddl' => $this->ddl($c, $tb),
            ];
        });
    }

    private function ddl(ConnectionInterface $c, array $tb): ?string
    {
        if (!in_array($c->getDriverName(), ['mysql', 'mariadb'], true)) {
            return null;
        }
        try {
            $r = (array) ($c->select('SHOW CREATE TABLE ' . $c->getQueryGrammar()->wrapTable($tb['name']))[0] ?? []);
            $sql = (string) (array_values($r)[1] ?? '');
            return $sql !== '' ? $sql : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @param string[] $visible @return array<int,array{name:?string,table:string,columns:string[],ref_table:string,ref_columns:string[],on_delete:?string,on_update:?string}> */
    private function foreignKeys(ConnectionInterface $c, array $visible): array
    {
        $out = [];
        if (in_array($c->getDriverName(), ['mysql', 'mariadb'], true)) {
            $rows = $c->select('select k.constraint_name as n, k.table_name as t, k.column_name as c, k.referenced_table_name as rt, k.referenced_column_name as rc, r.update_rule as ur, r.delete_rule as dr '
                . 'from information_schema.key_column_usage k join information_schema.referential_constraints r on r.constraint_schema = k.constraint_schema and r.constraint_name = k.constraint_name and r.table_name = k.table_name '
                . 'where k.table_schema = database() and k.referenced_table_name is not null order by k.constraint_name, k.ordinal_position');
            $g = [];
            foreach ($rows as $r) {
                $r = (array) $r;
                $k = $r['t'] . '|' . $r['n'];
                $g[$k] ??= ['name' => $r['n'], 'table' => $r['t'], 'columns' => [], 'ref_table' => $r['rt'], 'ref_columns' => [], 'on_delete' => $r['dr'], 'on_update' => $r['ur']];
                $g[$k]['columns'][] = $r['c'];
                $g[$k]['ref_columns'][] = $r['rc'];
            }
            $out = array_values($g);
        } else {
            foreach ($visible as $tn) {
                foreach (Schema::connection(self::CONN)->getForeignKeys($tn) as $f) {
                    $f = (array) $f;
                    $out[] = ['name' => $f['name'] ?? null, 'table' => $tn, 'columns' => array_map('strval', (array) $f['columns']), 'ref_table' => (string) $f['foreign_table'],
                        'ref_columns' => array_map('strval', (array) $f['foreign_columns']), 'on_delete' => $f['on_delete'] ?? null, 'on_update' => $f['on_update'] ?? null];
                }
            }
        }
        return array_values(array_filter($out, static fn ($f) => in_array($f['table'], $visible, true) && in_array($f['ref_table'], $visible, true)));
    }

    /** @param array<string,mixed> $f @return array<string,mixed> */
    public function rows(int $userId, int $tenantId, string $table, array $f): array
    {
        return $this->run($userId, $tenantId, function ($t, $c) use ($table, $f) {
            $tb = $this->table($table);
            $per = max(1, min((int) $this->cfg('page_max', 100), (int) ($f['per_page'] ?? 25)));
            $page = max(1, (int) ($f['page'] ?? 1));
            $q = $c->table($tb['name']);
            $this->search($c, $q, $tb, (string) ($f['q'] ?? ''));
            $this->filters($c, $q, $tb, (array) ($f['filters'] ?? []));

            $cap = (int) $this->cfg('count_cap', 100000);
            $n = (int) $c->query()->fromSub((clone $q)->selectRaw('1')->limit($cap + 1), 'c')->count();

            $sort = (string) ($f['sort'] ?? '');
            $dir = strtolower((string) ($f['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
            if ($sort !== '' && isset($tb['cols'][$sort]) && !$tb['cols'][$sort]['hidden_value']) {
                $q->orderBy($sort, $dir);
            } else {
                foreach ($tb['pk'] as $p) {
                    $q->orderBy($p, 'asc');
                }
            }
            $read = $this->readable($tb);
            $rows = $read ? $q->select($read)->forPage($page, $per)->get()->map(fn ($r) => (array) $r)->all() : [];
            $items = array_map(function ($r) use ($tb) {
                $key = [];
                foreach ($tb['pk'] as $p) {
                    $key[$p] = $r[$p] ?? null;
                }
                return ['key' => $tb['pk'] ? $key : null, 'values' => $this->present($tb, $r)];
            }, $rows);
            return ['table' => $tb['name'], 'items' => $items, 'total' => min($n, $cap), 'total_capped' => $n > $cap, 'page' => $page, 'per_page' => $per,
                'primary_key' => $tb['pk'], 'readonly' => $tb['readonly'], 'readonly_reason' => $tb['reason']];
        });
    }

    private function search(ConnectionInterface $c, Builder $q, array $tb, string $term): void
    {
        $term = trim($term);
        if ($term === '') {
            return;
        }
        $g = $c->getQueryGrammar();
        $like = $this->like(mb_substr($term, 0, 100));
        $text = array_slice(array_keys(array_filter($tb['cols'], static fn ($x) => !$x['hidden_value'] && in_array($x['kind'], ['string', 'text'], true))), 0, 12);
        $nums = ctype_digit($term) && strlen($term) < 18 ? array_keys(array_filter($tb['cols'], static fn ($x) => !$x['hidden_value'] && $x['kind'] === 'int' && ($x['primary'] || str_ends_with($x['name'], '_id')))) : [];
        if (!$text && !$nums) {
            return;
        }
        $q->where(function ($w) use ($g, $text, $nums, $like, $term) {
            foreach ($text as $col) {
                $w->orWhereRaw($g->wrap($col) . " like ? escape '!'", [$like]);
            }
            foreach ($nums as $col) {
                $w->orWhere($col, '=', (int) $term);
            }
        });
    }

    /** @param array<int,mixed> $filters */
    private function filters(ConnectionInterface $c, Builder $q, array $tb, array $filters): void
    {
        $g = $c->getQueryGrammar();
        foreach (array_slice($filters, 0, 8) as $f) {
            $f = (array) $f;
            $col = (string) ($f['col'] ?? '');
            $op = (string) ($f['op'] ?? '=');
            if (!isset($tb['cols'][$col]) || $tb['cols'][$col]['hidden_value']) {
                $this->fail('Unknown column in filter.');
            }
            $val = $f['val'] ?? null;
            if ($op === 'null') {
                $q->whereNull($col);
            } elseif ($op === 'not_null') {
                $q->whereNotNull($col);
            } elseif ($op === 'like') {
                $q->whereRaw($g->wrap($col) . " like ? escape '!'", [$this->like(mb_substr((string) $val, 0, 200))]);
            } elseif (isset(self::OPS[$op]) && is_scalar($val)) {
                $q->where($col, self::OPS[$op], $val);
            } else {
                $this->fail('Unsupported filter.');
            }
        }
    }

    /** صف كامل (من غير تقصير الخلايا) — للمحرر. */
    public function row(int $userId, int $tenantId, string $table, array $key): array
    {
        return $this->run($userId, $tenantId, function ($t, $c) use ($table, $key) {
            $tb = $this->table($table);
            $r = $this->find($c, $tb, $this->cleanKey($tb, $key));
            return ['table' => $tb['name'], 'key' => $this->cleanKey($tb, $key), 'values' => $this->present($tb, $r, true), 'readonly' => $tb['readonly']];
        });
    }

    /** @param array<string,mixed> $key @return array<string,mixed> */
    private function find(ConnectionInterface $c, array $tb, array $key): array
    {
        $read = $this->readable($tb);
        $q = $c->table($tb['name']);
        foreach ($key as $k => $v) {
            $q->where($k, '=', $v);
        }
        $r = $read ? $q->select($read)->first() : $q->first([$tb['pk'][0]]);
        if (!$r) {
            $this->fail('Row not found.', 404);
        }
        return (array) $r;
    }

    /* ------------------------------------------------------------------ write */

    /** @param array<string,mixed> $in @return array<string,mixed> */
    private function values(array $tb, array $in, bool $insert): array
    {
        $out = [];
        foreach ($in as $n => $v) {
            $n = (string) $n;
            $col = $tb['cols'][$n] ?? null;
            if (!$col) {
                $this->fail('Unknown column: ' . $n . '.');
            }
            if (!$col['editable']) {
                $this->fail('The column "' . $n . '" cannot be edited here.');
            }
            if (!$insert && ($col['primary'] || $col['auto_increment'])) {
                $this->fail('The key column "' . $n . '" cannot be changed.');
            }
            if (is_array($v)) {
                $v = json_encode($v, JSON_UNESCAPED_UNICODE);
            } elseif (is_bool($v)) {
                $v = $v ? 1 : 0;
            } elseif ($v !== null && !is_scalar($v)) {
                $this->fail('Invalid value for "' . $n . '".');
            }
            if (is_string($v) && strlen($v) > (int) $this->cfg('full_cell_bytes', 65536)) {
                $this->fail('The value of "' . $n . '" is too large.');
            }
            if (is_string($v) && !mb_check_encoding($v, 'UTF-8')) {
                $this->fail('The value of "' . $n . '" must be valid UTF-8.');
            }
            if ($v === '' && in_array($col['kind'], ['int', 'decimal', 'bool', 'date', 'datetime', 'time', 'json'], true)) {
                $v = null;
            }
            if ($v === null && !$col['nullable']) {
                $this->fail('The column "' . $n . '" cannot be empty.');
            }
            $out[$n] = $v;
        }
        return $out;
    }

    private function keyLabel(array $key): string
    {
        return implode(',', array_map(static fn ($k, $v) => $k . '=' . mb_substr((string) $v, 0, 40), array_keys($key), $key));
    }

    /** @param array<string,mixed> $values @return array<string,mixed> */
    public function insert(int $userId, int $tenantId, string $table, array $values): array
    {
        return $this->run($userId, $tenantId, function ($t, $c) use ($userId, $table, $values) {
            $tb = $this->table($table, true);
            $data = $this->values($tb, $values, true);
            if (!$data) {
                $this->fail('Fill at least one column.');
            }
            $auto = array_values(array_filter($tb['cols'], static fn ($x) => $x['auto_increment']));
            $id = $c->transaction(function () use ($c, $tb, $data, $auto) {
                return count($auto) === 1 && !array_key_exists($auto[0]['name'], $data) ? $c->table($tb['name'])->insertGetId($data) : ($c->table($tb['name'])->insert($data) ? true : null);
            });
            $key = [];
            if (count($tb['pk']) === 1 && is_int($id)) {
                $key[$tb['pk'][0]] = $id;
            } else {
                foreach ($tb['pk'] as $p) {
                    $key[$p] = $data[$p] ?? null;
                }
            }
            $this->audit($t, $userId, 'data.insert', $tb['name'] . ' ' . $this->keyLabel($key) . ' · ' . implode(',', array_keys($data)));
            return ['table' => $tb['name'], 'key' => $key];
        });
    }

    /** @param array<string,mixed> $key @param array<string,mixed> $values @return array<string,mixed> */
    public function update(int $userId, int $tenantId, string $table, array $key, array $values): array
    {
        return $this->run($userId, $tenantId, function ($t, $c) use ($userId, $table, $key, $values) {
            $tb = $this->table($table, true);
            $key = $this->cleanKey($tb, $key);
            $data = $this->values($tb, $values, false);
            if (!$data) {
                $this->fail('Nothing to change.');
            }
            $c->transaction(function () use ($c, $tb, $key, $data) {
                $q = $c->table($tb['name']);
                foreach ($key as $k => $v) {
                    $q->where($k, '=', $v);
                }
                if (!(clone $q)->exists()) {
                    $this->fail('Row not found.', 404);
                }
                $q->update($data);
            });
            $this->audit($t, $userId, 'data.update', $tb['name'] . ' ' . $this->keyLabel($key) . ' · ' . implode(',', array_keys($data)));
            return ['table' => $tb['name'], 'key' => $key, 'values' => $this->present($tb, $this->find($c, $tb, $key))];
        });
    }

    /** @param array<string,mixed> $key @return array<string,mixed> */
    public function delete(int $userId, int $tenantId, string $table, array $key): array
    {
        return $this->run($userId, $tenantId, function ($t, $c) use ($userId, $table, $key) {
            $tb = $this->table($table, true);
            $key = $this->cleanKey($tb, $key);
            $n = $c->transaction(function () use ($c, $tb, $key) {
                $q = $c->table($tb['name']);
                foreach ($key as $k => $v) {
                    $q->where($k, '=', $v);
                }
                return $q->delete();
            });
            if ($n < 1) {
                $this->fail('Row not found.', 404);
            }
            $this->audit($t, $userId, 'data.delete', $tb['name'] . ' ' . $this->keyLabel($key));
            return ['table' => $tb['name'], 'deleted' => (int) $n];
        });
    }

    /* ------------------------------------------------------------------ SQL (read only) */

    /** @return array<string,mixed> */
    public function sql(int $userId, int $tenantId, string $sql): array
    {
        return $this->run($userId, $tenantId, function ($t, $c) use ($userId, $sql) {
            $all = array_column($this->rawTables(), 'name');
            [$clean, $log] = TenantDataGuard::checkSql($sql, $all, $this->hiddenPatterns(), $this->sensitivePatterns(), (int) $this->cfg('sql_max_chars', 20000));
            if (str_contains(TenantDataGuard::strip($clean), '?')) {
                $this->fail('The "?" character is not allowed in queries.');
            }
            $this->readOnlySession($c);
            $max = max(1, (int) $this->cfg('sql_max_rows', 500));
            $sens = $this->sensitivePatterns();
            $started = microtime(true);
            $rows = [];
            $truncated = false;
            try {
                foreach ($c->cursor($clean, [], false) as $r) {
                    if (count($rows) >= $max) {
                        $truncated = true;
                        break;
                    }
                    $row = TenantDataGuard::maskRow((array) $r, $sens);
                    $rows[] = array_map(fn ($v) => $this->cell($v), $row);
                }
            } catch (QueryException $e) {
                $this->audit($t, $userId, 'data.sql', 'FAILED · ' . $log);
                $this->fail($this->dbMessage($e));
            }
            $ms = (int) round((microtime(true) - $started) * 1000);
            $this->audit($t, $userId, 'data.sql', $log . ' · ' . count($rows) . ' rows · ' . $ms . 'ms');
            return ['columns' => $rows ? array_keys($rows[0]) : [], 'rows' => $rows, 'row_count' => count($rows), 'truncated' => $truncated, 'ms' => $ms];
        }, true);
    }

    /** جلسة قراءة فقط + timeout. لو متقدرش تتفعّل → رفض (fail-closed). */
    private function readOnlySession(ConnectionInterface $c): void
    {
        $d = $c->getDriverName();
        $ms = max(500, (int) $this->cfg('sql_timeout_ms', 8000));
        try {
            if (in_array($d, ['mysql', 'mariadb'], true)) {
                $c->statement('SET SESSION TRANSACTION READ ONLY');
                try {
                    $c->statement('SET SESSION max_execution_time = ' . $ms);
                } catch (\Throwable $e) {
                    try {
                        $c->statement('SET SESSION max_statement_time = ' . ($ms / 1000));
                    } catch (\Throwable $e2) {
                        // مفيش timeout على الإصدار ده — الـ read-only كفاية والسقف على الصفوف شغال
                    }
                }
            } elseif ($d === 'sqlite') {
                $c->statement('PRAGMA query_only = ON');
            } elseif ($d === 'pgsql') {
                $c->statement('SET default_transaction_read_only = on');
                $c->statement('SET statement_timeout = ' . $ms);
            } else {
                $this->fail('SQL is not supported for this database type.', 409);
            }
        } catch (\DomainException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->fail('Could not open a read-only session, so the query was not run.', 503);
        }
    }
}
