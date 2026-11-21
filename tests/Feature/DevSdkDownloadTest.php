<?php

namespace Tests\Feature;

use App\Services\Developer\DevOpenApi;
use App\Services\Developer\Sdk\SdkGenerator;
use App\Services\Developer\Sdk\SdkPackager;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** تنزيل الـ SDK كـ zip — بدون DB: كل لغة في فولدر uipe-<lang>/ وفيها ملفاتها بس. */
class DevSdkDownloadTest extends TestCase
{
    private function files(): array
    {
        return SdkGenerator::generate(DevOpenApi::build(DevOpenApi::routesFromLaravel(), 'https://uipe.test'));
    }

    private function names(string $bin): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'chk');
        file_put_contents($tmp, $bin);
        $z = new \ZipArchive();
        $this->assertTrue($z->open($tmp) === true);
        $n = [];
        for ($i = 0; $i < $z->numFiles; $i++) {
            $n[] = $z->getNameIndex($i);
        }
        $z->close();
        @unlink($tmp);
        return $n;
    }

    #[Test]
    public function each_language_zip_has_its_own_folder_and_files_only(): void
    {
        $files = $this->files();
        $expect = ['php' => 'composer.json', 'js' => 'package.json', 'python' => 'pyproject.toml'];
        foreach ($expect as $lang => $root) {
            $names = $this->names(SdkPackager::zip($files, $lang));
            $this->assertContains('uipe-' . $lang . '/' . $root, $names, $lang);
            foreach ($names as $n) {
                $this->assertStringStartsWith('uipe-' . $lang . '/', $n);
            }
            $this->assertNotContains('uipe-' . $lang . '/openapi.json', $names);
        }
    }

    #[Test]
    public function an_unknown_language_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SdkPackager::zip($this->files(), 'ruby');
    }

    #[Test]
    public function the_download_route_is_not_documented_in_the_openapi_spec(): void
    {
        $spec = DevOpenApi::build(DevOpenApi::routesFromLaravel(), 'https://uipe.test');
        foreach (array_keys($spec['paths']) as $p) {
            $this->assertStringNotContainsString('/sdks/', $p);
        }
    }
}
