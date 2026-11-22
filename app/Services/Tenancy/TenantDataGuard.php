<?php

namespace App\Services\Tenancy;

/**
 * حارس إدارة بيانات المساحة (Database Manager). كلاس صافي (من غير DB) عشان يتختبر لوحده.
 *  - الجداول المخفية والأعمدة الحساسة بالأنماط (fnmatch).
 *  - SQL editor: SELECT/WITH بس، statement واحد، من غير تعليقات ولا متغيرات ولا دوال خطرة،
 *    ومن غير اسم جدول مخفي أو عمود حساس، ومن غير قاعدة تانية (db.table) أو information_schema.
 *  الطبقة الأخيرة (حساب MySQL بصلاحيات القراءة + transaction read only) في TenantDataService — ده حزام فوقها مش بديل.
 */
final class TenantDataGuard
{
    public const MASK = '••••••';

    /** كلمات ممنوعة جوه SELECT (الـ statement لازم يبدأ بـ select/with ومن غير ;، فالباقي مش محتاج حظر). */
    private const FORBIDDEN_WORDS = [
        'insert', 'update', 'delete', 'drop', 'alter', 'create', 'truncate', 'grant', 'revoke', 'call', 'do', 'handler', 'load', 'outfile',
        'dumpfile', 'into', 'lock', 'unlock', 'set', 'prepare', 'execute', 'deallocate', 'rename', 'optimize', 'analyze', 'flush', 'kill',
        'shutdown', 'sleep', 'benchmark', 'get_lock', 'release_lock', 'release_all_locks', 'is_free_lock', 'is_used_lock', 'load_file',
        'master_pos_wait', 'procedure', 'pragma', 'attach', 'detach', 'show', 'explain', 'describe', 'use', 'current_user', 'session_user',
        'system_user', 'database', 'schema', 'sqlite_master', 'sqlite_schema', 'sqlite_temp_master', 'information_schema', 'mysql',
        'performance_schema', 'sys', 'pg_catalog', 'pg_class', 'pg_shadow', 'pg_authid',
    ];

    private const KEYWORDS_AFTER_TABLE = [
        'as', 'on', 'where', 'join', 'inner', 'left', 'right', 'cross', 'natural', 'group', 'order', 'limit', 'union', 'having', 'using',
        'full', 'straight_join', 'window', 'except', 'intersect', 'offset', 'fetch', 'outer', 'partition', 'use', 'force', 'ignore',
    ];

    /** fnmatch بحروف صغيرة. */
    public static function matches(string $name, array $patterns): bool
    {
        $n = strtolower($name);
        foreach ($patterns as $p) {
            if (fnmatch(strtolower((string) $p), $n)) {
                return true;
            }
        }
        return false;
    }

    public static function hiddenTable(string $table, array $patterns): bool
    {
        return self::matches($table, $patterns);
    }

    public static function sensitiveColumn(string $column, array $patterns): bool
    {
        return self::matches($column, $patterns);
    }

    /** يقنّع قيم الأعمدة الحساسة في صف (حسب اسم العمود). @param array<string,mixed> $row @return array<string,mixed> */
    public static function maskRow(array $row, array $sensitive): array
    {
        foreach ($row as $k => $v) {
            if ($v !== null && self::sensitiveColumn((string) $k, $sensitive)) {
                $row[$k] = self::MASK;
            }
        }
        return $row;
    }

    /**
     * يفحص SQL ويرجّع [sql نضيف, نسخة للسجل (القيم النصية متشالة)]. بيرمي DomainException(422) لو مرفوض.
     * @param string[] $tables أسماء جداول القاعدة الحقيقية (الظاهرة + المخفية)
     * @return array{0:string,1:string}
     */
    public static function checkSql(string $sql, array $tables, array $hiddenTables, array $sensitiveColumns, int $maxChars = 20000): array
    {
        $sql = trim($sql);
        if ($sql === '') {
            self::no('Write a query first.');
        }
        if (strlen($sql) > $maxChars) {
            self::no('The query is too long.');
        }
        if (!mb_check_encoding($sql, 'UTF-8')) {
            self::no('The query must be valid UTF-8.');
        }
        $sql = rtrim($sql, "; \t\r\n");
        // الـ backslash والـ double quotes بتتفسّر بشكل مختلف حسب sql_mode / نوع القاعدة (NO_BACKSLASH_ESCAPES, ANSI_QUOTES) — نمنعهم عشان نتجنب أي لبس في حدود النصوص.
        if (strpos($sql, '\\') !== false) {
            self::no('Backslashes are not allowed. Use two single quotes to write a quote inside text.');
        }
        if (strpos($sql, '"') !== false) {
            self::no('Use single quotes for text values.');
        }
        $code = self::strip($sql);

        if (strpos($code, ';') !== false) {
            self::no('Only one statement is allowed.');
        }
        if (strpos($code, '@') !== false) {
            self::no('Variables and system variables are not allowed.');
        }
        if (!preg_match('/^\s*(select|with)\b/i', $code)) {
            self::no('Only SELECT queries are allowed here. Use the table grid to add, edit or delete rows.');
        }
        $lower = strtolower($code);

        foreach (self::words($lower) as $w) {
            if (in_array($w, self::FORBIDDEN_WORDS, true)) {
                self::no('This query uses something that is not allowed: ' . $w . '.');
            }
            if (self::hiddenTable($w, $hiddenTables)) {
                self::no('This query touches a protected table.');
            }
            if (self::sensitiveColumn($w, $sensitiveColumns)) {
                self::no('This query touches a protected column (passwords, tokens or secrets).');
            }
        }

        $known = array_map('strtolower', $tables);
        $ctes = [];
        if (preg_match_all('/(?:\bwith(?:\s+recursive)?|,)\s*([a-z_][a-z0-9_$]*)\s*(?:\([^)]*\))?\s+as\s*\(/', $lower, $m)) {
            $ctes = $m[1];
        }
        $aliases = $ctes;
        if (preg_match_all('/\bas\s+([a-z_][a-z0-9_$]*)/', $lower, $m)) {
            $aliases = array_merge($aliases, $m[1]);
        }
        if (preg_match_all('/\)\s*(?:as\s+)?([a-z_][a-z0-9_$]*)/', $lower, $m)) {
            $aliases = array_merge($aliases, $m[1]);
        }
        $kw = implode('|', self::KEYWORDS_AFTER_TABLE);
        if (preg_match_all('/\b(?:from|join)\s+([a-z_][a-z0-9_$]*)\s+(?!(?:' . $kw . ')\b)([a-z_][a-z0-9_$]*)/', $lower, $m)) {
            $aliases = array_merge($aliases, $m[2]);
        }

        // FROM / JOIN لازم يشاور على جدول معروف (أو CTE) — ومش db.table.
        if (preg_match('/\b(?:from|join)\s+[a-z_][a-z0-9_$]*\s*\./', $lower)) {
            self::no('Other databases are not accessible.');
        }
        if (preg_match_all('/\b(?:from|join)\s+([a-z_][a-z0-9_$]*)/', $lower, $m)) {
            foreach ($m[1] as $t) {
                if (!in_array($t, $known, true) && !in_array($t, $ctes, true)) {
                    self::no('Unknown table: ' . $t . '.');
                }
            }
        }
        // أي qualifier (x.col) لازم يكون جدول أو alias معروف.
        $okQualifiers = array_merge($known, $aliases);
        if (preg_match_all('/(?<![a-z0-9_$.])([a-z_][a-z0-9_$]*)\s*\.\s*(?=[a-z_*])/', $lower, $m)) {
            foreach ($m[1] as $q) {
                if (!in_array($q, $okQualifiers, true)) {
                    self::no('Other databases are not accessible.');
                }
            }
        }

        $log = trim((string) preg_replace('/\s+/', ' ', $code));
        return [$sql, mb_substr($log, 0, 200)];
    }

    /** @return string[] كلمات/معرّفات الكود (بعد شيل النصوص). */
    private static function words(string $lowerCode): array
    {
        preg_match_all('/[a-z_][a-z0-9_$]*/', $lowerCode, $m);
        return array_values(array_unique($m[0]));
    }

    /**
     * يرجّع الكود من غير النصوص ('..'): بتتبدّل بـ ''. المعرّفات بين backticks بتتفك (`name` → name، ولو فيها حاجة غريبة → رفض).
     * أي تعليق (-- أو # أو /* ) برا النصوص = رفض (التعليقات ممكن تخبّي كود أو تشغّل /*! */ في MySQL).
     */
    public static function strip(string $sql): string
    {
        $out = '';
        $n = strlen($sql);
        for ($i = 0; $i < $n; $i++) {
            $c = $sql[$i];
            if ($c === "'") {
                $i++;
                while ($i < $n) {
                    if ($sql[$i] === "'") {
                        if (($sql[$i + 1] ?? '') === "'") {
                            $i += 2;
                            continue;
                        }
                        break;
                    }
                    $i++;
                }
                if ($i >= $n) {
                    self::no('The query has an unclosed quote.');
                }
                $out .= "''";
                continue;
            }
            if ($c === '`') {
                $j = strpos($sql, '`', $i + 1);
                if ($j === false) {
                    self::no('The query has an unclosed quote.');
                }
                $name = substr($sql, $i + 1, $j - $i - 1);
                if (!preg_match('/^[A-Za-z_][A-Za-z0-9_$]*$/', $name)) {
                    self::no('Unsupported identifier.');
                }
                $out .= $name;
                $i = $j;
                continue;
            }
            if ($c === '#' || ($c === '-' && ($sql[$i + 1] ?? '') === '-') || ($c === '/' && ($sql[$i + 1] ?? '') === '*')) {
                self::no('Comments are not allowed in queries.');
            }
            $out .= $c;
        }
        return $out;
    }

    private static function no(string $msg): never
    {
        throw new \DomainException($msg, 422);
    }
}
