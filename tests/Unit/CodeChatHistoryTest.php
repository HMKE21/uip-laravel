<?php

namespace Tests\Unit;

use App\Services\Ai\CodeChatHistory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** تنضيف المحادثات المحفوظة (pure PHP — مفيش DB). */
class CodeChatHistoryTest extends TestCase
{
    #[Test]
    public function uuid_check(): void
    {
        $this->assertTrue(CodeChatHistory::isUuid('123e4567-e89b-12d3-a456-426614174000'));
        $this->assertFalse(CodeChatHistory::isUuid('../x'));
        $this->assertFalse(CodeChatHistory::isUuid(5));
    }

    #[Test]
    public function messages_are_cleaned_and_a_streaming_reply_becomes_stopped(): void
    {
        $out = CodeChatHistory::cleanMessages([
            ['id' => 'm1', 'role' => 'user', 'content' => "hello\r\nworld\x00"],
            ['id' => 'm2', 'role' => 'assistant', 'content' => 'partial', 'phase' => 'streaming', 'mode' => 'assistant', 'edits' => [
                ['id' => 'm2e0', 'path' => 'src/a.js', 'kind' => 'create', 'search' => '', 'replace' => 'x', 'start_line' => 1, 'end_line' => 1, 'state' => 'applied'],
                ['path' => '../evil', 'kind' => 'create', 'replace' => 'x'],
                ['path' => 'b.js', 'kind' => 'delete'],
            ]],
            ['role' => 'system', 'content' => 'ignored'],
            ['role' => 'assistant', 'content' => '', 'phase' => 'done'],
        ]);
        $this->assertCount(2, $out);
        $this->assertSame("hello\nworld", $out[0]['content']);
        $this->assertSame('stopped', $out[1]['phase']);
        $this->assertCount(1, $out[1]['edits']);
        $this->assertSame('applied', $out[1]['edits'][0]['state']);
    }

    #[Test]
    public function duplicate_ids_are_made_unique_and_empty_input_is_refused(): void
    {
        $out = CodeChatHistory::cleanMessages([['id' => 'a', 'role' => 'user', 'content' => '1'], ['id' => 'a', 'role' => 'user', 'content' => '2']]);
        $this->assertCount(2, array_unique(array_column($out, 'id')));
        $this->expectException(\RuntimeException::class);
        CodeChatHistory::cleanMessages([]);
    }

    #[Test]
    public function too_large_a_conversation_is_refused_with_413(): void
    {
        $msgs = [];
        for ($i = 0; $i < 120; $i++) {
            $msgs[] = ['role' => 'user', 'content' => str_repeat('ب', CodeChatHistory::MAX_CONTENT)];
        }
        try {
            CodeChatHistory::cleanMessages($msgs);
            $this->fail('must throw');
        } catch (\RuntimeException $e) {
            $this->assertSame(413, $e->getCode());
        }
    }

    #[Test]
    public function title_comes_from_the_first_question_and_is_trimmed(): void
    {
        $this->assertSame('اشرح الملف ده', CodeChatHistory::deriveTitle([['role' => 'user', 'content' => "  اشرح   الملف ده \n"]]));
        $this->assertSame(61, mb_strlen(CodeChatHistory::deriveTitle([['role' => 'user', 'content' => str_repeat('a', 200)]])));
        $this->assertSame('', CodeChatHistory::deriveTitle([]));
        $this->assertSame(CodeChatHistory::MAX_TITLE, mb_strlen(CodeChatHistory::cleanTitle(str_repeat('x', 500))));
    }
}
