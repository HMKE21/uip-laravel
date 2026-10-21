<?php

namespace Tests\Unit;

use App\Services\Ai\CodeChatEdits;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** بلوكات التعديل المتعددة الملفات بتاعة شات اللوحة الجانبية (pure PHP — مفيش DB). */
class CodeChatEditsTest extends TestCase
{
    private array $files = [
        'app.py'    => "def add(a, b):\n    return a + c\n\nprint(add(1, 2))\n",
        'util/x.py' => "X = 1\nY = 2\n",
    ];

    private function block(string $path, string $search, string $replace): string
    {
        return "<<<<<<< SEARCH {$path}\n{$search}\n=======\n{$replace}\n>>>>>>> REPLACE\n";
    }

    private function create(string $path, string $content): string
    {
        return "<<<<<<< SEARCH {$path}\n=======\n{$content}\n>>>>>>> REPLACE\n";
    }

    #[Test]
    public function edits_in_several_files_are_validated_and_ordered_by_first_appearance(): void
    {
        $text = "I fixed two things.\n"
            . $this->block('util/x.py', 'Y = 2', 'Y = 3')
            . $this->block('app.py', '    return a + c', '    return a + b');
        $e = CodeChatEdits::parse($text, $this->files, array_keys($this->files));
        $this->assertCount(2, $e);
        $this->assertSame('util/x.py', $e[0]['path']);
        $this->assertSame('edit', $e[0]['kind']);
        $this->assertSame(2, $e[0]['start_line']);
        $this->assertSame('app.py', $e[1]['path']);
        $this->assertSame(2, $e[1]['start_line']);

        $out = CodeChatEdits::apply($this->files, $e);
        $this->assertSame("X = 1\nY = 3\n", $out['util/x.py']);
        $this->assertSame(str_replace('a + c', 'a + b', $this->files['app.py']), $out['app.py']);
    }

    #[Test]
    public function new_file_is_a_create_edit_with_the_whole_content(): void
    {
        $text = $this->create('tests/test_app.py', "def test_add():\n    assert True");
        $e = CodeChatEdits::parse($text, $this->files, ['app.py', 'util/x.py']);
        $this->assertCount(1, $e);
        $this->assertSame('create', $e[0]['kind']);
        $this->assertSame('tests/test_app.py', $e[0]['path']);
        $this->assertSame("def test_add():\n    assert True", $e[0]['replace']);
        $this->assertSame(['tests/test_app.py' => "def test_add():\n    assert True"], CodeChatEdits::apply($this->files, $e));
    }

    #[Test]
    public function creating_a_file_that_exists_in_the_project_is_dropped(): void
    {
        $this->assertSame([], CodeChatEdits::parse($this->create('app.py', 'x'), $this->files, ['app.py']));
        // ملف موجود في المشروع بس ما اتبعتش كسياق
        $this->assertSame([], CodeChatEdits::parse($this->create('other.py', 'x'), $this->files, ['app.py', 'other.py']));
    }

    #[Test]
    public function edit_of_a_file_that_was_not_sent_is_dropped(): void
    {
        $this->assertSame([], CodeChatEdits::parse($this->block('other.py', 'a', 'b'), $this->files, ['app.py', 'other.py']));
    }

    #[Test]
    public function hallucinated_ambiguous_identical_and_overlapping_blocks_are_dropped(): void
    {
        $this->assertSame([], CodeChatEdits::parse($this->block('app.py', 'not in file', 'x'), $this->files));
        $this->assertSame([], CodeChatEdits::parse($this->block('app.py', 'return', 'x'), ['app.py' => "return 1\nreturn 2\n"]));
        $this->assertSame([], CodeChatEdits::parse($this->block('app.py', 'X = 1', 'X = 1'), ['app.py' => "X = 1\n"]));
        $text = $this->block('app.py', "def add(a, b):\n    return a + c", 'one')
            . $this->block('app.py', '    return a + c', 'two');
        $this->assertCount(1, CodeChatEdits::parse($text, $this->files));
    }

    #[Test]
    public function unsafe_paths_are_dropped(): void
    {
        foreach (['../x.py', '/etc/passwd', 'a/../b.py', 'a\\b.py', 'dir/', "a\x07b.py"] as $bad) {
            $this->assertNull(CodeChatEdits::safePath($bad), $bad);
            $this->assertSame([], CodeChatEdits::parse($this->create($bad, 'x'), [], []), $bad);
        }
        $this->assertSame('src/app.py', CodeChatEdits::safePath('./src/app.py'));
    }

    #[Test]
    public function an_unfinished_block_and_a_block_cut_by_a_new_one_are_ignored(): void
    {
        $text = "<<<<<<< SEARCH app.py\n    return a + c\n=======\n    return a + b\n"; // الرد اتقطع قبل >>>>>>> REPLACE
        $this->assertSame([], CodeChatEdits::parse($text, $this->files));

        $text = "<<<<<<< SEARCH app.py\n    return a + c\n"
            . $this->block('util/x.py', 'Y = 2', 'Y = 3');
        $e = CodeChatEdits::parse($text, $this->files);
        $this->assertCount(1, $e);
        $this->assertSame('util/x.py', $e[0]['path']);
    }

    #[Test]
    public function crlf_input_is_handled_and_blocks_are_capped(): void
    {
        $e = CodeChatEdits::parse(
            str_replace("\n", "\r\n", $this->block('app.py', '    return a + c', '    return a + b')),
            ['app.py' => str_replace("\n", "\r\n", $this->files['app.py'])]
        );
        $this->assertCount(1, $e);

        $many = '';
        for ($i = 0; $i < 20; $i++) {
            $many .= $this->create("new/f{$i}.txt", "x{$i}");
        }
        $this->assertCount(CodeChatEdits::MAX_EDITS, CodeChatEdits::parse($many, [], []));
    }
}
