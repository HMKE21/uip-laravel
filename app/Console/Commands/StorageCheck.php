<?php

namespace App\Console\Commands;

use App\Support\PersistentStorage;
use App\Support\StorageHealth;
use Illuminate\Console\Command;

/**
 * php artisan ops:storage          → تقرير: الملفات على Volume ولا لأ + حدود PHP.
 * php artisan ops:storage --link   → يطبّق ربط الـ symlinks دلوقتي (بيتعمل تلقائي عند الإقلاع لو
 *                                    PERSISTENT_STORAGE_PATH متظبط).
 * بيرجّع exit code 1 لو في مشكلة (مناسب كـ health check في CI/نشر).
 */
class StorageCheck extends Command
{
    protected $signature = 'ops:storage {--link : Apply the volume symlinks now}';

    protected $description = 'Reports whether uploads/logs live on a persistent volume and whether PHP upload limits are sufficient.';

    public function handle(): int
    {
        if ($this->option('link')) {
            if (PersistentStorage::root() === null) {
                $this->error('PERSISTENT_STORAGE_PATH is not set — nothing to link.');
                return self::FAILURE;
            }
            foreach (PersistentStorage::apply() as $rel => $status) {
                $this->line(str_pad($rel, 28) . ' ' . $status);
            }
            $this->newLine();
        }

        $r = StorageHealth::report();

        $this->info('PHP (' . $r['php']['sapi'] . ', ini: ' . ($r['php']['ini_file'] ?? 'none') . ')');
        foreach (['upload_max_filesize', 'post_max_size'] as $k) {
            $v = $r['php'][$k];
            $this->line(sprintf('  %-20s %-8s %s', $k, $v['value'], $v['ok'] ? ($v['unlimited'] ? 'ok (unlimited)' : 'ok') : 'TOO LOW'));
        }
        $this->line('  required (upload policy): ' . StorageHealth::human($r['required_upload_bytes']));
        if ($r['php']['sapi'] === 'cli') {
            $this->comment('  note: this is the CLI. The web server may use different values — check GET /api/v1/admin/system/storage-health.');
        }

        $this->newLine();
        $p = $r['persistence'];
        $this->info('Persistence (PERSISTENT_STORAGE_PATH ' . ($p['configured'] ? '= ' . $p['volume']['path'] : 'not set') . ')');
        foreach ($p['paths'] as $row) {
            $this->line(sprintf('  %-28s %s', $row['path'], $row['persistent'] ? 'persistent' : 'EPHEMERAL (lost on deploy)'));
        }
        if ($p['volume']) {
            $v = $p['volume'];
            $this->line('  volume: mount=' . ($v['is_mount'] ? 'yes' : 'NO') . ', writable=' . ($v['writable'] ? 'yes' : 'NO')
                . ', free=' . StorageHealth::human((int) $v['free_bytes']) . ' of ' . StorageHealth::human((int) $v['total_bytes']));
            $s = $v['sentinel'];
            if ($s) {
                $this->line('  survived a redeploy: ' . ($s['survived_redeploy'] === null ? 'unknown yet (redeploy once, then run this again)' : ($s['survived_redeploy'] ? 'YES' : 'no')));
            }
        }

        $this->newLine();
        foreach ($r['warnings'] as $w) {
            $this->warn('! ' . $w);
        }
        $r['ok'] ? $this->info('OK') : $this->error('Problems found.');

        return $r['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
