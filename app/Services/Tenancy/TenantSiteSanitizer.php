<?php

namespace App\Services\Tenancy;

use Illuminate\Support\Str;

/**
 * بيحوّل أي input من العميل لإعدادات موقع سليمة: بيقبل بس المفاتيح المعرّفة في TenantSiteTemplates، وبيقصّ الأطوال،
 * وبيشيل أي HTML، وروابط https أو مسار داخلي بس، وألوان #RRGGBB. أي حاجة غريبة بتتشال بصمت؛ الأخطاء الصريحة بس للحجم الكلي.
 */
final class TenantSiteSanitizer
{
    private static function fail(string $msg, int $code = 422): never
    {
        throw new \DomainException($msg, $code);
    }

    /** نص عادي: من غير tags ولا control chars، متقصّر. */
    public static function text($v, int $max): string
    {
        if (!is_string($v) && !is_numeric($v)) {
            return '';
        }
        $s = strip_tags((string) $v);
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
        $s = trim($s);
        return mb_substr($s, 0, $max);
    }

    public static function link($v): ?string
    {
        $v = trim((string) (is_string($v) ? $v : ''));
        if ($v === '' || strlen($v) > 300 || preg_match('/[\s<>"\'\\\\]/', $v)) {
            return null;
        }
        if (preg_match('~^/(?!/)[A-Za-z0-9\-._\~/%?=&#:@+]*$~', $v)) {
            return $v;                                              // مسار داخلي
        }
        if (preg_match('~^https://~i', $v) && filter_var($v, FILTER_VALIDATE_URL) && parse_url($v, PHP_URL_HOST)) {
            return $v;
        }
        return null;
    }

    public static function img($v): ?string
    {
        $l = self::link($v);
        return ($l !== null && preg_match('~^https://~i', $l)) ? $l : null;
    }

    public static function color($v): ?string
    {
        return (is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', trim($v))) ? strtoupper(trim($v)) : null;
    }

    private static function value(string|array $spec, $v)
    {
        if (is_array($spec)) {                                       // ['list', max, [مفتاح => نوع]]
            if (($spec[0] ?? null) === 'list') {
                $out = [];
                foreach (array_slice(is_array($v) ? array_values($v) : [], 0, (int) $spec[1]) as $row) {
                    if (is_array($row)) {
                        $out[] = self::object($spec[2], $row);
                    }
                }
                return $out;
            }
            return self::object($spec, is_array($v) ? $v : []);
        }
        [$kind, $arg] = array_pad(explode(':', $spec, 2), 2, null);
        switch ($kind) {
            case 't':
                return self::text($v, (int) $arg);
            case 'i18n':
                $v = is_array($v) ? $v : [];
                return ['en' => self::text($v['en'] ?? '', (int) $arg), 'ar' => self::text($v['ar'] ?? '', (int) $arg)];
            case 'link':
                return self::link($v);
            case 'img':
                return self::img($v);
            case 'color':
                return self::color($v);
            case 'bool':
                return filter_var($v, FILTER_VALIDATE_BOOLEAN);
            case 'email':
                $e = self::text($v, 150);
                return ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) ? $e : '';
            case 'enum':
                $opts = explode('|', (string) $arg);
                return in_array($v, $opts, true) ? $v : $opts[0];
        }
        return null;
    }

    /** @param array<string,string|array> $spec */
    public static function object(array $spec, array $in): array
    {
        $out = [];
        foreach ($spec as $k => $s) {
            $out[$k] = self::value($s, $in[$k] ?? null);
        }
        return $out;
    }

    /** sections: بالترتيب، id فريد، النوع من القايمة المسموحة، props حسب مخطط النوع. */
    public static function sections($in, ?int $limit = null): array
    {
        $max = $limit ?? (int) config('tenancy.site.max_sections', 12);
        $specs = TenantSiteTemplates::sectionSpecs();
        $out = [];
        $seen = [];
        // الأنواع غير المسموحة مبتاخدش من حصة الأقسام: بنفلتر الأول وبعدين نوقف عند الحد (مع سقف أمان على عدد الصفوف المفحوصة).
        foreach (array_slice(is_array($in) ? array_values($in) : [], 0, max($max * 4, 50)) as $row) {
            if (count($out) >= $max) {
                break;
            }
            if (!is_array($row) || !is_string($row['type'] ?? null) || !isset($specs[$row['type']])) {
                continue;
            }
            $type = $row['type'];
            $id = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($row['id'] ?? ''))) ?: '';
            $id = substr($id, 0, 20);
            if ($id === '' || isset($seen[$id])) {
                $id = $type . '-' . Str::lower(Str::random(5));
            }
            $seen[$id] = true;
            $out[] = ['id' => $id, 'type' => $type, 'enabled' => !array_key_exists('enabled', $row) || filter_var($row['enabled'], FILTER_VALIDATE_BOOLEAN),
                'props' => self::object($specs[$type], is_array($row['props'] ?? null) ? $row['props'] : [])];
        }
        return $out;
    }

    /** slug سليم (a-z 0-9 -) من نص حر. بيرجّع '' لو مفيش حاجة صالحة. */
    public static function slugify($v, int $max = 40): string
    {
        $s = strtolower(self::text($v, 200));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        return substr(trim($s, '-'), 0, $max);
    }

    /** يضمن slug فريد جوه المجموعة (بيضيف -2 -3…) ومش محجوز. */
    private static function uniqueSlug(string $slug, array &$seen, array $reserved, string $fallback): string
    {
        $slug = $slug !== '' ? $slug : $fallback;
        $base = substr($slug, 0, 36);
        $n = 1;
        while (isset($seen[$slug]) || in_array($slug, $reserved, true)) {
            $n++;
            $slug = $base . '-' . $n;
        }
        $seen[$slug] = true;
        return $slug;
    }

    private static function itemId($v, string $prefix, array &$seen): string
    {
        $id = substr(preg_replace('/[^a-z0-9_-]/', '', strtolower((string) (is_string($v) ? $v : ''))) ?: '', 0, 20);
        if ($id === '' || isset($seen[$id])) {
            $id = $prefix . '-' . Str::lower(Str::random(5));
        }
        $seen[$id] = true;
        return $id;
    }

    /** صفحات إضافية (من نحن / تواصل / مخصصة): id وslug فريدين، kind، عنوان، SEO، وأقسام بنفس مخطط الصفحة الرئيسية. */
    public static function pages($in): array
    {
        $max = (int) config('tenancy.site.max_pages', 8);
        $maxSections = (int) config('tenancy.site.max_page_sections', 10);
        $spec = TenantSiteTemplates::pageSpec();
        $out = [];
        $ids = [];
        $slugs = [];
        foreach (array_slice(is_array($in) ? array_values($in) : [], 0, $max * 3) as $row) {
            if (count($out) >= $max) {
                break;
            }
            if (!is_array($row)) {
                continue;
            }
            $page = self::object($spec, $row);
            foreach (['enabled', 'show_in_nav'] as $flag) {
                if (!array_key_exists($flag, $row)) {
                    $page[$flag] = true;                         // الصفحة الجديدة شغّالة وظاهرة في القايمة إلا لو العميل قفلها
                }
            }
            $title = $page['title']['en'] ?: $page['title']['ar'];
            $kind = $page['kind'];
            $slug = self::uniqueSlug(self::slugify($row['slug'] ?? '') ?: self::slugify($title), $slugs, TenantSiteTemplates::RESERVED_PAGE_SLUGS, $kind === 'custom' ? 'page' : $kind);
            $out[] = ['id' => self::itemId($row['id'] ?? '', 'page', $ids), 'slug' => $slug] + $page + ['sections' => self::sections($row['sections'] ?? [], $maxSections)];
        }
        return $out;
    }

    private static function date($v): string
    {
        if (is_string($v) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $v;
        }
        return now()->format('Y-m-d');
    }

    /** مقالات المدونة: slug فريد، تاريخ Y-m-d، عنوان/ملخص/محتوى ثنائي اللغة، غلاف https، SEO. */
    public static function posts($in): array
    {
        $max = (int) config('tenancy.site.max_posts', 30);
        $spec = TenantSiteTemplates::postSpec();
        $out = [];
        $ids = [];
        $slugs = [];
        foreach (array_slice(is_array($in) ? array_values($in) : [], 0, $max * 2) as $row) {
            if (count($out) >= $max) {
                break;
            }
            if (!is_array($row)) {
                continue;
            }
            $post = self::object($spec, $row);
            $title = $post['title']['en'] ?: $post['title']['ar'];
            $slug = self::uniqueSlug(self::slugify($row['slug'] ?? '') ?: self::slugify($title), $slugs, [], 'post');
            $out[] = ['id' => self::itemId($row['id'] ?? '', 'post', $ids), 'slug' => $slug, 'date' => self::date($row['date'] ?? null)] + $post;
        }
        return $out;
    }

    /**
     * إعدادات كاملة. المفاتيح الغايبة من $in بتاخد قيمتها من $base (تعديل جزئي)؛ المفاتيح الموجودة بتتفحص كلها.
     * @param array<string,mixed> $base الإعدادات الحالية (سليمة بالفعل)
     */
    public static function settings(array $in, array $base): array
    {
        $out = $base + TenantSiteTemplates::emptySettings();
        $root = TenantSiteTemplates::rootSpec();
        foreach ($root as $key => $spec) {
            if (!array_key_exists($key, $in)) {
                continue;
            }
            if (is_array($spec) && ($spec[0] ?? null) !== 'list' && is_array($in[$key]) && is_array($out[$key] ?? null)) {
                // object (theme/layout/texts/footer): تعديل جزئي مفتاح بمفتاح
                $merged = array_replace($out[$key], array_intersect_key($in[$key], $spec));
                $out[$key] = self::object($spec, $merged);
            } else {
                $out[$key] = self::value($spec, $in[$key]);
            }
        }
        if (array_key_exists('home', $in) && is_array($in['home'])) {
            $out['home'] = ['sections' => self::sections($in['home']['sections'] ?? [])];
        }
        if (array_key_exists('pages', $in)) {
            $out['pages'] = self::pages($in['pages']);
        }
        if (array_key_exists('posts', $in)) {
            $out['posts'] = self::posts($in['posts']);
        }
        $out = array_intersect_key($out, TenantSiteTemplates::emptySettings());
        if (strlen((string) json_encode($out, JSON_UNESCAPED_UNICODE)) > (int) config('tenancy.site.max_bytes', 32768)) {
            self::fail('The site settings are too large. Remove some content and try again.', 413);
        }
        return $out;
    }

    /** إعدادات سليمة من الصفر (قالب/نسخة قديمة): بتتفحص كلها وبتتكمّل بالافتراضي. */
    public static function full(array $in): array
    {
        return self::settings($in, TenantSiteTemplates::emptySettings());
    }
}
