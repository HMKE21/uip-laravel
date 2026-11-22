<?php

namespace Tests\Feature;

use App\Services\Tenancy\TenantDomainAutomation;
use App\Services\Tenancy\TenantDomainService;
use App\Services\Tenancy\TenantSiteMessages;
use App\Services\Tenancy\TenantSiteSanitizer;
use App\Services\Tenancy\TenantSiteSeo;
use App\Services\Tenancy\TenantSiteTemplates;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** موقع العميل — الخطوة 5 (صفحات/مدونة/SEO/خطوط/نموذج تواصل/دومين تلقائي). من غير DB: منطق صافي بس. */
class DevTenantSiteStep5Test extends TestCase
{
    private function tenant(): object
    {
        return (object) ['id' => 1, 'slug' => 'acme', 'name' => 'Acme', 'public_id' => 't_1'];
    }

    private function settings(array $over = []): array
    {
        return TenantSiteSanitizer::settings($over, TenantSiteSanitizer::full([]));
    }

    #[Test]
    public function fonts_are_an_allow_list_with_separate_heading_font_and_scale(): void
    {
        $this->assertSame(array_keys(TenantSiteTemplates::FONT_META), TenantSiteTemplates::FONTS);
        $s = $this->settings(['theme' => ['font' => 'cairo', 'font_heading' => 'playfair-display', 'font_scale' => 'lg', 'heading_weight' => 'extrabold']]);
        $this->assertSame('cairo', $s['theme']['font']);
        $this->assertSame('playfair-display', $s['theme']['font_heading']);
        $this->assertSame('lg', $s['theme']['font_scale']);
        $bad = $this->settings(['theme' => ['font' => 'comic-sans', 'font_heading' => 'x', 'font_scale' => '9', 'heading_weight' => '900']]);
        $this->assertSame('system', $bad['theme']['font']);
        $this->assertSame('same', $bad['theme']['font_heading']);
        $this->assertSame('md', $bad['theme']['font_scale']);
        $this->assertSame('bold', $bad['theme']['heading_weight']);
        $this->assertSame('same', TenantSiteSanitizer::full([])['theme']['font_heading']);
    }

    #[Test]
    public function pages_get_unique_safe_slugs_and_reserved_ones_are_avoided(): void
    {
        $out = TenantSiteSanitizer::pages([
            ['title' => ['en' => 'About us'], 'kind' => 'about'],
            ['slug' => 'about', 'title' => ['en' => 'About again']],
            ['slug' => 'login', 'title' => ['en' => 'Login page']],
            ['slug' => 'blog', 'title' => ['en' => 'Blog']],
            ['slug' => 'ÙÚ', 'title' => ['ar' => 'عربي'], 'kind' => 'evil'],
            'not-an-array',
        ]);
        $slugs = array_column($out, 'slug');
        $this->assertSame(array_values(array_unique($slugs)), $slugs, 'slugs must be unique');
        $this->assertSame('about', $slugs[0]);
        foreach ($slugs as $sl) {
            $this->assertMatchesRegularExpression('/^[a-z0-9-]{1,40}$/', $sl);
            $this->assertNotContains($sl, ['login', 'blog']);
        }
        $this->assertTrue($out[0]['enabled']);
        $this->assertTrue($out[0]['show_in_nav']);
        $this->assertSame('custom', $out[4]['kind']);
    }

    #[Test]
    public function pages_are_limited_and_their_sections_are_filtered(): void
    {
        config(['tenancy.site.max_pages' => 2, 'tenancy.site.max_page_sections' => 1]);
        $out = TenantSiteSanitizer::pages(array_fill(0, 6, ['title' => ['en' => 'P'], 'sections' => [
            ['type' => 'text', 'props' => ['body' => ['en' => '<b>x</b>']]], ['type' => 'hero', 'props' => []], ['type' => 'evil', 'props' => []],
        ]]));
        $this->assertCount(2, $out);
        $this->assertCount(1, $out[0]['sections']);
        $this->assertSame('x', $out[0]['sections'][0]['props']['body']['en']);
    }

    #[Test]
    public function posts_have_valid_dates_unique_slugs_and_start_as_drafts(): void
    {
        $out = TenantSiteSanitizer::posts([
            ['title' => ['en' => 'Hello World'], 'date' => '2026-02-30', 'cover' => 'javascript:x', 'body' => ['en' => '<script>a</script>text']],
            ['title' => ['en' => 'Hello World'], 'date' => '2026-02-10', 'published' => true],
        ]);
        $this->assertSame('hello-world', $out[0]['slug']);
        $this->assertSame('hello-world-2', $out[1]['slug']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $out[0]['date']);
        $this->assertNotSame('2026-02-30', $out[0]['date']);
        $this->assertSame('2026-02-10', $out[1]['date']);
        $this->assertFalse($out[0]['published']);
        $this->assertTrue($out[1]['published']);
        $this->assertNull($out[0]['cover']);
        $this->assertSame('atext', $out[0]['body']['en']);
        config(['tenancy.site.max_posts' => 1]);
        $this->assertCount(1, TenantSiteSanitizer::posts([['title' => ['en' => 'a']], ['title' => ['en' => 'b']]]));
    }

    #[Test]
    public function seo_fields_are_trimmed_and_the_share_image_must_be_https(): void
    {
        $s = $this->settings(['seo' => ['title' => ['en' => str_repeat('a', 100)], 'description' => ['en' => str_repeat('b', 300)], 'share_image' => 'http://x.com/a.png', 'robots' => 'maybe']]);
        $this->assertSame(70, mb_strlen($s['seo']['title']['en']));
        $this->assertSame(160, mb_strlen($s['seo']['description']['en']));
        $this->assertNull($s['seo']['share_image']);
        $this->assertSame('index', $s['seo']['robots']);
        $s = $this->settings(['seo' => ['share_image' => 'https://cdn.example.com/og.png', 'robots' => 'noindex']]);
        $this->assertSame('noindex', $s['seo']['robots']);
    }

    #[Test]
    public function every_builtin_template_still_round_trips_with_pages_and_new_templates_exist(): void
    {
        foreach (['startup', 'academy', 'elegant'] as $k) {
            $this->assertNotNull(TenantSiteTemplates::find($k), $k);
        }
        foreach (TenantSiteTemplates::builtin() as $key => $tpl) {
            $once = TenantSiteSanitizer::full($tpl['settings']);
            $this->assertSame($once, TenantSiteSanitizer::full($once), "{$key} must be stable");
            $slugs = array_column($once['pages'], 'slug');
            $this->assertSame(array_values(array_unique($slugs)), $slugs, $key);
        }
        $this->assertNotEmpty(TenantSiteSanitizer::full(TenantSiteTemplates::find('business')['settings'])['pages']);
    }

    #[Test]
    public function seo_resolves_titles_descriptions_and_robots_per_path(): void
    {
        config(['tenancy.address.enabled' => true, 'tenancy.address.base_url' => 'https://app.example.com', 'tenancy.address.path_prefix' => 'w']);
        $s = $this->settings([
            'seo' => ['title' => ['en' => 'Acme Uni', 'ar' => 'جامعة أكمي'], 'description' => ['en' => 'Site desc'], 'share_image' => 'https://cdn.example.com/og.png'],
            'blog' => ['enabled' => true],
            'pages' => [['slug' => 'about', 'title' => ['en' => 'About', 'ar' => 'من نحن'], 'seo' => ['description' => ['en' => 'About desc']]]],
            'posts' => [['slug' => 'first', 'title' => ['en' => 'First post'], 'excerpt' => ['en' => 'Excerpt'], 'cover' => 'https://cdn.example.com/c.png', 'date' => '2026-03-01', 'published' => true],
                ['slug' => 'draft', 'title' => ['en' => 'Draft'], 'published' => false]],
        ]);
        $b = ['name' => 'Acme', 'logo_url' => 'https://cdn.example.com/logo.png', 'favicon_url' => null];
        $t = $this->tenant();

        $home = TenantSiteSeo::resolve($t, $s, $b, '/');
        $this->assertSame('Acme Uni', $home['title']['en']);
        $this->assertSame('جامعة أكمي', $home['title']['ar']);
        $this->assertSame('Site desc', $home['description']['en']);
        $this->assertSame('https://cdn.example.com/og.png', $home['image']);
        $this->assertSame('index,follow', $home['robots']);
        $this->assertSame('https://app.example.com/w/acme', $home['canonical']);

        $about = TenantSiteSeo::resolve($t, $s, $b, '/p/about');
        $this->assertSame('About | Acme Uni', $about['title']['en']);
        $this->assertSame('About desc', $about['description']['en']);
        $this->assertSame('https://app.example.com/w/acme/p/about', $about['canonical']);

        $post = TenantSiteSeo::resolve($t, $s, $b, '/blog/first');
        $this->assertSame('article', $post['type']);
        $this->assertSame('Excerpt', $post['description']['en']);
        $this->assertSame('https://cdn.example.com/c.png', $post['image']);
        $this->assertSame('2026-03-01', $post['published_time']);

        $this->assertFalse(TenantSiteSeo::resolve($t, $s, $b, '/blog/draft')['found']);
        $this->assertFalse(TenantSiteSeo::resolve($t, $s, $b, '/p/missing')['found']);
        $this->assertSame('noindex,nofollow', TenantSiteSeo::resolve($t, $s, $b, '/login')['robots']);
        $this->assertSame('noindex,nofollow', TenantSiteSeo::resolve($t, $s, $b, '/', true)['robots']);
        $this->assertNull(TenantSiteSeo::resolve($t, $s, $b, '/', true)['canonical']);
        $this->assertSame('/p/about', TenantSiteSeo::normalizePath('/w/acme/p/about?x=1'));
        $this->assertSame('/', TenantSiteSeo::normalizePath(''));
    }

    #[Test]
    public function sitemap_lists_only_public_pages_and_published_posts_and_respects_noindex(): void
    {
        config(['tenancy.address.enabled' => true, 'tenancy.address.base_url' => 'https://app.example.com']);
        $s = $this->settings([
            'blog' => ['enabled' => true],
            'pages' => [['slug' => 'about', 'title' => ['en' => 'About']], ['slug' => 'hidden', 'enabled' => false, 'title' => ['en' => 'Hidden']]],
            'posts' => [['slug' => 'live', 'published' => true, 'date' => '2026-01-05', 'title' => ['en' => 'Live']], ['slug' => 'wip', 'title' => ['en' => 'WIP']]],
        ]);
        $xml = TenantSiteSeo::sitemap($this->tenant(), $s);
        $this->assertStringContainsString('/w/acme/p/about', $xml);
        $this->assertStringContainsString('/w/acme/blog/live</loc><lastmod>2026-01-05', $xml);
        $this->assertStringNotContainsString('hidden', $xml);
        $this->assertStringNotContainsString('wip', $xml);
        $s['seo']['robots'] = 'noindex';
        $this->assertStringNotContainsString('<loc>', TenantSiteSeo::sitemap($this->tenant(), $s));
        $this->assertStringContainsString('Disallow: /', TenantSiteSeo::robots($this->tenant(), $s));
    }

    #[Test]
    public function the_contact_form_needs_an_enabled_contact_section_with_form_on(): void
    {
        $off = $this->settings(['home' => ['sections' => [['type' => 'contact', 'props' => ['form' => false]]]]]);
        $this->assertFalse(TenantSiteMessages::hasForm($off));
        $on = $this->settings(['home' => ['sections' => [['type' => 'contact', 'props' => ['form' => true]]]]]);
        $this->assertTrue(TenantSiteMessages::hasForm($on));
        $page = $this->settings(['pages' => [['slug' => 'contact', 'sections' => [['type' => 'contact', 'props' => ['form' => true]]]]]]);
        $this->assertTrue(TenantSiteMessages::hasForm($page));
        $off2 = $this->settings(['pages' => [['slug' => 'contact', 'enabled' => false, 'sections' => [['type' => 'contact', 'props' => ['form' => true]]]]]]);
        $this->assertFalse(TenantSiteMessages::hasForm($off2));
    }

    #[Test]
    public function contact_messages_are_validated_and_bots_are_ignored_before_touching_storage(): void
    {
        $on = $this->settings(['home' => ['sections' => [['type' => 'contact', 'props' => ['form' => true]]]]]);
        $t = $this->tenant();
        try {
            TenantSiteMessages::submit($t, $this->settings(), ['name' => 'Ali', 'email' => 'a@b.co', 'message' => 'hello there'], '1.1.1.1');
            $this->fail('no form => rejected');
        } catch (\DomainException $e) {
            $this->assertSame(404, $e->getCode());
        }
        $this->assertSame(['sent' => true], TenantSiteMessages::submit($t, $on, ['name' => 'Bot', 'email' => 'x@y.zz', 'message' => 'buy now', 'website' => 'http://spam'], '1.1.1.1'));
        foreach ([['name' => 'A', 'email' => 'a@b.co', 'message' => 'hello there'], ['name' => 'Ali', 'email' => 'nope', 'message' => 'hello there'], ['name' => 'Ali', 'email' => 'a@b.co', 'message' => 'hi']] as $bad) {
            try {
                TenantSiteMessages::submit($t, $on, $bad, '1.1.1.1');
                $this->fail('should be rejected: ' . json_encode($bad));
            } catch (\DomainException $e) {
                $this->assertSame(422, $e->getCode());
            }
        }
    }

    #[Test]
    public function domain_payload_reports_ssl_and_automatic_checking(): void
    {
        $d = (object) ['id' => 1, 'domain' => 'meet.acme.com', 'status' => 'pending', 'is_primary' => 0, 'verified_at' => null, 'activated_at' => null, 'last_checked_at' => null, 'last_error' => null,
            'verification_token' => 'tok', 'ssl_status' => 'pending', 'ssl_checked_at' => null, 'ssl_expires_at' => null, 'ssl_error' => null, 'next_check_at' => '2030-01-01 00:00:00'];
        $p = TenantDomainService::payload($d);
        $this->assertSame('pending', $p['ssl']['status']);
        $this->assertTrue($p['auto_check']);
        $this->assertSame('2030-01-01 00:00:00', $p['next_check_at']);
        $d->status = 'active';
        $this->assertFalse(TenantDomainService::payload($d)['auto_check']);
        unset($d->ssl_status);
        $this->assertSame('none', TenantDomainService::payload($d)['ssl']['status'], 'works before the migration too');
    }

    #[Test]
    public function dns_checks_slow_down_with_the_age_of_the_domain_and_the_tls_probe_is_replaceable(): void
    {
        $this->assertSame(2, TenantDomainAutomation::intervalMinutes(now()->subMinutes(5)));
        $this->assertSame(15, TenantDomainAutomation::intervalMinutes(now()->subHours(5)));
        $this->assertSame(60, TenantDomainAutomation::intervalMinutes(now()->subDays(3)));
        $this->assertSame(360, TenantDomainAutomation::intervalMinutes(now()->subDays(10)));
        $this->assertSame(1440, TenantDomainAutomation::intervalMinutes(now()->subDays(20)));
        TenantDomainAutomation::$tls = fn (string $d) => ['ok' => true, 'expires_at' => '2030-01-01 00:00:00'];
        try {
            $this->assertTrue(TenantDomainAutomation::probe('x.example.com')['ok']);
        } finally {
            TenantDomainAutomation::$tls = null;
        }
    }
}
