<?php

namespace Tests\Unit;

use App\Support\ContentLexicon;
use PHPUnit\Framework\TestCase;

class ContentLexiconTest extends TestCase
{
    /** @dataProvider blocked */
    public function test_blocks_explicit_text_and_evasions(string $text): void
    {
        $this->assertNotNull(ContentLexicon::check($text, false), $text);
    }

    public static function blocked(): array
    {
        return [['xnxx'], ['XNXX video'], ['p.o.r.n'], ['p o r n'], ['p0rn'], ['pornnnn'], ['freeporn site'], ['سـكـس'], ['فيديوهات سكس مصري'], ['نيك امك'],
            ['والسكس'], ['شرموطة'], ['sharmota'], ['kos omak'], ['sex videos'], ['ك س امك']];
    }

    /** @dataProvider clean */
    public function test_does_not_flag_normal_text(string $text): void
    {
        $this->assertNull(ContentLexicon::check($text, false), $text);
    }

    public static function clean(): array
    {
        return [['hello world'], ['Sussex county'], ['Essex'], ['popcorn'], ['top or none'], ['sex differences in mice'], ['كسكس لذيذ'], ['الكسر العشري'],
            ['جيسون بورن'], ['نيك قابلته امس'], ['خولة محمد'], ['محاضرة الجنس البشري'], ['class assignment'], ['magna cum laude'], ['Dickens novel'], ['analysis of data']];
    }

    public function test_strict_layer_blocks_suggestive_gif_searches_but_not_normal_ones(): void
    {
        $this->assertNotNull(ContentLexicon::check('sexy dance', true));
        $this->assertNotNull(ContentLexicon::check('والجنس', true));
        $this->assertNull(ContentLexicon::check('happy birthday', true));
        $this->assertNull(ContentLexicon::check('تصفيق', true));
        $this->assertNull(ContentLexicon::check('sexy dance', false)); // tier 2 مش بتتحجب في النصوص العادية
    }

    public function test_allow_list_and_extra_words(): void
    {
        $this->assertNull(ContentLexicon::check('ass', true, [], ['ass']));
        $this->assertSame('badword', ContentLexicon::check('a badword here', false, ['badword'])['term']);
    }
}
