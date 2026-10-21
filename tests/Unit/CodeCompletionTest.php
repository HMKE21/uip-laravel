<?php

namespace Tests\Unit;

use App\Services\Ai\CodeCompletion;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** تنضيف ردود الإكمال التلقائي (pure PHP — مفيش DB). */
class CodeCompletionTest extends TestCase
{
    #[Test]
    public function fences_and_cursor_markers_are_removed(): void
    {
        $this->assertSame('b)', CodeCompletion::clean("```python\nb)\n```", 'print(a, ', ''));
        $this->assertSame('b)', CodeCompletion::clean("```\nb)", 'print(a, ', ''));
        $this->assertSame('b)', CodeCompletion::clean('b)<cursor>', 'print(a, ', ''));
    }

    #[Test]
    public function echoed_last_line_is_removed(): void
    {
        $this->assertSame('b)', CodeCompletion::clean('print(a, b)', 'print(a, ', ''));
        $this->assertSame('return x', CodeCompletion::clean('    return x', "def f(x):\n    ", ''));
        // سطر قصير جدًا ماينفعش يتعامل كـ echo
        $this->assertSame('ab', CodeCompletion::clean('ab', "x = 1\na", ''));
    }

    #[Test]
    public function long_answers_are_cut_by_lines_and_chars(): void
    {
        $raw = implode("\n", array_map(fn ($i) => "line$i", range(1, 20)));
        $this->assertSame("line1\nline2\nline3", CodeCompletion::clean($raw, 'x', '', 3));
        $long = "ok\n" . str_repeat('y', 700);
        $this->assertSame('ok', CodeCompletion::clean($long, 'x', '', 8, 100));
    }

    #[Test]
    public function empty_or_duplicate_of_suffix_gives_nothing(): void
    {
        $this->assertSame('', CodeCompletion::clean("  \n ", 'x', ''));
        $this->assertSame('', CodeCompletion::clean(')', 'print(1', ")\n"));
        $this->assertSame('', CodeCompletion::clean('```', 'x', ''));
        $this->assertSame('', CodeCompletion::clean("```python\n", 'x', ''));
    }

    #[Test]
    public function context_is_clipped_and_tutor_is_stricter(): void
    {
        [$p, $s] = CodeCompletion::clip(str_repeat('a', 5000) . 'END', str_repeat('b', 2000));
        $this->assertSame(CodeCompletion::MAX_PREFIX, mb_strlen($p));
        $this->assertSame('END', mb_substr($p, -3));
        $this->assertSame(CodeCompletion::MAX_SUFFIX, mb_strlen($s));
        $this->assertTrue(CodeCompletion::maxLines('tutor') < CodeCompletion::maxLines('assistant'));
        $this->assertStringContainsString('Do NOT write a whole function', CodeCompletion::systemPrompt('tutor'));
    }
}
