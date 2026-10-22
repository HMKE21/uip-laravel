<?php

namespace App\Services;

use App\Models\DriveNode;
use App\Support\DriveException;
use Illuminate\Support\Facades\DB;

/**
 * بحث داخل محتوى ملفات "جهازي" (الأكواد والنصوص).
 *
 * الفهرس: جدول drive_node_text فيه نص كل ملف نصي (لحد DRIVE_INDEX_MAX_KB). مفيش hooks في مسارات الحفظ: وقت البحث بنكمّل فهرسة
 * أي ملف جديد أو متغيّر (بصمة الحجم + sha1) بميزانية صغيرة لكل طلب، والباقي (pending) الواجهة بتكرر الطلب عليه، أو
 * `php artisan drive:index-content` يفهرس الكل مرة واحدة. العزل: كل استعلام متقيّد بـ user_id، والملفات المحذوفة (سلة) مش بتظهر.
 */
class DriveContentSearchService
{
    /** @var string[] */
    private const TEXT_EXT = ['txt', 'md', 'markdown', 'log', 'json', 'xml', 'yml', 'yaml', 'toml', 'ini', 'env', 'gitignore', 'csv', 'tsv',
        'js', 'jsx', 'mjs', 'cjs', 'ts', 'tsx', 'vue', 'py', 'java', 'c', 'h', 'cpp', 'hpp', 'cc', 'cs', 'php', 'rb', 'go', 'rs', 'kt',
        'swift', 'dart', 'lua', 'pl', 'r', 'jl', 'hs', 'f90', 'pas', 'sql', 'sh', 'bat', 'css', 'scss', 'less', 'html', 'htm', 'svg',
        'gradle', 'properties', 'geojson', 'webmanifest', 'jsonl', 'ndjson', 'tex', 'rst', 'conf', 'cfg', 'tf'];
    private const TEXT_NAMES = '/^(makefile|dockerfile|procfile|readme|license|changelog|\.gitignore|\.env.*|\.editorconfig)$/i';

    public function __construct(private DriveService $drive)
    {
    }

    private function maxBytes(): int
    {
        return max(8, (int) config('drive.index_max_kb', 256)) * 1024;
    }

    public function isIndexable(string $name, int $size): bool
    {
        if ($size <= 0 || $size > $this->maxBytes()) {
            return false;
        }
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        return in_array($ext, self::TEXT_EXT, true) || (bool) preg_match(self::TEXT_NAMES, $name);
    }

    /** يقرا الملف ويسجّل نصه في الفهرس (أو NULL لو مش نصي). */
    public function indexNode(int $uid, DriveNode $n): void
    {
        $content = null;
        if ($this->isIndexable((string) $n->name, (int) $n->size_bytes)) {
            try {
                [, $stream] = $this->drive->openStream($uid, (int) $n->id);
                $raw = (string) stream_get_contents($stream, $this->maxBytes() + 1);
                if (is_resource($stream)) {
                    fclose($stream);
                }
                if ($raw !== '' && strlen($raw) <= $this->maxBytes() && !str_contains(substr($raw, 0, 8000), "\0")) {
                    $content = mb_check_encoding($raw, 'UTF-8') ? $raw : (string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1256,ISO-8859-1');
                    if (str_starts_with($content, "\xEF\xBB\xBF")) {
                        $content = substr($content, 3);
                    }
                }
            } catch (\Throwable $e) {
                $content = null; // الملف مش متاح دلوقتي: بنسجّله من غير نص (بيتفهرس تاني لو اتغيّر)
            }
        }
        $row = [
            'user_id' => $uid, 'size_bytes' => (int) $n->size_bytes, 'sha1' => $n->sha1, 'content' => $content, 'indexed_at' => now(),
        ];
        DB::table('drive_node_text')->updateOrInsert(['node_id' => (int) $n->id], $row);
    }

    /** ملفات المستخدم الحيّة اللي مالهاش فهرس أو فهرسها قديم. */
    private function staleQuery(int $uid)
    {
        return DB::table('drive_nodes as n')
            ->leftJoin('drive_node_text as t', 't.node_id', '=', 'n.id')
            ->where('n.user_id', $uid)->where('n.type', 'file')->whereNull('n.deleted_at')->whereNotNull('n.storage_path')
            ->where(function ($q) {
                $q->whereNull('t.node_id')
                    ->orWhereColumn('t.size_bytes', '!=', 'n.size_bytes')
                    ->orWhereRaw("COALESCE(t.sha1, '') != COALESCE(n.sha1, '')");
            });
    }

    public function pending(int $uid): int
    {
        return (int) $this->staleQuery($uid)->count();
    }

    /** يفهرس لحد $maxFiles ملف / $maxBytes بايت. @return int عدد الملفات اللي اتفهرست */
    public function catchUp(int $uid, int $maxFiles = 120, int $maxBytes = 16_000_000): int
    {
        $ids = $this->staleQuery($uid)->orderByDesc('n.updated_at')->limit($maxFiles)->pluck('n.id')->all();
        $done = 0;
        $bytes = 0;
        foreach ($ids as $id) {
            $n = DriveNode::query()->where('user_id', $uid)->where('id', $id)->first();
            if (!$n) {
                continue;
            }
            $this->indexNode($uid, $n);
            $done++;
            $bytes += $this->isIndexable((string) $n->name, (int) $n->size_bytes) ? (int) $n->size_bytes : 0;
            if ($bytes >= $maxBytes) {
                break;
            }
        }

        return $done;
    }

    public function forget(array $nodeIds): void
    {
        foreach (array_chunk($nodeIds, 500) as $chunk) {
            DB::table('drive_node_text')->whereIn('node_id', $chunk)->delete();
        }
    }

    /**
     * @return array{items:array,pending:int,term:string}
     */
    public function search(int $uid, string $term, int $limit = 40): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            throw new DriveException('Type at least 2 characters to search inside files.', 422);
        }
        $term = mb_substr($term, 0, 100);
        $this->catchUp($uid);
        $pending = $this->pending($uid);

        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
        $hits = DB::table('drive_node_text as t')
            ->join('drive_nodes as n', 'n.id', '=', 't.node_id')
            ->where('t.user_id', $uid)->where('n.user_id', $uid)->whereNull('n.deleted_at')
            ->whereNotNull('t.content')->where('t.content', 'like', $like)
            ->orderByDesc('n.updated_at')->limit(min(100, max(1, $limit)))
            ->get(['t.node_id', 't.content']);
        if ($hits->isEmpty()) {
            return ['items' => [], 'pending' => $pending, 'term' => $term];
        }
        $nodes = DriveNode::query()->where('user_id', $uid)->whereIn('id', $hits->pluck('node_id')->all())->get()->keyBy('id');
        $crumbs = [];
        $items = [];
        foreach ($hits as $h) {
            $n = $nodes->get($h->node_id);
            if (!$n) {
                continue;
            }
            $pid = $n->parent_id !== null ? (int) $n->parent_id : null;
            $crumbs[$pid ?? 0] ??= implode('/', array_column($this->drive->breadcrumb($uid, $pid), 'name'));
            $r = $n->toRowArray();
            $r['path'] = $crumbs[$pid ?? 0];
            [$r['matches'], $r['match_count']] = $this->snippets((string) $h->content, $term);
            $items[] = $r;
        }

        return ['items' => $items, 'pending' => $pending, 'term' => $term];
    }

    /** أول 3 مطابقات: رقم السطر + السطر مقصوص حوالين الكلمة. @return array{0:array,1:int} */
    private function snippets(string $content, string $term): array
    {
        $out = [];
        $count = 0;
        $tl = mb_strlen($term);
        $offset = 0;
        $lenAll = mb_strlen($content);
        while (($pos = mb_stripos($content, $term, $offset)) !== false) {
            $count++;
            if (count($out) < 3) {
                $lineStart = mb_strrpos(mb_substr($content, 0, $pos), "\n");
                $lineStart = $lineStart === false ? 0 : $lineStart + 1;
                $lineEnd = mb_strpos($content, "\n", $pos);
                $lineEnd = $lineEnd === false ? $lenAll : $lineEnd;
                $line = mb_substr($content, $lineStart, $lineEnd - $lineStart);
                $col = $pos - $lineStart;
                $from = max(0, $col - 60);
                $text = mb_substr($line, $from, 180);
                $out[] = [
                    'line'  => substr_count(mb_substr($content, 0, $pos), "\n") + 1,
                    'text'  => rtrim($text, "\r"),
                    'start' => $col - $from,
                    'len'   => $tl,
                ];
            }
            if ($count >= 200) {
                break;
            }
            $offset = $pos + $tl;
        }

        return [$out, $count];
    }
}
