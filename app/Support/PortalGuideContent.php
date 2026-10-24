<?php

namespace App\Support;

/**
 * تنضيف + فحص محتوى صفحات الدليل اللي الأدمن بيكتبه (بيظهر لكل المستخدمين، فلازم يكون آمن).
 *
 *  - نص عادي بس: أي HTML بيتشال (strip_tags). الفرونت بيعرض **bold** و `code` والسطور الجديدة بس.
 *  - الـ route داخلي بس (يبدأ بـ "/")، مفيش http:/javascript: ولا "//".
 *  - كل الدوال بترجّع [data, errors] — data بأسماء الأعمدة الجاهزة للكتابة، errors = [field => message].
 *  - partial=true (تعديل): الحقول اللي مش مبعوتة متتلمسش.
 */
class PortalGuideContent
{
    private const KEY_RE        = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
    private const PERMISSION_RE = '/^[a-z0-9_.\-]+$/';
    private const ROUTE_RE      = '~^/(?!/)[A-Za-z0-9\-_/:.]*(?:\?[A-Za-z0-9\-_=&%.]*)?(?:#[A-Za-z0-9\-_]*)?$~';
    private const KINDS         = ['overview', 'group', 'tasks', 'faq', 'custom'];

    public static function cleanText(mixed $value): string
    {
        $s = is_scalar($value) ? (string) $value : '';
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = strip_tags($s);
        // أحرف تحكم (غير \n و \t) + محارف اتجاه مخفية بتتشال.
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $s) ?? '';
        $s = preg_replace("/\n{3,}/", "\n\n", $s) ?? '';
        return trim($s);
    }

    public static function key(mixed $value): ?string
    {
        $s = strtolower(trim(is_scalar($value) ? (string) $value : ''));
        return ($s !== '' && strlen($s) <= 80 && preg_match(self::KEY_RE, $s)) ? $s : null;
    }

    public static function route(mixed $value): ?string
    {
        $s = trim(is_scalar($value) ? (string) $value : '');
        return ($s !== '' && strlen($s) <= 255 && preg_match(self::ROUTE_RE, $s)) ? $s : null;
    }

    /** @return string[]|null  null = غير صالح */
    public static function roles(mixed $value): ?array
    {
        if ($value === null || $value === [] || $value === '') {
            return [];
        }
        if (!is_array($value)) {
            return null;
        }
        $allowed = (array) config('roles.available', []);
        $out = [];
        foreach ($value as $r) {
            $r = is_string($r) ? trim($r) : '';
            if ($r === '' || !in_array($r, $allowed, true)) {
                return null;
            }
            $out[$r] = true;
        }
        return array_keys($out);
    }

    /**
     * قائمة {ar,en} (steps / tips). بترجّع null لو الشكل غلط، [] لو فاضية.
     * @return array<int,array{ar:string,en:string}>|null
     */
    public static function pairs(mixed $value, int $max, int $maxLen): ?array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_array($value) || count($value) > $max) {
            return null;
        }
        $out = [];
        foreach (array_values($value) as $row) {
            if (!is_array($row)) {
                return null;
            }
            $ar = self::cleanText($row['ar'] ?? '');
            $en = self::cleanText($row['en'] ?? '');
            if ($ar === '' && $en === '') {
                continue;
            }
            if (mb_strlen($ar) > $maxLen || mb_strlen($en) > $maxLen) {
                return null;
            }
            $out[] = ['ar' => $ar !== '' ? $ar : $en, 'en' => $en !== '' ? $en : $ar];
        }
        return $out;
    }

    private static function has(array $in, string $field, bool $partial): bool
    {
        return !$partial || array_key_exists($field, $in);
    }

    /** نص ثنائي اللغة (ar/en) — بيحط الناقص من التاني لو required. */
    private static function bilingual(array $in, string $base, int $max, bool $partial, bool $required, array &$data, array &$errors): void
    {
        $fa = "{$base}_ar";
        $fe = "{$base}_en";
        if (!self::has($in, $fa, $partial) && !self::has($in, $fe, $partial)) {
            return;
        }
        if ($partial && !array_key_exists($fa, $in) && !array_key_exists($fe, $in)) {
            return;
        }
        $ar = array_key_exists($fa, $in) ? self::cleanText($in[$fa]) : null;
        $en = array_key_exists($fe, $in) ? self::cleanText($in[$fe]) : null;

        if ($required) {
            $a = $ar ?? '';
            $e = $en ?? '';
            if ($a === '' && $e === '') {
                if (!$partial || $ar !== null || $en !== null) {
                    $errors[$fa] = "{$base} is required (Arabic or English).";
                }
                return;
            }
            // الأدمن ممكن يكتب لغة واحدة: الناقصة بتتنسخ من التانية.
            // في الإنشاء الاتنين لازم يتكتبوا؛ في التعديل بنلمس بس اللي اتبعت.
            if (!$partial || $ar !== null) {
                $ar = $a !== '' ? $a : $e;
            }
            if (!$partial || $en !== null) {
                $en = $e !== '' ? $e : $a;
            }
        }
        foreach ([$fa => $ar, $fe => $en] as $f => $v) {
            if ($v === null) {
                continue;
            }
            if (mb_strlen($v) > $max) {
                $errors[$f] = "{$f} must be at most {$max} characters.";
                continue;
            }
            $data[$f] = ($v === '' && !$required) ? null : $v;
        }
    }

    /** @return array{0:array<string,mixed>,1:array<string,string>} */
    public static function guideMeta(array $in, bool $partial = true): array
    {
        $lim = (array) config('guides.limits');
        $data = [];
        $errors = [];
        self::bilingual($in, 'title', (int) $lim['title'], $partial, true, $data, $errors);
        self::bilingual($in, 'subtitle', 255, $partial, false, $data, $errors);
        self::bilingual($in, 'intro', (int) $lim['intro'], $partial, false, $data, $errors);
        if (array_key_exists('icon', $in)) {
            $icon = self::key($in['icon']);
            if ($icon === null || strlen($icon) > 48) {
                $errors['icon'] = 'icon must be a lowercase slug.';
            } else {
                $data['icon'] = $icon;
            }
        }
        if (array_key_exists('is_published', $in)) {
            $data['is_published'] = filter_var($in['is_published'], FILTER_VALIDATE_BOOLEAN);
        }
        return [$data, $errors];
    }

    /** @return array{0:array<string,mixed>,1:array<string,string>} */
    public static function section(array $in, bool $partial = false): array
    {
        $lim = (array) config('guides.limits');
        $data = [];
        $errors = [];

        if (!$partial || array_key_exists('key', $in)) {
            $k = self::key($in['key'] ?? '');
            if ($k === null) {
                $errors['key'] = 'key is required (lowercase letters, digits and dashes).';
            } else {
                $data['key'] = $k;
            }
        }
        if (array_key_exists('kind', $in) || !$partial) {
            $kind = (string) ($in['kind'] ?? 'custom');
            if (!in_array($kind, self::KINDS, true)) {
                $errors['kind'] = 'kind must be one of: ' . implode(', ', self::KINDS) . '.';
            } else {
                $data['kind'] = $kind;
            }
        }
        self::bilingual($in, 'title', (int) $lim['title'], $partial, true, $data, $errors);
        self::bilingual($in, 'summary', (int) $lim['summary'], $partial, false, $data, $errors);
        self::common($in, $data, $errors);
        return [$data, $errors];
    }

    /** @return array{0:array<string,mixed>,1:array<string,string>} */
    public static function item(array $in, bool $partial = false): array
    {
        $lim = (array) config('guides.limits');
        $data = [];
        $errors = [];

        if (!$partial || array_key_exists('key', $in)) {
            $k = self::key($in['key'] ?? '');
            if ($k === null) {
                $errors['key'] = 'key is required (lowercase letters, digits and dashes).';
            } else {
                $data['key'] = $k;
            }
        }
        self::bilingual($in, 'title', (int) $lim['title'], $partial, true, $data, $errors);
        self::bilingual($in, 'summary', (int) $lim['summary'], $partial, false, $data, $errors);
        self::bilingual($in, 'body', (int) $lim['body'], $partial, false, $data, $errors);

        if (array_key_exists('route', $in)) {
            if ($in['route'] === null || $in['route'] === '') {
                $data['route'] = null;
            } else {
                $r = self::route($in['route']);
                if ($r === null) {
                    $errors['route'] = 'route must be an internal path starting with "/".';
                } else {
                    $data['route'] = $r;
                }
            }
        }
        if (array_key_exists('permission', $in)) {
            $p = is_string($in['permission']) ? trim($in['permission']) : '';
            if ($p === '') {
                $data['permission'] = null;
            } elseif (strlen($p) > 100 || !preg_match(self::PERMISSION_RE, $p)) {
                $errors['permission'] = 'permission is not a valid slug.';
            } else {
                $data['permission'] = $p;
            }
        }
        foreach (['steps' => (int) $lim['steps'], 'tips' => (int) $lim['tips']] as $f => $max) {
            if (array_key_exists($f, $in)) {
                $v = self::pairs($in[$f], $max, (int) $lim['step']);
                if ($v === null) {
                    $errors[$f] = "{$f} must be a list of {ar,en} texts (max {$max}).";
                } else {
                    $data[$f] = $v === [] ? null : $v;
                }
            }
        }
        self::common($in, $data, $errors);
        return [$data, $errors];
    }

    /** icon / audience_roles / sort_order / is_hidden */
    private static function common(array $in, array &$data, array &$errors): void
    {
        if (array_key_exists('icon', $in)) {
            if ($in['icon'] === null || $in['icon'] === '') {
                $data['icon'] = null;
            } else {
                $icon = self::key($in['icon']);
                if ($icon === null || strlen($icon) > 48) {
                    $errors['icon'] = 'icon must be a lowercase slug.';
                } else {
                    $data['icon'] = $icon;
                }
            }
        }
        if (array_key_exists('audience_roles', $in)) {
            $roles = self::roles($in['audience_roles']);
            if ($roles === null) {
                $errors['audience_roles'] = 'audience_roles must be a list of valid role slugs.';
            } else {
                $data['audience_roles'] = $roles === [] ? null : $roles;
            }
        }
        if (array_key_exists('sort_order', $in)) {
            $data['sort_order'] = max(0, min(100000, (int) $in['sort_order']));
        }
        if (array_key_exists('is_hidden', $in)) {
            $data['is_hidden'] = filter_var($in['is_hidden'], FILTER_VALIDATE_BOOLEAN);
        }
    }
}
