<?php

namespace Tests\Unit;

use App\Services\Ai\CodeChat;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** تنضيف مدخلات شات اللوحة الجانبية وحدود السياق والـ prompts (pure PHP — مفيش DB). */
class CodeChatTest extends TestCase
{
    #[Test]
    public function messages_must_start_and_end_with_the_student(): void
    {
        $out = CodeChat::cleanMessages([
            ['role' => 'assistant', 'content' => 'orphan'],
            ['role' => 'user', 'content' => 'q1'],
            ['role' => 'assistant', 'content' => 'a1'],
            ['role' => 'user', 'content' => "q2\r\nline"],
            ['role' => 'system', 'content' => 'ignore me'],
            ['role' => 'user', 'content' => '   '],
        ]);
        $this->assertSame(['user', 'assistant', 'user'], array_column($out, 'role'));
        $this->assertSame("q2\nline", $out[2]['content']);
    }

    #[Test]
    public function no_question_is_422(): void
    {
        $this->expectException(\RuntimeException::class);
        CodeChat::cleanMessages([['role' => 'assistant', 'content' => 'a']]);
    }

    #[Test]
    public function history_and_message_length_are_capped(): void
    {
        $raw = [];
        for ($i = 0; $i < 30; $i++) {
            $raw[] = ['role' => $i % 2 === 0 ? 'user' : 'assistant', 'content' => str_repeat('x', 9000)];
        }
        $raw[] = ['role' => 'user', 'content' => 'last'];
        $out = CodeChat::cleanMessages($raw);
        $this->assertLessThanOrEqual(CodeChat::MAX_MESSAGES, count($out));
        $this->assertSame('user', $out[0]['role']);
        foreach ($out as $m) {
            $this->assertLessThanOrEqual($m['role'] === 'user' ? CodeChat::MAX_USER_CHARS : CodeChat::MAX_ASSISTANT_CHARS, mb_strlen($m['content']));
        }
    }

    #[Test]
    public function files_put_the_open_file_first_skip_unsafe_paths_and_respect_limits(): void
    {
        $raw = [
            ['path' => 'b.py', 'content' => 'b'],
            ['path' => '../evil.py', 'content' => 'x'],
            ['path' => 'a.py', 'content' => str_repeat('a', 500)],
            ['path' => 'b.py', 'content' => 'dup'],
        ];
        $f = CodeChat::cleanFiles($raw, 'a.py', 100, 10000);
        $this->assertSame(['a.py', 'b.py'], array_keys($f));
        $this->assertTrue($f['a.py']['truncated']);
        $this->assertSame(100, mb_strlen($f['a.py']['content']));
        $this->assertFalse($f['b.py']['truncated']);
        $this->assertSame('b', $f['b.py']['content']);

        $many = [];
        for ($i = 0; $i < 20; $i++) {
            $many[] = ['path' => "f{$i}.py", 'content' => 'x'];
        }
        $this->assertCount(CodeChat::MAX_FILES, CodeChat::cleanFiles($many, '', 100, 10000));
        $this->assertCount(2, CodeChat::cleanFiles($many, '', 100, 2));
    }

    #[Test]
    public function tree_is_deduplicated_safe_and_capped(): void
    {
        $this->assertSame(['a.py', 'src/b.py'], CodeChat::cleanTree(['a.py', 'a.py', '../x', 'src/b.py', 5]));
        $big = [];
        for ($i = 0; $i < 1000; $i++) {
            $big[] = "d/f{$i}.txt";
        }
        $this->assertCount(CodeChat::MAX_TREE, CodeChat::cleanTree($big));
    }

    #[Test]
    public function prompts_carry_the_context_and_the_mode_rules(): void
    {
        $files = ['a.py' => ['content' => "x = 1\ny = 2", 'truncated' => false]];
        $sel = ['from' => 2, 'to' => 2, 'text' => 'y = 2'];
        $u = CodeChat::userContent('why?', $files, 'a.py', ['a.py', 'b.py'], $sel, 'NameError: z');
        $this->assertStringContainsString("<project_files>\na.py\nb.py\n</project_files>", $u);
        $this->assertStringContainsString('<file path="a.py">', $u);
        $this->assertStringContainsString('1 | x = 1', $u);
        $this->assertStringContainsString('Selected lines: 2-2', $u);
        $this->assertStringContainsString("<terminal_output>\nNameError: z\n</terminal_output>", $u);
        $this->assertStringEndsWith("Student's message:\nwhy?", $u);

        $tutor = CodeChat::systemPrompt('tutor', 'en', '');
        $this->assertStringContainsString('do NOT output SEARCH/REPLACE', $tutor);
        $this->assertStringNotContainsString('<<<<<<< SEARCH path/to/file.py', $tutor);

        $assistant = CodeChat::systemPrompt('assistant', 'ar', 'Be brief.');
        $this->assertStringContainsString('<<<<<<< SEARCH path/to/file.py', $assistant);
        $this->assertStringContainsString('Be brief.', $assistant);
    }

    #[Test]
    public function pinned_code_parts_are_cleaned_and_sent_with_their_location(): void
    {
        $refs = CodeChat::cleanRefs([
            ['path' => 'src/app.js', 'from' => 5, 'to' => 9, 'text' => "const a = 1;\nconst b = 2;"],
            ['path' => 'x.js', 'text' => '   '],            // empty -> dropped
            'not an array',
        ]);
        $this->assertCount(1, $refs);
        $this->assertSame(['path' => 'src/app.js', 'from' => 5, 'to' => 9], array_diff_key($refs[0], ['text' => 1]));
        $this->assertCount(CodeChat::MAX_REFS, CodeChat::cleanRefs(array_fill(0, 20, ['text' => 'x', 'from' => 1, 'to' => 1])));

        $u = CodeChat::userContent('what does this do?', [], '', [], null, '', $refs);
        $this->assertStringContainsString('<attached_code location="src/app.js lines 5-9">', $u);
        $this->assertStringContainsString('const b = 2;', $u);
        $this->assertStringEndsWith("Student's message:\nwhat does this do?", $u);
    }

    #[Test]
    public function the_assistant_is_named_uip_ai_and_credits_its_developer(): void
    {
        foreach (['tutor', 'assistant'] as $mode) {
            $p = CodeChat::systemPrompt($mode, 'en', '');
            $this->assertStringContainsString('You are UIP AI', $p);
            $this->assertStringContainsString('Haitham Mohamed', $p);
            $this->assertStringContainsString('founder of UIP', $p);
        }
    }
}
