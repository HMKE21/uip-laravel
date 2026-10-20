<?php

namespace Tests\Unit;

use App\Support\BilingualName;
use PHPUnit\Framework\TestCase;

class BilingualNameTest extends TestCase
{
    public function test_accepts_arabic_and_english_names(): void
    {
        $r = BilingualName::resolve('  أحمد   محمد ', 'Ahmed Mohamed', 'ar');
        $this->assertTrue($r['ok']);
        $this->assertSame('أحمد محمد', $r['name_ar']);
        $this->assertSame('Ahmed Mohamed', $r['name_en']);
        $this->assertSame('أحمد محمد', $r['full_name']);
        $this->assertSame('Ahmed Mohamed', BilingualName::resolve('أحمد', 'Ahmed', 'en')['full_name']);
    }

    public function test_both_names_are_required(): void
    {
        $r = BilingualName::resolve('', 'Ahmed', 'en');
        $this->assertFalse($r['ok']);
        $this->assertArrayHasKey('name_ar', $r['errors']);
        $this->assertArrayNotHasKey('name_en', $r['errors']);

        $r = BilingualName::resolve('أحمد', null, 'ar');
        $this->assertFalse($r['ok']);
        $this->assertArrayHasKey('name_en', $r['errors']);
    }

    public function test_names_must_be_in_the_right_script(): void
    {
        $r = BilingualName::resolve('Ahmed', 'أحمد', 'en');
        $this->assertSame(['name_ar', 'name_en'], array_keys($r['errors']));
        $this->assertFalse(BilingualName::resolve('جامعة القاهرة', 'جامعة القاهرة')['ok']);
    }

    public function test_pick_falls_back_to_the_other_language(): void
    {
        $this->assertSame('Ahmed', BilingualName::pick('', 'Ahmed', 'ar'));
        $this->assertSame('أحمد', BilingualName::pick('أحمد', '', 'en'));
        $this->assertSame('legacy', BilingualName::pick('', '', 'ar', 'legacy'));
    }

    public function test_legacy_single_name_is_mirrored(): void
    {
        $r = BilingualName::resolveOrLegacy('Old Name', null, null, 'en');
        $this->assertTrue($r['ok']);
        $this->assertSame('Old Name', $r['name_ar']);
        $this->assertSame('Old Name', $r['name_en']);
    }
}
