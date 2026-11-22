<?php

namespace App\Services\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * SEO موقع العميل (قراءة بس، من الإعدادات المنشورة أو المسودة في المعاينة):
 *  - resolve(): العنوان والوصف وصورة المشاركة والـ canonical والـ robots لمسار معيّن، بالعربي والإنجليزي.
 *  - sitemap() / robots(): XML ونص جاهزين. الفرونت أو الـ edge بيقدّمهم على /sitemap.xml و/robots.txt.
 * المسارات: / · /p/<slug> · /blog · /blog/<slug> · /login.
 * الأولوية للعنوان: SEO الصفحة/المقال ← SEO الموقع ← اسم الموقع. الصورة: الصفحة/المقال ← صورة مشاركة الموقع ← صورة الهيرو ← لوجو الهوية.
 */
final class TenantSiteSeo
{
    public static function normalizePath(string $path): string
    {
        $p = '/' . trim(strtok(trim($path), '?#') ?: '', '/');
        $p = preg_replace('~^/w/[a-z0-9-]+(?=/|$)~', '', $p) ?? $p;       // لو الفرونت بعت المسار كامل بالرابط الجاهز /w/<slug>/…
        return $p === '' ? '/' : $p;
    }

    /** أصل الموقع للـ canonical: دومين العميل الأساسي (active) لو موجود، وإلا الرابط الجاهز /w/<slug>. */
    public static function base(object $tenant): ?string
    {
        try {
            $domain = DB::table('tenant_domains')->where('tenant_id', $tenant->id)->where('status', 'active')->orderByDesc('is_primary')->orderBy('id')->value('domain');
        } catch (\Throwable $e) {
            $domain = null;
        }
        if ($domain) {
            return 'https://' . $domain;
        }
        return TenantAddress::url((string) $tenant->slug);
    }

    private static function pick($i18n, string $lang): string
    {
        $i18n = is_array($i18n) ? $i18n : [];
        $other = $lang === 'ar' ? 'en' : 'ar';
        return trim((string) ($i18n[$lang] ?? '')) !== '' ? trim((string) $i18n[$lang]) : trim((string) ($i18n[$other] ?? ''));
    }

    private static function firstHeroSubtitle(array $settings, string $lang): string
    {
        foreach ($settings['home']['sections'] ?? [] as $s) {
            if (($s['type'] ?? '') === 'hero' && ($s['enabled'] ?? true)) {
                $t = self::pick($s['props']['subtitle'] ?? [], $lang);
                if ($t !== '') {
                    return $t;
                }
            }
        }
        return '';
    }

    private static function findBySlug(array $items, string $slug): ?array
    {
        foreach ($items as $it) {
            if (($it['slug'] ?? null) === $slug) {
                return $it;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $settings إعدادات سليمة (full) @param array<string,mixed> $branding TenantBranding::publicPayload */
    public static function resolve(object $tenant, array $settings, array $branding, string $path, bool $preview = false): array
    {
        $path = self::normalizePath($path);
        $site = $settings['seo'] ?? [];
        $name = (string) ($branding['name'] ?? $tenant->name);
        $found = true;
        $type = 'website';
        $item = null;            // page أو post
        $own = [];               // title/description/image خاصة بالصفحة
        $pageTitle = ['en' => '', 'ar' => ''];
        $published = null;

        if ($path === '/') {
            // الرئيسية
        } elseif (preg_match('~^/p/([a-z0-9-]+)$~', $path, $m)) {
            $item = self::findBySlug($settings['pages'] ?? [], $m[1]);
            if (!$item || empty($item['enabled'])) {
                $found = false;
            } else {
                $pageTitle = $item['title'];
                $own = ['title' => $item['seo']['title'] ?? [], 'description' => $item['seo']['description'] ?? [], 'image' => $item['seo']['share_image'] ?? null];
            }
        } elseif ($path === '/blog') {
            if (empty($settings['blog']['enabled'])) {
                $found = false;
            } else {
                $pageTitle = $settings['blog']['title'];
                $own = ['description' => $settings['blog']['intro'] ?? []];
            }
        } elseif (preg_match('~^/blog/([a-z0-9-]+)$~', $path, $m)) {
            $item = self::findBySlug($settings['posts'] ?? [], $m[1]);
            if (empty($settings['blog']['enabled']) || !$item || empty($item['published'])) {
                $found = false;
            } else {
                $type = 'article';
                $pageTitle = $item['title'];
                $published = $item['date'];
                $seoDesc = $item['seo']['description'] ?? [];
                $hasSeoDesc = trim((string) ($seoDesc['en'] ?? '') . (string) ($seoDesc['ar'] ?? '')) !== '';
                $own = ['title' => $item['seo']['title'] ?? [], 'description' => $hasSeoDesc ? $seoDesc : ($item['excerpt'] ?? []), 'image' => ($item['seo']['share_image'] ?? null) ?: ($item['cover'] ?? null)];
            }
        } elseif ($path === '/login') {
            $pageTitle = $settings['texts']['login_title'] ?? ['en' => '', 'ar' => ''];
        } else {
            $found = false;
        }

        $title = [];
        $desc = [];
        foreach (['en', 'ar'] as $lang) {
            $siteTitle = self::pick($site['title'] ?? [], $lang) ?: $name;
            $own_t = self::pick($own['title'] ?? [], $lang);
            $page_t = self::pick($pageTitle, $lang);
            if ($path === '/') {
                $title[$lang] = $siteTitle;
            } else {
                $title[$lang] = $own_t !== '' ? $own_t : ($page_t !== '' ? $page_t . ' | ' . $siteTitle : $siteTitle);
            }
            $d = self::pick($own['description'] ?? [], $lang);
            $desc[$lang] = $d !== '' ? $d : (self::pick($site['description'] ?? [], $lang) ?: self::firstHeroSubtitle($settings, $lang));
            $title[$lang] = mb_substr($title[$lang], 0, 120);
            $desc[$lang] = mb_substr($desc[$lang], 0, 200);
        }

        $image = ($own['image'] ?? null) ?: ($site['share_image'] ?? null) ?: ($settings['layout']['hero_image_url'] ?? null) ?: ($branding['logo_url'] ?? null);
        $base = self::base($tenant);
        $noindex = $preview || !$found || ($site['robots'] ?? 'index') === 'noindex' || $path === '/login';

        return [
            'found' => $found, 'path' => $path, 'site_name' => $name, 'title' => $title, 'description' => $desc, 'image' => $image, 'type' => $type,
            'published_time' => $published, 'favicon' => ($site['favicon'] ?? null) ?: ($branding['favicon_url'] ?? null),
            'robots' => $noindex ? 'noindex,nofollow' : 'index,follow',
            'canonical' => ($base && !$preview && $found) ? rtrim($base, '/') . ($path === '/' ? '' : $path) : null,
        ];
    }

    public static function sitemap(object $tenant, array $settings): string
    {
        $base = self::base($tenant);
        $urls = [];
        if ($base && ($settings['seo']['robots'] ?? 'index') !== 'noindex') {
            $b = rtrim($base, '/');
            $urls[] = [$b . '/', null];
            foreach ($settings['pages'] ?? [] as $p) {
                if (!empty($p['enabled'])) {
                    $urls[] = [$b . '/p/' . $p['slug'], null];
                }
            }
            if (!empty($settings['blog']['enabled'])) {
                $urls[] = [$b . '/blog', null];
                foreach ($settings['posts'] ?? [] as $p) {
                    if (!empty($p['published'])) {
                        $urls[] = [$b . '/blog/' . $p['slug'], $p['date'] ?? null];
                    }
                }
            }
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as [$loc, $last]) {
            $xml .= '  <url><loc>' . htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>' . ($last ? '<lastmod>' . $last . '</lastmod>' : '') . "</url>\n";
        }
        return $xml . '</urlset>' . "\n";
    }

    public static function robots(object $tenant, array $settings): string
    {
        if (($settings['seo']['robots'] ?? 'index') === 'noindex') {
            return "User-agent: *\nDisallow: /\n";
        }
        $base = self::base($tenant);
        return "User-agent: *\nAllow: /\nDisallow: /login\n" . ($base ? 'Sitemap: ' . rtrim($base, '/') . "/sitemap.xml\n" : '');
    }
}
