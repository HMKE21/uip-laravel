<?php

namespace App\Support;

/**
 * تنضيف بيانات "مكتبتي" القادمة من المتصفح قبل تخزينها: أنواع ثابتة، حدود للأطوال والأعداد، ومفيش أي مفاتيح غريبة.
 * Pure (من غير اعتماد على Laravel) عشان تتختبر لوحدها.
 */
final class LibraryStateSanitizer
{
    public const MAX_IDS = 5000;
    public const MAX_TAGS_PER_ITEM = 6;
    public const MAX_TAG_LEN = 24;
    public const MAX_SHARED = 100;
    public const MAX_SMART = 30;
    public const KINDS = ['image', 'video', 'audio', 'doc', 'note', 'link', 'code', 'data', 'archive', 'other'];
    public const PERIODS = ['any', 'today', '7d', '30d', 'month', 'year'];

    /** @return list<int> */
    public static function ids($v): array
    {
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $x) {
            if ((is_int($x) || (is_string($x) && ctype_digit($x))) && (int) $x > 0) {
                $out[(int) $x] = (int) $x;
            }
            if (count($out) >= self::MAX_IDS) {
                break;
            }
        }
        return array_values($out);
    }

    /** @return array{fav:list<int>,tags:array<string,list<string>>,shared:list<array<string,mixed>>} */
    public static function marks($v): array
    {
        $v = is_array($v) ? $v : [];
        $tags = [];
        if (isset($v['tags']) && is_array($v['tags'])) {
            foreach ($v['tags'] as $id => $list) {
                if (!ctype_digit((string) $id) || (int) $id < 1 || !is_array($list)) {
                    continue;
                }
                $clean = [];
                foreach ($list as $t) {
                    $t = is_string($t) ? trim(mb_substr($t, 0, self::MAX_TAG_LEN)) : '';
                    if ($t !== '') {
                        $clean[$t] = $t;
                    }
                }
                $clean = array_slice(array_values($clean), 0, self::MAX_TAGS_PER_ITEM);
                if ($clean) {
                    $tags[(string) (int) $id] = $clean;
                }
                if (count($tags) >= self::MAX_IDS) {
                    break;
                }
            }
        }
        $shared = [];
        if (isset($v['shared']) && is_array($v['shared'])) {
            foreach ($v['shared'] as $e) {
                if (!is_array($e) || !isset($e['token']) || !is_string($e['token']) || !preg_match('/^[A-Za-z0-9]{8,64}$/', $e['token'])) {
                    continue;
                }
                $row = [];
                foreach ($e as $k => $val) {
                    if (!is_string($k) || strlen($k) > 30 || count($row) >= 12) {
                        continue;
                    }
                    if (is_string($val)) {
                        $row[$k] = mb_substr($val, 0, 300);
                    } elseif (is_int($val) || is_float($val) || is_bool($val)) {
                        $row[$k] = $val;
                    }
                }
                $shared[] = $row;
                if (count($shared) >= self::MAX_SHARED) {
                    break;
                }
            }
        }
        return ['fav' => self::ids($v['fav'] ?? []), 'tags' => $tags, 'shared' => $shared];
    }

    /** @return array<string,mixed> */
    public static function rules($r): array
    {
        $r = is_array($r) ? $r : [];
        $kinds = [];
        foreach ((array) ($r['kinds'] ?? []) as $k) {
            if (is_string($k) && in_array($k, self::KINDS, true)) {
                $kinds[$k] = $k;
            }
        }
        $period = isset($r['period']) && in_array($r['period'], self::PERIODS, true) ? $r['period'] : 'any';
        $mb = static fn ($x) => (is_int($x) || is_float($x) || (is_string($x) && is_numeric($x))) && $x >= 0 ? min(1024 * 1024, (float) $x) : null;
        return [
            'kinds'    => array_values($kinds),
            'period'   => $period,
            'min_mb'   => $mb($r['min_mb'] ?? null),
            'max_mb'   => $mb($r['max_mb'] ?? null),
            'tag'      => isset($r['tag']) && is_string($r['tag']) ? trim(mb_substr($r['tag'], 0, self::MAX_TAG_LEN)) : '',
            'fav'      => !empty($r['fav']),
            'contains' => isset($r['contains']) && is_string($r['contains']) ? trim(mb_substr($r['contains'], 0, 60)) : '',
        ];
    }

    /** @return array{hidden:list<int>,source:string,view:string,smart:list<array<string,mixed>>} */
    public static function library($v): array
    {
        $v = is_array($v) ? $v : [];
        $smart = [];
        foreach ((array) ($v['smart'] ?? []) as $s) {
            if (!is_array($s)) {
                continue;
            }
            $id = isset($s['id']) && is_string($s['id']) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $s['id']) ? $s['id'] : null;
            $name = isset($s['name']) && is_string($s['name']) ? trim(mb_substr($s['name'], 0, 40)) : '';
            if ($id === null || $name === '' || isset($smart[$id])) {
                continue;
            }
            $smart[$id] = ['id' => $id, 'name' => $name, 'rules' => self::rules($s['rules'] ?? [])];
            if (count($smart) >= self::MAX_SMART) {
                break;
            }
        }
        return [
            'hidden' => self::ids($v['hidden'] ?? []),
            'source' => ($v['source'] ?? '') === 'lib' ? 'lib' : 'all',
            'view'   => ($v['view'] ?? '') === 'list' ? 'list' : 'grid',
            'smart'  => array_values($smart),
        ];
    }
}
