<?php

namespace App\Console\Commands;

use App\Support\DisposableEmail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Downloads the community list of disposable e-mail domains into the cache (used by DisposableEmail). */
class UpdateDisposableDomains extends Command
{
    protected $signature = 'mail:update-disposable-domains';
    protected $description = 'Download the latest list of disposable e-mail domains';

    public function handle(): int
    {
        $url = (string) config('mail.disposable_list_url');
        try {
            $res = Http::timeout(30)->get($url);
        } catch (\Throwable $e) {
            $this->error('Download failed: ' . $e->getMessage());
            return self::FAILURE;
        }
        if (!$res->successful()) {
            $this->error('Download failed: HTTP ' . $res->status());
            return self::FAILURE;
        }

        $set = [];
        foreach (preg_split('/\R/', (string) $res->body()) as $line) {
            $d = mb_strtolower(trim($line));
            if ($d !== '' && $d[0] !== '#' && preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?\.[a-z0-9-]{2,}$/', $d)) {
                $set[$d] = true;
            }
        }

        // A broken/empty download must never wipe a good list.
        if (count($set) < 1000) {
            $this->error('List looks wrong (' . count($set) . ' domains); keeping the previous one.');
            return self::FAILURE;
        }

        Cache::forever(DisposableEmail::REMOTE_CACHE_KEY, $set);
        $this->info('Updated: ' . count($set) . ' domains.');
        return self::SUCCESS;
    }
}
