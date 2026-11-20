<?php

namespace App\Support;

/**
 * النسخة المحلية من الـ Lexicon Hub (هاشات بس) + المطابِق. بتتملي بـ `php artisan lexicon:sync` (LexiconSyncService).
 *
 * الفحص كله محلي: بنهاش كلمات النص وعبارات من 1 لـ 4 كلمات والدومينات اللي فيه بنفس قواعد build.py
 * (normalize ثم SHA-256 مقتطع لـ hash_len حرف) ونقارنها بالقايمة المحمّلة. مفيش نص مستخدم بيطلع من السيرفر.
 *
 * التخزين: storage/app/lexicon/
 *   current.json     مؤشر النسخة الشغّالة {version, dir, synced_at, source, hash_len, max_ngram, counts, files}
 *   status.json      آخر محاولة sync (نجاح/فشل) للأدمن
 *   v<version>/d/xx.txt  دومينات (256 شارد)،  v<version>/w/_.txt كلمات القوائم العامة،  v<version>/c/_.txt كلمات الأدمن المخصّصة
 *
 * Tiers (زي README بتاع الـ Hub): d + c = tier 1 (أي نص)، w = tier 2 (السياقات الصارمة بس: بحث GIF، أسماء، usernames).
 * لو مفيش نسخة محمّلة أو الملفات تالفة: الدالة بترجّع null والقاموس المدمج في ContentLexicon يكمّل لوحده.
 */
class LexiconStore
{
    private const HOST_RE = '/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/';
    /** سقف الكلمات اللي بنهاش عبارات منها في نص واحد (حماية من نص ضخم). */
    private const MAX_WORDS = 50000;
    /** نعيد قراءة current.json كل كام ثانية (عشان الـ queue workers / Octane يلقطوا نسخة جديدة من غير restart). */
    private const RECHECK_SECS = 300;

    private static ?string $dirOverride = null;
    /** @var array<string,mixed>|null */
    private static ?array $state = null;
    private static int $loadedAt = 0;
    private static bool $warned = false;

    // ───────────── paths / meta ─────────────

    public static function useDir(?string $dir): void
    {
        self::$dirOverride = $dir;
        self::forget();
    }

    public static function baseDir(): string
    {
        if (self::$dirOverride !== null) {
            return rtrim(self::$dirOverride, '/\\');
        }
        $p = function_exists('config') ? (string) config('content_safety.remote.path', '') : '';

        return $p !== '' ? rtrim($p, '/\\') : storage_path('app/lexicon');
    }

    public static function forget(): void
    {
        self::$state = null;
        self::$loadedAt = 0;
        self::$warned = false;
    }

    /** @return array<string,mixed>|null  محتوى current.json لو فيه نسخة صالحة. */
    public static function meta(): ?array
    {
        $f = self::baseDir() . '/current.json';
        $raw = is_file($f) ? @file_get_contents($f) : false;
        $m = $raw ? json_decode($raw, true) : null;
        if (!is_array($m) || !isset($m['version'], $m['dir']) || !preg_match('/^[A-Za-z0-9._-]{1,60}$/', (string) $m['dir'])) {
            return null;
        }

        return $m;
    }

    /** حالة للأدمن. @return array<string,mixed> */
    public static function status(): array
    {
        $m = self::meta();
        $s = [];
        $f = self::baseDir() . '/status.json';
        if (is_file($f)) {
            $s = json_decode((string) @file_get_contents($f), true) ?: [];
        }
        $syncedAt = $m['synced_at'] ?? null;

        return [
            'loaded' => $m !== null,
            'version' => $m['version'] ?? null,
            'synced_at' => $syncedAt,
            'source' => $m['source'] ?? null,
            'counts' => $m['counts'] ?? null,
            'stale' => $syncedAt ? (time() - (int) strtotime((string) $syncedAt)) > 3 * 86400 : null,
            'last_attempt_at' => $s['last_attempt_at'] ?? null,
            'last_success_at' => $s['last_success_at'] ?? null,
            'last_error' => $s['last_error'] ?? null,
        ];
    }

    // ───────────── hashing (لازم يطابق build.py) ─────────────

    public static function hash(string $term, int $len = 16): string
    {
        return substr(hash('sha256', $term), 0, $len);
    }

    /** نفس words_of في build.py: أي حاجة مش حرف/رقم = فاصل. @return string[] */
    private static function words(string $norm): array
    {
        return preg_split('/[^\p{L}\p{N}]+/u', $norm, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    // ───────────── loading ─────────────

    /** @return array<string,mixed>|null */
    private static function state(): ?array
    {
        $now = time();
        if (self::$loadedAt !== 0 && ($now - self::$loadedAt) < self::RECHECK_SECS) {
            return self::$state;
        }
        self::$loadedAt = $now;
        $m = self::meta();
        if ($m === null) {
            return self::$state = null;
        }
        if (self::$state !== null && (self::$state['version'] ?? null) === $m['version']) {
            return self::$state;
        }
        $dir = self::baseDir() . '/' . $m['dir'];
        $load = function (string $rel) use ($dir): array {
            $raw = @file_get_contents($dir . '/' . $rel);

            return $raw === false ? [] : array_flip(array_filter(explode("\n", $raw), fn ($l) => $l !== ''));
        };

        return self::$state = [
            'version' => $m['version'],
            'dir' => $dir,
            'hash_len' => max(8, min(32, (int) ($m['hash_len'] ?? 16))),
            'max_ngram' => max(1, min(6, (int) ($m['max_ngram'] ?? 4))),
            'w' => $load('w/_.txt'),
            'c' => $load('c/_.txt'),
            'd' => [],
        ];
    }

    private static function domainHit(array &$st, string $hash): bool
    {
        $shard = substr($hash, 0, 2);
        if (!isset($st['d'][$shard])) {
            $raw = @file_get_contents($st['dir'] . '/d/' . $shard . '.txt');
            // "\n" في الأول والآخر عشان str_contains على "\nهاش\n" يطابق سطر كامل (الملفات مرتبة وصغيرة ~64KB).
            $st['d'][$shard] = $raw === false ? "\n" : "\n" . $raw;
        }

        return str_contains($st['d'][$shard], "\n" . $hash . "\n");
    }

    // ───────────── API ─────────────

    /**
     * @param string               $norm     النص بعد ContentLexicon::normalize
     * @param array<string,mixed>  $allowSet استثناءات (مفاتيح = كلمات/دومينات متطبّعة)
     * @return array{tier:int,category:string,term:string}|null
     */
    public static function check(string $norm, bool $strict = false, array $allowSet = []): ?array
    {
        try {
            if ($norm === '' || self::state() === null) {
                return null;
            }

            return self::run(self::$state, $norm, $strict, $allowSet);
        } catch (\Throwable $e) {
            if (!self::$warned) {
                self::$warned = true;
                try {
                    \Illuminate\Support\Facades\Log::warning('Lexicon store check failed: ' . $e->getMessage());
                } catch (\Throwable) {
                    // اللوج مش متاح (اختبار بدون Laravel)
                }
            }

            return null;
        }
    }

    /** @param array<string,mixed> $st */
    private static function run(array &$st, string $norm, bool $strict, array $allowSet): ?array
    {
        $len = $st['hash_len'];

        // 1) دومينات (tier 1) — الدومين نفسه وأي parent (sub.bad.com → bad.com)
        $text = preg_replace('/\s*[\[\(\{]\s*(?:\.|dot)\s*[\]\)\}]\s*/iu', '.', $norm) ?? $norm;
        if (preg_match_all('/(?<![\p{L}\p{N}_.\-])((?:[\p{L}\p{N}](?:[\p{L}\p{N}\-]{0,61}[\p{L}\p{N}])?\.)+[\p{L}\p{N}\-]{2,63})(?![\p{L}\p{N}])/u', $text, $mm)) {
            $seen = [];
            foreach ($mm[1] as $host) {
                $host = rtrim($host, '.-');
                if (isset($seen[$host])) {
                    continue;
                }
                $seen[$host] = true;
                if (preg_match('/[^\x00-\x7F]/', $host)) {
                    $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : false;
                    if (!is_string($ascii) || $ascii === '') {
                        continue;
                    }
                    $host = $ascii;
                }
                if (str_starts_with($host, 'www.')) {
                    $host = substr($host, 4);
                }
                if (!preg_match(self::HOST_RE, $host)) {
                    continue;
                }
                $labels = explode('.', $host);
                for ($i = 0, $n = count($labels) - 1; $i < $n; $i++) {
                    $cand = implode('.', array_slice($labels, $i));
                    if (isset($allowSet[$cand])) {
                        break;
                    }
                    if (self::domainHit($st, self::hash($cand, $len))) {
                        return ['tier' => 1, 'category' => 'blocked_link', 'term' => $cand];
                    }
                }
            }
        }

        if (!$st['c'] && !$st['w']) {
            return null;
        }

        // 2) كلمات مفردة (مع صيغ التحايل: leet/تكرار/حروف متفرّقة) — c = tier 1، w = tier 2 (strict بس)
        $seenTok = [];
        foreach (ContentLexicon::tokens($norm) as $tok) {
            if ($tok === '' || isset($seenTok[$tok])) {
                continue;
            }
            $seenTok[$tok] = true;
            foreach (ContentLexicon::variants($tok) as $v) {
                if (isset($allowSet[$v])) {
                    continue 2;
                }
                if (mb_strlen($v) < 3) {
                    continue;
                }
                $h = self::hash($v, $len);
                if (isset($st['c'][$h])) {
                    return ['tier' => 1, 'category' => 'custom', 'term' => $v];
                }
                if ($strict && isset($st['w'][$h])) {
                    return ['tier' => 2, 'category' => 'profanity', 'term' => $v];
                }
            }
        }

        // 3) عبارات من 2 لـ max_ngram كلمات
        $words = self::words($norm);
        if (count($words) > self::MAX_WORDS) {
            $words = array_slice($words, 0, self::MAX_WORDS);
        }
        $cnt = count($words);
        $max = $st['max_ngram'];
        for ($i = 0; $i < $cnt - 1; $i++) {
            $phrase = $words[$i];
            for ($k = 1; $k < $max && $i + $k < $cnt; $k++) {
                $phrase .= ' ' . $words[$i + $k];
                if (isset($allowSet[$phrase])) {
                    break;
                }
                $h = self::hash($phrase, $len);
                if (isset($st['c'][$h])) {
                    return ['tier' => 1, 'category' => 'custom', 'term' => $phrase];
                }
                if ($strict && isset($st['w'][$h])) {
                    return ['tier' => 2, 'category' => 'profanity', 'term' => $phrase];
                }
            }
        }

        return null;
    }
}
