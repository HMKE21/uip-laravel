<?php

namespace App\Console\Commands;

use App\Services\Developer\DevOpenApi;
use App\Services\Developer\Sdk\SdkGenerator;
use Illuminate\Console\Command;

/**
 * php artisan developer:sdk                        → يولّد sdks/{python,js,php} + sdks/openapi.json من الـ routes الحالية
 * php artisan developer:sdk --lang=python,js       → لغات معيّنة
 * php artisan developer:sdk --spec=openapi.json    → من ملف spec جاهز بدل الـ routes
 * php artisan developer:sdk --check                → يفشل (exit 1) لو اللي في sdks/ مختلف عن اللي هيتولّد (ملف ناقص/مختلف/زيادة). ده اللي يتحط في CI
 *
 * الـ spec هو نفسه اللي بيطلّعه developer:openapi، فالـ SDKs مصدرها الـ routes الفعلية + DevOpenApi::catalog().
 */
class DeveloperSdk extends Command
{
    protected $signature = 'developer:sdk {--lang=all : python, js, php (comma separated) or all} {--out= : output folder (default: sdks/)} {--spec= : read this openapi.json instead of building it from the routes} {--url= : base URL (default APP_URL)} {--sdk-version=1.0.0 : version written into the packages} {--check : compare with the files on disk and fail if they differ}';
    protected $description = 'Generate the PHP, JavaScript and Python SDKs of the Developer API from its OpenAPI spec';

    /** ملفات بتنتج من تشغيل/تثبيت الـ SDK نفسه، مش من المولّد. */
    private const IGNORED = ['/__pycache__/', '/node_modules/', '/vendor/', '.pyc', '.egg-info', '/dist/', '/build/', 'package-lock.json', 'composer.lock', '.phpunit.result.cache', '.DS_Store'];

    public function handle(): int
    {
        $lang = trim((string) $this->option('lang'));
        $langs = $lang === '' || $lang === 'all' ? SdkGenerator::LANGS : array_values(array_filter(array_map('trim', explode(',', $lang))));
        foreach ($langs as $l) {
            if (!in_array($l, SdkGenerator::LANGS, true)) {
                $this->error("Unknown language '{$l}'. Use: " . implode(', ', SdkGenerator::LANGS) . ' or all.');
                return self::FAILURE;
            }
        }

        $spec = $this->spec();
        if ($spec === null) {
            return self::FAILURE;
        }

        $out = rtrim((string) ($this->option('out') ?: base_path('sdks')), '/\\');
        try {
            $files = SdkGenerator::generate($spec, $langs, (string) $this->option('sdk-version'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        if ($this->option('check')) {
            return $this->check($out, $langs, $files);
        }

        foreach ($files as $rel => $content) {
            $path = $out . '/' . $rel;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            file_put_contents($path, $content);
        }
        $this->info('Wrote ' . count($files) . " files to {$out} (" . implode(', ', $langs) . ').');
        return self::SUCCESS;
    }

    /** @return array<string,mixed>|null */
    private function spec(): ?array
    {
        if ($file = $this->option('spec')) {
            if (!is_file($file)) {
                $this->error("Spec file not found: {$file}");
                return null;
            }
            $spec = json_decode((string) file_get_contents($file), true);
            if (!is_array($spec) || !isset($spec['paths'])) {
                $this->error("{$file} is not an OpenAPI document.");
                return null;
            }
            return $spec;
        }
        $routes = DevOpenApi::routesFromLaravel();
        $bad = false;
        foreach (DevOpenApi::unmapped($routes) as $k) {
            $this->error("Route without documentation: {$k} (add it to DevOpenApi::catalog())");
            $bad = true;
        }
        foreach (DevOpenApi::orphans($routes) as $k) {
            $this->error("Documented but no such route: {$k}");
            $bad = true;
        }
        if ($bad) {
            return null;
        }
        return DevOpenApi::build($routes, (string) ($this->option('url') ?: config('app.url')));
    }

    /** @param array<string,string> $files */
    private function check(string $out, array $langs, array $files): int
    {
        $problems = [];
        foreach ($files as $rel => $content) {
            $path = $out . '/' . $rel;
            if (!is_file($path)) {
                $problems[] = "missing   {$rel}";
            } elseif (file_get_contents($path) !== $content) {
                $problems[] = "changed   {$rel}";
            }
        }
        foreach ($langs as $l) {
            $dir = $out . '/' . $l;
            if (!is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!$f->isFile()) {
                    continue;
                }
                $full = str_replace('\\', '/', $f->getPathname());
                foreach (self::IGNORED as $skip) {
                    if (str_contains($full, $skip)) {
                        continue 2;
                    }
                }
                $rel = $l . '/' . substr($full, strlen(str_replace('\\', '/', $dir)) + 1);
                if (!isset($files[$rel])) {
                    $problems[] = "stale     {$rel}";
                }
            }
        }
        if ($problems) {
            sort($problems);
            foreach ($problems as $p) {
                $this->line($p);
            }
            $this->error('The SDKs are out of date. Run: php artisan developer:sdk');
            return self::FAILURE;
        }
        $this->info('SDKs are up to date.');
        return self::SUCCESS;
    }
}
