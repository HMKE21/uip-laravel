<?php

namespace Tests\Unit;

use App\Services\FileUploadService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** الملفات المرفوعة اللي السيرفر ينفّذها/يعرضها كصفحة لازم تتخزّن باسم مش قابل للتنفيذ. */
class UploadHardeningTest extends TestCase
{
    #[Test]
    public function executable_and_html_extensions_get_a_txt_suffix(): void
    {
        foreach (['php', 'PHP', 'phtml', 'phar', 'php8', 'html', 'htm', 'xhtml', 'shtml', 'pht'] as $ext) {
            $this->assertStringEndsWith('.txt', FileUploadService::storedExtension($ext), $ext);
        }
        $this->assertSame('php.txt', FileUploadService::storedExtension('PHP'));
    }

    #[Test]
    public function normal_extensions_are_untouched(): void
    {
        foreach (['pdf', 'docx', 'zip', 'rar', 'png', 'jpg', 'mp4', 'svg', 'js', 'py', 'csv'] as $ext) {
            $this->assertSame($ext, FileUploadService::storedExtension($ext));
        }
    }
}
