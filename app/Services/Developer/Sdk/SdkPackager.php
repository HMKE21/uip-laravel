<?php

namespace App\Services\Developer\Sdk;

/**
 * بيحزّم مكتبة SDK واحدة (من خرج SdkGenerator::generate) في ملف zip جوه فولدر `uipe-<lang>/`.
 * الخرج بيتبني في الذاكرة من الـ spec الحالي، فمفيش اعتماد على وجود sdks/ على السيرفر.
 */
final class SdkPackager
{
    public static function folder(string $lang): string
    {
        return 'uipe-' . $lang;
    }

    /**
     * @param array<string,string> $files خرج SdkGenerator::generate() (مسار نسبي => محتوى)
     * @return string محتوى الـ zip (binary)
     */
    public static function zip(array $files, string $lang): string
    {
        if (!in_array($lang, SdkGenerator::LANGS, true)) {
            throw new \InvalidArgumentException("Unknown language '{$lang}'.");
        }
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is not installed.');
        }
        $prefix = $lang . '/';
        $tmp = tempnam(sys_get_temp_dir(), 'uipesdk');
        if ($tmp === false) {
            throw new \RuntimeException('Could not create a temporary file.');
        }
        try {
            $zip = new \ZipArchive();
            if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not open the zip for writing.');
            }
            $count = 0;
            foreach ($files as $path => $content) {
                if (!str_starts_with($path, $prefix)) {
                    continue;
                }
                $zip->addFromString(self::folder($lang) . '/' . substr($path, strlen($prefix)), $content);
                $count++;
            }
            if ($count === 0) {
                $zip->close();
                throw new \RuntimeException("No files were generated for '{$lang}'.");
            }
            if ($zip->close() !== true) {
                throw new \RuntimeException('Could not finish the zip.');
            }
            return (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
    }
}
