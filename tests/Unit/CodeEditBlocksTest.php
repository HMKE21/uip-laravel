<?php

namespace Tests\Unit;

use App\Services\Ai\CodeEditBlocks;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** parser بلوكات SEARCH/REPLACE بتاعة مساعد الكود (pure PHP — مفيش DB). */
class CodeEditBlocksTest extends TestCase
{
    private string $code = "def add(a, b):\n    return a + c\n\nprint(add(1, 2))\n";

    private function block(string $search, string $replace): string
    {
        return "شرح\n\n<<<<<<< SEARCH\n{$search}\n=======\n{$replace}\n>>>>>>> REPLACE\n";
    }

    #[Test]
    public function valid_block_is_parsed_with_line_numbers_and_applies(): void
    {
        $edits = CodeEditBlocks::parse($this->block('    return a + c', '    return a + b'), $this->code);
        $this->assertCount(1, $edits);
        $this->assertSame(2, $edits[0]['start_line']);
        $this->assertSame(str_replace('a + c', 'a + b', $this->code), CodeEditBlocks::apply($this->code, $edits));
    }

    #[Test]
    public function hallucinated_or_ambiguous_search_is_dropped(): void
    {
        $this->assertSame([], CodeEditBlocks::parse($this->block('not in file', 'x'), $this->code));
        $this->assertSame([], CodeEditBlocks::parse($this->block('print', 'x'), "print(1)\nprint(2)"));
    }

    #[Test]
    public function empty_replace_deletes_and_visible_text_hides_blocks(): void
    {
        $text = "شرح\n\n<<<<<<< SEARCH\nprint(add(1, 2))\n=======\n>>>>>>> REPLACE\n";
        $edits = CodeEditBlocks::parse($text, $this->code);
        $this->assertCount(1, $edits);
        $this->assertSame('', $edits[0]['replace']);
        $this->assertSame('شرح', CodeEditBlocks::visibleText($text));
    }

    #[Test]
    public function crlf_input_is_handled(): void
    {
        $edits = CodeEditBlocks::parse(
            str_replace("\n", "\r\n", $this->block('    return a + c', '    return a + b')),
            str_replace("\n", "\r\n", $this->code)
        );
        $this->assertCount(1, $edits);
    }
}
