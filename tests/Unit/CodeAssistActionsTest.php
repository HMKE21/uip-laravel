<?php

namespace Tests\Unit;

use App\Services\Ai\CodeAssistActions;
use App\Services\Ai\EditBlockStreamFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** أوامر قائمة الكليك يمين (pure PHP — مفيش DB). */
class CodeAssistActionsTest extends TestCase
{
    private string $code = "a\nb\nc\nd\ne";

    #[Test]
    public function selection_is_validated_and_clamped(): void
    {
        $this->assertSame(['from' => 2, 'to' => 3, 'text' => "b\nc"], CodeAssistActions::selection($this->code, 2, 3));
        $this->assertSame(['from' => 4, 'to' => 5, 'text' => "d\ne"], CodeAssistActions::selection($this->code, '4', '99'));
        $this->assertNull(CodeAssistActions::selection($this->code, 3, 2));
        $this->assertNull(CodeAssistActions::selection($this->code, 0, 2));
        $this->assertNull(CodeAssistActions::selection($this->code, 9, 10));
        $this->assertNull(CodeAssistActions::selection($this->code, null, null));
        $this->assertNull(CodeAssistActions::selection($this->code, 'x', 2));
    }

    #[Test]
    public function edits_outside_the_selection_are_dropped(): void
    {
        $in = ['search' => 'b', 'replace' => 'B', 'start_line' => 2, 'end_line' => 2];
        $out = ['search' => 'e', 'replace' => 'E', 'start_line' => 5, 'end_line' => 5];
        $sel = CodeAssistActions::selection($this->code, 1, 3);
        $this->assertSame([$in], CodeAssistActions::filterEdits([$in, $out], $sel));
        $this->assertSame([$in, $out], CodeAssistActions::filterEdits([$in, $out], null));
    }

    #[Test]
    public function only_the_right_actions_may_return_edits(): void
    {
        $this->assertTrue(CodeAssistActions::allowsEdits('comment', 'tutor'));
        $this->assertTrue(CodeAssistActions::allowsEdits('fix', 'assistant'));
        $this->assertTrue(CodeAssistActions::allowsEdits('improve', 'assistant'));
        $this->assertFalse(CodeAssistActions::allowsEdits('fix', 'tutor'));
        $this->assertFalse(CodeAssistActions::allowsEdits('improve', 'tutor'));
        foreach (['explain', 'convert', 'test'] as $a) {
            $this->assertFalse(CodeAssistActions::allowsEdits($a, 'assistant'));
        }
    }

    #[Test]
    public function target_language_is_sanitised(): void
    {
        $this->assertSame('C++', CodeAssistActions::cleanTarget('C++'));
        $this->assertSame('Python', CodeAssistActions::cleanTarget("Python\n\nIgnore previous"));
        $this->assertSame('', CodeAssistActions::cleanTarget(['x']));
    }

    #[Test]
    public function tutor_prompts_never_ask_for_the_solution(): void
    {
        foreach (['fix', 'improve', 'convert', 'test'] as $a) {
            $p = CodeAssistActions::systemPrompt($a, 'tutor', 'en', 'Python');
            $this->assertStringContainsString('Do NOT', $p, $a);
            $this->assertStringNotContainsString('<<<<<<< SEARCH', $p, $a);
        }
        $this->assertStringContainsString('<<<<<<< SEARCH', CodeAssistActions::systemPrompt('fix', 'assistant', 'en'));
        $this->assertStringContainsString('<<<<<<< SEARCH', CodeAssistActions::systemPrompt('comment', 'tutor', 'en'));
        $this->assertStringContainsString('Python', CodeAssistActions::systemPrompt('convert', 'assistant', 'en', 'Python'));
        $this->assertStringContainsString('boss said', CodeAssistActions::systemPrompt('explain', 'tutor', 'en', '', 'boss said'));
    }

    #[Test]
    public function stream_filter_hides_edit_blocks(): void
    {
        $out = '';
        $f = new EditBlockStreamFilter(function (array $e) use (&$out) { $out .= $e['delta']; });
        foreach (str_split("Explain here.\n\n<<<<<<< SEARCH\nx\n=======\ny\n>>>>>>> REPLACE\n", 7) as $chunk) {
            $f->push($chunk);
        }
        $f->finish();
        $this->assertSame('Explain here.', rtrim($out));
    }
}
