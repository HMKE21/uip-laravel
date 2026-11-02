<?php

namespace Tests\Unit;

use App\Support\LibraryStateSanitizer as S;
use PHPUnit\Framework\TestCase;

/** تنضيف تفضيلات "مكتبتي" قبل التخزين. (pure: من غير DB.) */
class LibraryStateSanitizerTest extends TestCase
{
    public function test_marks_are_typed_deduped_and_capped(): void
    {
        $m = S::marks([
            'fav' => [3, '4', 3, -1, 'x', 0, 5.5],
            'tags' => ['7' => ['a', 'a', ' b ', '', str_repeat('z', 40), 'c', 'd', 'e', 'f'], 'bad' => ['x'], '8' => 'nope'],
            'shared' => [['token' => 'abcdefgh1234', 'name' => 'N', 'x' => ['nested']], ['token' => 'short'], 'junk'],
        ]);
        $this->assertSame([3, 4], $m['fav']);
        $this->assertSame(['a', 'b', str_repeat('z', 24), 'c', 'd', 'e'], $m['tags']['7']);
        $this->assertArrayNotHasKey('bad', $m['tags']);
        $this->assertArrayNotHasKey('8', $m['tags']);
        $this->assertCount(1, $m['shared']);
        $this->assertSame(['token' => 'abcdefgh1234', 'name' => 'N'], $m['shared'][0]);
    }

    public function test_library_defaults_and_smart_folders(): void
    {
        $l = S::library(['source' => 'weird', 'view' => 'list', 'hidden' => [1, 1, 2], 'smart' => [
            ['id' => 'a1', 'name' => 'Big videos', 'rules' => ['kinds' => ['video', 'nope'], 'period' => 'bogus', 'min_mb' => '50', 'max_mb' => -3, 'fav' => 1]],
            ['id' => 'a1', 'name' => 'dup'], ['id' => 'bad id!', 'name' => 'x'], ['id' => 'b2', 'name' => '  '],
        ]]);
        $this->assertSame('all', $l['source']);
        $this->assertSame('list', $l['view']);
        $this->assertSame([1, 2], $l['hidden']);
        $this->assertCount(1, $l['smart']);
        $r = $l['smart'][0]['rules'];
        $this->assertSame(['video'], $r['kinds']);
        $this->assertSame('any', $r['period']);
        $this->assertSame(50.0, $r['min_mb']);
        $this->assertNull($r['max_mb']);
        $this->assertTrue($r['fav']);
    }

    public function test_garbage_input_gives_empty_state(): void
    {
        $this->assertSame(['fav' => [], 'tags' => [], 'shared' => []], S::marks('x'));
        $this->assertSame(['hidden' => [], 'source' => 'all', 'view' => 'grid', 'smart' => []], S::library(null));
    }
}
