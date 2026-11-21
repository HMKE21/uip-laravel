<?php

namespace Tests\Feature;

use App\Services\Tenancy\TenantAddress;
use App\Services\Tenancy\TenantService;
use App\Services\Tenancy\TenantSiteSanitizer;
use App\Services\Tenancy\TenantSiteTemplates;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** موقع العميل (قوالب/تخصيص/رابط جاهز) — اختبارات بدون DB: الـ sanitizer، القوالب الجاهزة، الـ slug، والصلاحيات. */
class DevTenantSiteTest extends TestCase
{
    #[Test]
    public function every_builtin_template_is_valid_stable_and_small(): void
    {
        $this->assertNotEmpty(TenantSiteTemplates::builtin());
        $types = array_keys(TenantSiteTemplates::sectionSpecs());
        foreach (TenantSiteTemplates::builtin() as $key => $tpl) {
            $once = TenantSiteSanitizer::full($tpl['settings']);
            $this->assertSame($once, TenantSiteSanitizer::full($once), "{$key} must be stable under sanitising");
            foreach ($once['home']['sections'] as $s) {
                $this->assertContains($s['type'], $types, $key);
            }
            $this->assertLessThan((int) config('tenancy.site.max_bytes'), strlen((string) json_encode($once, JSON_UNESCAPED_UNICODE)), $key);
            $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', (string) $once['theme']['primary'], $key);
            $this->assertSame($key, $tpl['key']);
        }
    }

    #[Test]
    public function text_loses_html_and_control_characters_and_is_truncated(): void
    {
        $this->assertSame('alert(1)Hi', TenantSiteSanitizer::text('<script>alert(1)</script><b>Hi</b>', 50));
        $this->assertSame('ab', TenantSiteSanitizer::text("a\x00b", 50));
        $this->assertSame(5, mb_strlen(TenantSiteSanitizer::text(str_repeat('ع', 20), 5)));
    }

    #[Test]
    public function only_https_links_and_internal_paths_survive(): void
    {
        $this->assertSame('https://example.com/a?b=1', TenantSiteSanitizer::link('https://example.com/a?b=1'));
        $this->assertSame('/login', TenantSiteSanitizer::link('/login'));
        foreach (['javascript:alert(1)', 'http://example.com', '//evil.com', 'data:text/html,hi', 'https://', '/a b', "https://x.com/\"onload=1", '', 'ftp://x.com'] as $bad) {
            $this->assertNull(TenantSiteSanitizer::link($bad), $bad);
        }
        $this->assertNull(TenantSiteSanitizer::img('/logo.png'), 'images must be https');
        $this->assertSame('https://cdn.example.com/l.png', TenantSiteSanitizer::img('https://cdn.example.com/l.png'));
    }

    #[Test]
    public function colors_and_enums_fall_back_safely(): void
    {
        $this->assertSame('#1A73E8', TenantSiteSanitizer::color('#1a73e8'));
        $this->assertNull(TenantSiteSanitizer::color('red'));
        $this->assertNull(TenantSiteSanitizer::color('#12345'));
        $s = TenantSiteSanitizer::settings(['theme' => ['font' => 'comic-sans', 'radius' => 'huge', 'primary' => 'url(x)']], TenantSiteTemplates::emptySettings());
        $this->assertSame('system', $s['theme']['font']);
        $this->assertSame('none', $s['theme']['radius']);   // أول قيمة في الـ enum عند القيمة الغلط
        $this->assertNull($s['theme']['primary']);
    }

    #[Test]
    public function a_partial_update_keeps_everything_else(): void
    {
        $base = TenantSiteSanitizer::full(TenantSiteTemplates::find('campus')['settings']);
        $new = TenantSiteSanitizer::settings(['theme' => ['primary' => '#ff0000']], $base);
        $this->assertSame('#FF0000', $new['theme']['primary']);
        $this->assertSame($base['theme']['accent'], $new['theme']['accent']);
        $this->assertSame($base['home'], $new['home']);
        $this->assertSame($base['layout'], $new['layout']);
    }

    #[Test]
    public function sections_are_filtered_limited_and_given_unique_ids(): void
    {
        config(['tenancy.site.max_sections' => 3]);
        $out = TenantSiteSanitizer::sections([
            ['id' => 'a', 'type' => 'hero', 'props' => ['title' => ['en' => '<i>Hello</i>', 'ar' => 'مرحبا'], 'cta_url' => 'javascript:x']],
            ['id' => 'a', 'type' => 'text', 'enabled' => false, 'props' => ['body' => ['en' => 'x']]],
            ['id' => 'z', 'type' => 'evil-widget', 'props' => []],
            ['type' => 'faq', 'props' => ['items' => array_fill(0, 30, ['q' => ['en' => 'q'], 'a' => ['en' => 'a']])]],
            ['type' => 'cta', 'props' => []],
        ]);
        $this->assertCount(3, $out);
        $this->assertSame('Hello', $out[0]['props']['title']['en']);
        $this->assertNull($out[0]['props']['cta_url']);
        $this->assertNotSame($out[0]['id'], $out[1]['id']);
        $this->assertFalse($out[1]['enabled']);
        $this->assertCount(10, $out[2]['props']['items']);
    }

    #[Test]
    public function oversized_settings_are_rejected(): void
    {
        config(['tenancy.site.max_bytes' => 2000]);
        try {
            TenantSiteSanitizer::settings(['home' => ['sections' => [['type' => 'text', 'props' => ['body' => ['en' => str_repeat('a', 1500), 'ar' => str_repeat('ب', 1500)]]]]]], TenantSiteTemplates::emptySettings());
            $this->fail('should be too large');
        } catch (\DomainException $e) {
            $this->assertSame(413, $e->getCode());
        }
    }

    #[Test]
    public function addresses_accept_numbers_and_reject_reserved_or_malformed_slugs(): void
    {
        $this->assertSame('12345', TenantAddress::normalize(' 12345 '));
        $this->assertSame('acme-uni', TenantAddress::normalize('Acme-Uni'));
        foreach (['admin', 'api', 'ab', '-abc', 'abc-', 'a--b', 'has space', 'under_score', 'نقطة', str_repeat('a', 41), 'w'] as $bad) {
            try {
                TenantAddress::normalize($bad);
                $this->fail("'{$bad}' should be rejected");
            } catch (\DomainException $e) {
                $this->assertSame(422, $e->getCode(), $bad);
            }
        }
    }

    #[Test]
    public function the_ready_made_link_uses_the_platform_origin_and_can_be_switched_off(): void
    {
        config(['tenancy.address.enabled' => true, 'tenancy.address.base_url' => 'https://uip-nub.vercel.app/', 'tenancy.address.path_prefix' => 'w']);
        $this->assertSame('https://uip-nub.vercel.app/w/acme', TenantAddress::url('acme'));
        $p = TenantAddress::payload((object) ['slug' => '1001']);
        $this->assertTrue($p['is_numeric']);
        $this->assertSame('https://uip-nub.vercel.app/w/1001/login', $p['login_url']);
        config(['tenancy.address.enabled' => false]);
        $this->assertNull(TenantAddress::url('acme'));
    }

    #[Test]
    public function only_owner_and_admin_can_edit_the_site_but_any_member_can_view(): void
    {
        $can = TenantService::ABILITIES;
        $this->assertSame(['owner', 'admin'], $can['site']);
        foreach (['viewer', 'developer'] as $role) {
            $this->assertNotContains($role, $can['site']);
        }
        $this->assertContains('viewer', $can['view']);
    }
}
