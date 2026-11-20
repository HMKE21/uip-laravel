<?php

namespace App\Console\Commands;

use App\Services\Developer\DevOpenApi;
use Illuminate\Console\Command;

/**
 * php artisan developer:openapi                      → يطبع الـ spec
 * php artisan developer:openapi --out=openapi.json   → يكتبه في ملف (للـ SDKs)
 * php artisan developer:openapi --check=openapi.json → يفشل (exit 1) لو الملف مختلف عن الـ routes الحالية، وهو اللي يتحط في CI
 */
class DeveloperOpenApi extends Command
{
    protected $signature = 'developer:openapi {--out= : write the spec to this file} {--check= : compare with this file and fail if it is out of date} {--url= : base URL (default APP_URL)}';
    protected $description = 'Generate the OpenAPI 3.1 spec of the Developer API from the registered routes';

    public function handle(): int
    {
        $routes = DevOpenApi::routesFromLaravel();
        $missing = DevOpenApi::unmapped($routes);
        $orphans = DevOpenApi::orphans($routes);
        foreach ($missing as $k) {
            $this->warn("Route without documentation: {$k} (add it to DevOpenApi::catalog())");
        }
        foreach ($orphans as $k) {
            $this->warn("Documented but no such route: {$k}");
        }
        $json = json_encode(DevOpenApi::build($routes, (string) ($this->option('url') ?: config('app.url'))), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";

        if ($check = $this->option('check')) {
            if (!is_file($check) || file_get_contents($check) !== $json) {
                $this->error("{$check} is out of date. Run: php artisan developer:openapi --out={$check}");
                return self::FAILURE;
            }
            $this->info('OpenAPI spec is up to date.');
            return $missing || $orphans ? self::FAILURE : self::SUCCESS;
        }
        if ($out = $this->option('out')) {
            file_put_contents($out, $json);
            $this->info("Wrote {$out} (" . count($routes) . ' operations).');
        } else {
            $this->line($json);
        }
        return $missing || $orphans ? self::FAILURE : self::SUCCESS;
    }
}
