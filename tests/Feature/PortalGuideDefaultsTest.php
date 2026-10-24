<?php

namespace Tests\Feature;

use App\Support\PortalGuideContent;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * المحتوى الافتراضي لصفحات الدليل (database/data/guides/*.php) لازم يعدّي نفس الفحص اللي الأدمن بيعدّي عليه،
 * من غير مفاتيح مكررة. بدون داتابيز.
 */
class PortalGuideDefaultsTest extends TestCase
{
    #[Test]
    public function every_portal_has_default_content_that_passes_validation(): void
    {
        foreach (config('guides.portals') as $portal) {
            $path = database_path("data/guides/{$portal}.php");
            $this->assertFileExists($path, $portal);
            $def = require $path;

            [, $errors] = PortalGuideContent::guideMeta($def, false);
            $this->assertSame([], $errors, "$portal meta");

            $sectionKeys = [];
            $itemKeys = [];
            foreach ($def['sections'] as $s) {
                [, $errors] = PortalGuideContent::section($s, false);
                $this->assertSame([], $errors, "$portal section {$s['key']}");
                $this->assertArrayNotHasKey($s['key'], $sectionKeys, "$portal duplicate section {$s['key']}");
                $sectionKeys[$s['key']] = true;

                foreach ($s['items'] as $i) {
                    [, $errors] = PortalGuideContent::item($i, false);
                    $this->assertSame([], $errors, "$portal item {$i['key']}");
                    $this->assertArrayNotHasKey($i['key'], $itemKeys, "$portal duplicate item {$i['key']}");
                    $itemKeys[$i['key']] = true;
                }
            }
        }
    }

    #[Test]
    public function content_cleaner_strips_html_and_rejects_external_routes(): void
    {
        $this->assertSame('hi', PortalGuideContent::cleanText('<b>hi</b>'));
        $this->assertNull(PortalGuideContent::route('javascript:alert(1)'));
        $this->assertNull(PortalGuideContent::route('//evil.example'));
        $this->assertNull(PortalGuideContent::route('https://evil.example'));
        $this->assertSame('/student/projects', PortalGuideContent::route('/student/projects'));

        [$data, $errors] = PortalGuideContent::item(['key' => 'x-y', 'title_ar' => 'عنوان'], false);
        $this->assertSame([], $errors);
        $this->assertSame('عنوان', $data['title_en']); // اللغة الناقصة بتتنسخ
    }
}
