<?php

namespace App\Services;

use App\Support\LexiconStore;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * مزامنة قوايم الـ Lexicon Hub (موقع static فيه هاشات بس) لنسخة محلية — `php artisan lexicon:sync`.
 *
 * الخطوات لكل رابط (الأول أساسي والتاني احتياطي):
 *  1. تنزيل {url}/v1/manifest.json والتحقق من توقيع HMAC-SHA256 بـ CONTENT_SAFETY_REMOTE_KEY
 *     (نفس LEXICON_SIGN_KEY بتاع الـ Hub). manifest من غير توقيع أو بتوقيع غلط = مرفوض.
 *  2. تنزيل كل ملف والتحقق من SHA-256 بتاعه (الملفات اللي هاشها ما اتغيّرش بتتنسخ من النسخة الحالية).
 *  3. حارس: الإصدار الأقدم من المثبّت مرفوض (replay)، وأي تحديث بيصغّر قايمة الدومينات (أو الكلمات العامة) لأقل من
 *     min_ratio (افتراضي 50%) مرفوض. الأرقام بتتحسب من الملفات الفعلية (counts في الـ manifest مش موقّعة).
 *  4. النسخة بتتبنى في مجلد مؤقت وبعدين rename + تبديل current.json ذرّي، فمفيش لحظة فيها قايمة نص نازلة.
 * لو الرابطين وقعوا: النسخة المحفوظة بتفضل شغّالة زي ما هي (مفيش حاجة بتتمسح).
 */
class LexiconSyncService
{
    private const NAME_RE = '#^(?:d/[0-9a-f]{2}|w/_|c/_)\.txt$#';
    private const MAX_FILE_BYTES = 4_000_000;
    private const MAX_FILES = 400;

    /** @var array<string,mixed> */
    private array $cfg;

    /** @var \Closure(string):string|null  fetcher مخصّص (للاختبارات): url → body أو يرمي Exception */
    private ?\Closure $fetcher;

    /**
     * @param array<string,mixed>|null $config بديل لـ config('content_safety.remote')
     */
    public function __construct(?array $config = null, ?\Closure $fetcher = null)
    {
        $this->cfg = $config ?? (array) config('content_safety.remote', []);
        $this->fetcher = $fetcher;
    }

    /**
     * @return array{status:string,version:?string,source:?string,errors:string[],counts:?array,message:string}
     *   status: updated | current | failed | busy | not_configured
     */
    public function sync(bool $force = false): array
    {
        $urls = array_values(array_filter((array) ($this->cfg['urls'] ?? []), fn ($u) => is_string($u) && trim($u) !== ''));
        $key = (string) ($this->cfg['key'] ?? '');
        $base = LexiconStore::baseDir();
        $installed = LexiconStore::meta();
        $out = ['status' => 'failed', 'version' => $installed['version'] ?? null, 'source' => null, 'errors' => [], 'counts' => $installed['counts'] ?? null, 'message' => ''];

        if (!$urls) {
            return array_merge($out, ['status' => 'not_configured', 'message' => 'CONTENT_SAFETY_REMOTE_URLS is empty.']);
        }
        if ($key === '') {
            // من غير مفتاح مفيش طريقة نتأكد إن القوايم جاية من الـ Hub فعلًا => نرفض بدل ما نثق في أي حد.
            $out['errors'][] = 'CONTENT_SAFETY_REMOTE_KEY is empty — refusing to install unsigned lists.';
            $out['message'] = $out['errors'][0];
            $this->writeStatus($base, null, $out['errors'][0]);

            return $out;
        }

        if (!is_dir($base) && !@mkdir($base, 0775, true) && !is_dir($base)) {
            $out['errors'][] = 'Cannot create ' . $base;
            $out['message'] = $out['errors'][0];

            return $out;
        }
        $lock = @fopen($base . '/.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return ['status' => 'busy', 'message' => 'Another lexicon:sync is running.'] + $out;
        }

        try {
            foreach ($urls as $url) {
                try {
                    $r = $this->syncFrom(rtrim(trim($url), '/'), $key, $base, $installed, $force);
                    $this->writeStatus($base, $r['version'], null);

                    return ['status' => $r['status'], 'version' => $r['version'], 'source' => $url, 'errors' => $out['errors'], 'counts' => $r['counts'],
                        'message' => $r['status'] === 'current' ? 'Already up to date.' : 'Installed version ' . $r['version'] . '.'];
                } catch (\Throwable $e) {
                    $out['errors'][] = $this->hostOf($url) . ': ' . $e->getMessage();
                }
            }
            $err = implode(' | ', $out['errors']);
            $this->writeStatus($base, null, $err);
            try {
                Log::warning('Lexicon sync failed on every source; keeping ' . ($installed ? 'installed version ' . $installed['version'] : 'NO lexicon (built-in dictionary only)') . '. ' . $err);
            } catch (\Throwable) {
                // اللوج مش متاح (اختبار بدون Laravel)
            }
            $out['message'] = $installed ? 'All sources failed; keeping installed version ' . $installed['version'] . '.' : 'All sources failed and no lexicon is installed yet.';

            return $out;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param array<string,mixed>|null $installed
     * @return array{status:string,version:string,counts:array}
     */
    private function syncFrom(string $url, string $key, string $base, ?array $installed, bool $force): array
    {
        if (!preg_match('#^https://#i', $url) && !(bool) ($this->cfg['allow_http'] ?? false)) {
            throw new \RuntimeException('source must be https (set CONTENT_SAFETY_REMOTE_ALLOW_HTTP=true only for local testing)');
        }

        // 1) manifest + توقيع
        $m = json_decode($this->get($url . '/v1/manifest.json', 1_000_000), true);
        if (!is_array($m) || (int) ($m['schema'] ?? 0) !== 1) {
            throw new \RuntimeException('manifest is not valid (schema 1 expected)');
        }
        $version = (string) ($m['version'] ?? '');
        $files = $m['files'] ?? null;
        $sig = (string) ($m['sig'] ?? '');
        if (!preg_match('/^[0-9]{8,20}$/', $version) || !is_array($files) || !$files || count($files) > self::MAX_FILES) {
            throw new \RuntimeException('manifest has an invalid version/files list');
        }
        foreach ($files as $name => $sha) {
            if (!is_string($name) || !preg_match(self::NAME_RE, $name) || !is_string($sha) || !preg_match('/^[0-9a-f]{64}$/', $sha)) {
                throw new \RuntimeException('manifest lists an unexpected file name/hash');
            }
        }
        if ($sig === '') {
            throw new \RuntimeException('manifest is not signed');
        }
        ksort($files, SORT_STRING);
        $canon = $version . "\n" . implode("\n", array_map(fn ($k, $v) => $k . ':' . $v, array_keys($files), $files));
        if (!hash_equals(hash_hmac('sha256', $canon, $key), strtolower($sig))) {
            throw new \RuntimeException('manifest signature does not match CONTENT_SAFETY_REMOTE_KEY');
        }
        $hashLen = (int) ($m['hash_len'] ?? 16);
        if ($hashLen < 8 || $hashLen > 32) {
            throw new \RuntimeException('unsupported hash_len ' . $hashLen);
        }
        $maxNgram = max(1, min(6, (int) ($m['max_ngram'] ?? 4)));

        // 2) إصدار: نفس الإصدار = محدّث، أقدم = رفض (إعادة تشغيل manifest قديم موقّع)
        $cur = $installed['version'] ?? null;
        if ($cur !== null && !$force) {
            $cmp = strnatcmp($version, (string) $cur);
            if ($cmp === 0) {
                return ['status' => 'current', 'version' => (string) $cur, 'counts' => (array) ($installed['counts'] ?? [])];
            }
            if ($cmp < 0) {
                throw new \RuntimeException("version $version is older than the installed $cur (possible replay of an old manifest)");
            }
        }

        // 3) تنزيل + تحقق من هاش كل ملف (بالتوازي لو Http متاح) في مجلد مؤقت
        $stage = $base . '/.tmp-' . $version . '-' . bin2hex(random_bytes(3));
        @mkdir($stage, 0775, true);
        try {
            $counts = ['d' => 0, 'w' => 0, 'c' => 0];
            $reuse = [];
            if ($installed && is_array($installed['files'] ?? null) && !$force) {
                foreach ($files as $name => $sha) {
                    $p = $base . '/' . $installed['dir'] . '/' . $name;
                    if (($installed['files'][$name] ?? null) === $sha && is_file($p)) {
                        $reuse[$name] = $p;
                    }
                }
            }
            $need = array_values(array_diff(array_keys($files), array_keys($reuse)));
            $bodies = $this->getMany(array_map(fn ($n) => $url . '/v1/' . $n, $need), self::MAX_FILE_BYTES);
            foreach ($files as $name => $sha) {
                $data = isset($reuse[$name]) ? (string) file_get_contents($reuse[$name]) : ($bodies[$url . '/v1/' . $name] ?? null);
                if ($data === null || !hash_equals($sha, hash('sha256', $data))) {
                    // ملف الـ reuse لو اتلخبط محليًا ننزّله من جديد بدل ما نفشل
                    if (isset($reuse[$name])) {
                        $data = $this->get($url . '/v1/' . $name, self::MAX_FILE_BYTES);
                    }
                    if ($data === null || !hash_equals($sha, hash('sha256', $data))) {
                        throw new \RuntimeException("file $name failed its SHA-256 check");
                    }
                }
                if (!preg_match('/\A(?:[0-9a-f]{' . $hashLen . '}\n)*\z/', $data)) {
                    throw new \RuntimeException("file $name has lines that are not $hashLen-char hashes");
                }
                $counts[$name[0]] += substr_count($data, "\n");
                $dest = $stage . '/' . $name;
                if (!is_dir(dirname($dest))) {
                    @mkdir(dirname($dest), 0775, true);
                }
                if (file_put_contents($dest, $data) === false) {
                    throw new \RuntimeException("cannot write $name");
                }
            }

            // 4) حراس الحجم (من الملفات الفعلية)
            $min = (int) ($this->cfg['min_domains'] ?? 1000);
            if ($counts['d'] < $min) {
                throw new \RuntimeException("domain list has only {$counts['d']} entries (minimum $min)");
            }
            $ratio = (float) ($this->cfg['min_ratio'] ?? 0.5);
            foreach (['d' => 'domain', 'w' => 'term'] as $k => $label) {
                $before = (int) ($installed['counts'][$k] ?? 0);
                if ($before > 0 && $counts[$k] < $before * $ratio) {
                    throw new \RuntimeException("$label list would shrink from $before to {$counts[$k]} (below " . (int) round($ratio * 100) . '%) — update rejected');
                }
            }

            // 5) نشر ذرّي: rename للمجلد ثم تبديل current.json
            $dirName = 'v' . $version;
            $final = $base . '/' . $dirName;
            if (is_dir($final)) {
                $this->rmdir($final);
            }
            if (!@rename($stage, $final)) {
                throw new \RuntimeException('cannot move the new version into place');
            }
            $meta = ['version' => $version, 'dir' => $dirName, 'synced_at' => gmdate('c'), 'source' => $this->hostOf($url), 'hash_len' => $hashLen,
                'max_ngram' => $maxNgram, 'counts' => $counts, 'files' => $files];
            $tmp = $base . '/current.json.tmp';
            if (file_put_contents($tmp, json_encode($meta, JSON_UNESCAPED_SLASHES)) === false || !@rename($tmp, $base . '/current.json')) {
                throw new \RuntimeException('cannot write current.json');
            }
            LexiconStore::forget();
            $this->prune($base, [$dirName, (string) ($installed['dir'] ?? '')]);

            return ['status' => 'updated', 'version' => $version, 'counts' => $counts];
        } finally {
            if (is_dir($stage)) {
                $this->rmdir($stage);
            }
        }
    }

    // ───────────── http ─────────────

    private function timeout(): int
    {
        return max(5, (int) ($this->cfg['timeout'] ?? 30));
    }

    private function get(string $url, int $maxBytes): string
    {
        if ($this->fetcher) {
            $b = ($this->fetcher)($url);
        } else {
            $r = Http::timeout($this->timeout())->withHeaders(['User-Agent' => 'uipe-lexicon-sync'])->retry(2, 500, throw: false)->get($url);
            if (!$r->successful()) {
                throw new \RuntimeException('HTTP ' . $r->status() . ' for ' . $this->shortUrl($url));
            }
            $b = $r->body();
        }
        if (strlen($b) > $maxBytes) {
            throw new \RuntimeException('response too large for ' . $this->shortUrl($url));
        }

        return $b;
    }

    /** @param string[] $urls @return array<string,string> url → body (اللي فشل بيرمي Exception) */
    private function getMany(array $urls, int $maxBytes): array
    {
        $out = [];
        if ($this->fetcher) {
            foreach ($urls as $u) {
                $out[$u] = $this->get($u, $maxBytes);
            }

            return $out;
        }
        foreach (array_chunk($urls, 12) as $chunk) {
            $res = Http::pool(function ($pool) use ($chunk) {
                $reqs = [];
                foreach ($chunk as $i => $u) {
                    $reqs[] = $pool->as((string) $i)->timeout($this->timeout())->withHeaders(['User-Agent' => 'uipe-lexicon-sync'])->get($u);
                }

                return $reqs;
            });
            foreach ($chunk as $i => $u) {
                $r = $res[(string) $i] ?? null;
                if (!($r instanceof \Illuminate\Http\Client\Response) || !$r->successful()) {
                    // محاولة تانية فردية قبل ما نفشل
                    $out[$u] = $this->get($u, $maxBytes);
                    continue;
                }
                if (strlen($r->body()) > $maxBytes) {
                    throw new \RuntimeException('response too large for ' . $this->shortUrl($u));
                }
                $out[$u] = $r->body();
            }
        }

        return $out;
    }

    // ───────────── housekeeping ─────────────

    private function writeStatus(string $base, ?string $okVersion, ?string $error): void
    {
        $f = $base . '/status.json';
        $s = is_file($f) ? (json_decode((string) @file_get_contents($f), true) ?: []) : [];
        $s['last_attempt_at'] = gmdate('c');
        if ($error === null) {
            $s['last_success_at'] = gmdate('c');
            $s['last_error'] = null;
        } else {
            $s['last_error'] = mb_substr($error, 0, 500);
        }
        @file_put_contents($f, json_encode($s, JSON_UNESCAPED_SLASHES));
    }

    /** بيسيب النسخة الحالية + اللي قبلها (للرجوع اليدوي) ويمسح الباقي. @param string[] $keep */
    private function prune(string $base, array $keep): void
    {
        foreach (glob($base . '/v*', GLOB_ONLYDIR) ?: [] as $d) {
            if (!in_array(basename($d), $keep, true)) {
                $this->rmdir($d);
            }
        }
        foreach (glob($base . '/.tmp-*', GLOB_ONLYDIR) ?: [] as $d) {
            if (filemtime($d) < time() - 3600) {
                $this->rmdir($d);
            }
        }
    }

    private function rmdir(string $dir): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    private function hostOf(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_HOST) ?: $url);
    }

    private function shortUrl(string $url): string
    {
        return $this->hostOf($url) . '/…' . basename($url);
    }
}
