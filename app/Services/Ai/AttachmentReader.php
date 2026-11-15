<?php

namespace App\Services\Ai;

/**
 * قراءة مرفقات المساعد: استخراج نص من PDF / DOCX / PPTX / XLSX، وتجهيز الصور (وصفحات PDF الممسوحة ضوئيًا)
 * كـ data URI صغيرة عشان تتبعت لموديل بيشوف الصور. كل الدوال best-effort: بترجّع null / [] عند أي فشل ومابترميش.
 */
class AttachmentReader
{
    public const MAX_TEXT = 200000;

    /** امتدادات نصية بتتقرا كنص خام (ملف مرفوع أو جوّه zip). */
    public const TEXT_EXT = [
        'txt', 'md', 'markdown', 'csv', 'tsv', 'json', 'xml', 'sql', 'log', 'ini', 'toml', 'env', 'html', 'htm', 'css', 'scss',
        'php', 'js', 'jsx', 'ts', 'tsx', 'vue', 'py', 'java', 'kt', 'swift', 'rs', 'c', 'h', 'cpp', 'cs', 'go', 'rb', 'sh', 'bat',
        'yml', 'yaml', 'gitignore', 'dockerfile',
    ];
    /** امتدادات بتحتاج استخراج (PDF / Office / OpenDocument / RTF / ZIP). */
    public const EXTRACT_EXT = ['pdf', 'docx', 'pptx', 'xlsx', 'odt', 'ods', 'odp', 'rtf', 'zip'];

    public static function isTextExt(string $ext): bool
    {
        return in_array(strtolower($ext), self::TEXT_EXT, true);
    }

    public static function canExtract(string $ext): bool
    {
        return in_array(strtolower($ext), self::EXTRACT_EXT, true);
    }

    /** بايتات نص → UTF-8 (ملفات عربي قديمة بتبقى Windows-1256). */
    public static function toUtf8(string $raw): string
    {
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        if (mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }
        return (string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1256, ISO-8859-1');
    }

    public static function extractText(string $path, string $ext): ?string
    {
        $ext = strtolower($ext);
        try {
            $text = match ($ext) {
                'pdf'  => self::pdfText($path),
                'docx' => self::zipXmlText($path, ['word/document.xml'], '</w:p>'),
                'pptx' => self::pptxText($path),
                'xlsx' => self::xlsxText($path),
                'odt', 'odp' => self::zipXmlText($path, ['content.xml'], '</text:p>'),
                'ods'  => self::zipXmlText($path, ['content.xml'], '</table:table-row>'),
                'rtf'  => self::rtfText($path),
                'zip'  => self::zipListing($path),
                default => null,
            };
        } catch (\Throwable $e) {
            return null;
        }
        if ($text === null) {
            return null;
        }
        $text = trim(preg_replace("/[ \t]+\n/", "\n", preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $text))) ?? '');
        return mb_strlen($text) >= 3 ? mb_substr($text, 0, self::MAX_TEXT) : null;
    }

    private static function rtfText(string $path): ?string
    {
        $raw = @file_get_contents($path, false, null, 0, 3_000_000);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $raw = preg_replace_callback("/\\\\'([0-9a-f]{2})/i", fn ($m) => chr(hexdec($m[1])), $raw) ?? $raw;
        $raw = preg_replace('/\\\\(par|line|tab)\b ?/', "\n", $raw) ?? $raw;
        $raw = preg_replace('/\{\\\\\*[^{}]*\}/', '', $raw) ?? $raw;
        $raw = preg_replace('/\\\\[a-z]+-?\d* ?|[{}]/i', '', $raw) ?? $raw;
        return self::toUtf8($raw);
    }

    /**
     * محتوى zip: قايمة الملفات + نص الملفات النصية/المكتب جوّاه (بيتقرا في الذاكرة بس — مفيش فك على القرص، فمفيش zip-slip).
     * node_modules/vendor/.git وأمثالهم بيتعدّوا. حدود: 400 مدخل، 2MB لكل ملف، ميزانية نص كلية.
     */
    private static function zipListing(string $path): ?string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        $total = $zip->numFiles;
        $listing = [];
        $bodies = [];
        $skipped = 0;
        $budget = 150000;
        $nested = 0;
        $perFile = 12000;

        for ($i = 0; $i < min($total, 400); $i++) {
            $st = $zip->statIndex($i);
            if (!is_array($st)) {
                continue;
            }
            $name = (string) $st['name'];
            if (preg_match('#(^|/)(node_modules|vendor|\.git|__MACOSX|\.idea|dist|build|\.next)(/|$)#', $name) || str_ends_with($name, '.DS_Store')) {
                $skipped++;
                continue;
            }
            $isDir = str_ends_with($name, '/');
            $size = (int) ($st['size'] ?? 0);
            $listing[] = $isDir ? "[dir]  {$name}" : "[file] {$name} ({$size} bytes)";
            if ($isDir || $budget <= 0 || $size <= 0 || $size > 2_000_000) {
                continue;
            }
            $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
            $base = strtolower(basename($name));
            $text = null;
            if (self::isTextExt($ext) || in_array($base, ['readme', 'license', 'makefile', 'dockerfile', '.env.example', '.gitignore'], true)) {
                $raw = $zip->getFromIndex($i, 200000);
                if (is_string($raw) && $raw !== '' && !str_contains(substr($raw, 0, 4000), "\0")) {
                    $text = self::toUtf8($raw);
                }
            } elseif (in_array($ext, ['docx', 'pptx', 'xlsx', 'pdf', 'odt', 'ods', 'odp'], true) && $nested < 6 && $size <= 10_000_000) {
                $raw = $zip->getFromIndex($i);
                if (is_string($raw) && $raw !== '') {
                    $nested++;
                    $tmp = tempnam(sys_get_temp_dir(), 'uipzn');
                    if ($tmp !== false) {
                        @file_put_contents($tmp, $raw);
                        $text = self::extractText($tmp, $ext);
                        @unlink($tmp);
                    }
                }
            }
            if ($text !== null && trim($text) !== '') {
                $cut = mb_strlen($text) > $perFile;
                $chunk = mb_substr($text, 0, $perFile);
                $budget -= mb_strlen($chunk);
                $bodies[] = "--- {$name} ---\n" . $chunk . ($cut ? "\n[... file truncated]" : '');
            }
        }

        $head = "ZIP archive with {$total} entries" . ($skipped ? " ({$skipped} dependency/build entries hidden)" : '') . ($total > 400 ? ' — only the first 400 are listed' : '') . ":\n" . implode("\n", $listing);
        $zip->close();
        return $head . ($bodies ? "\n\nText content of readable files:\n\n" . implode("\n\n", $bodies) : '');
    }

    private static function shellOk(): bool
    {
        if (!function_exists('shell_exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return !in_array('shell_exec', $disabled, true);
    }

    private static function hasBinary(string $bin): bool
    {
        if (!self::shellOk()) {
            return false;
        }
        $out = @shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null');
        return is_string($out) && trim($out) !== '';
    }

    private static function pdfText(string $path): ?string
    {
        if (self::hasBinary('pdftotext')) {
            $cmd = 'timeout 20 pdftotext -layout -enc UTF-8 -l 60 ' . escapeshellarg($path) . ' - 2>/dev/null';
            $out = @shell_exec($cmd);
            if (is_string($out) && trim($out) !== '') {
                return $out;
            }
        }
        if (class_exists('\Smalot\PdfParser\Parser')) {
            $parser = new \Smalot\PdfParser\Parser();
            return $parser->parseFile($path)->getText();
        }
        return null;
    }

    private static function xmlToText(string $xml, string $paragraphEnd): string
    {
        $xml = str_replace([$paragraphEnd, '</w:tab>', '<w:tab/>', '<w:br/>', '</a:p>'], ["\n", "\t", "\t", "\n", "\n"], $xml);
        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** @param string[] $names */
    private static function zipXmlText(string $path, array $names, string $paragraphEnd): ?string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        $out = '';
        foreach ($names as $n) {
            $xml = $zip->getFromName($n);
            if (is_string($xml)) {
                $out .= self::xmlToText($xml, $paragraphEnd) . "\n";
            }
        }
        $zip->close();
        return $out;
    }

    private static function pptxText(string $path): ?string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        $slides = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $m)) {
                $slides[(int) $m[1]] = $name;
            }
        }
        ksort($slides);
        $out = '';
        foreach ($slides as $n => $name) {
            $xml = $zip->getFromName($name);
            if (is_string($xml)) {
                $out .= "--- Slide {$n} ---\n" . self::xmlToText($xml, '</a:p>') . "\n";
            }
        }
        $zip->close();
        return $out;
    }

    private static function xlsxText(string $path): ?string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        $shared = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($ss) && preg_match_all('#<si>(.*?)</si>#s', $ss, $items)) {
            foreach ($items[1] as $si) {
                $shared[] = html_entity_decode(strip_tags($si), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
        $out = '';
        for ($s = 1; $s <= 8; $s++) {
            $xml = $zip->getFromName("xl/worksheets/sheet{$s}.xml");
            if (!is_string($xml)) {
                continue;
            }
            $out .= "--- Sheet {$s} ---\n";
            $rows = 0;
            if (preg_match_all('#<row[^>]*>(.*?)</row>#s', $xml, $rowMatches)) {
                foreach ($rowMatches[1] as $row) {
                    if (++$rows > 500) {
                        $out .= "[...more rows truncated]\n";
                        break;
                    }
                    $cells = [];
                    if (preg_match_all('#<c ([^>]*?)(?:/>|>(.*?)</c>)#s', $row, $cm, PREG_SET_ORDER)) {
                        foreach ($cm as $c) {
                            $attrs = $c[1];
                            $inner = $c[2] ?? '';
                            $val = '';
                            if (preg_match('#<v>(.*?)</v>#s', $inner, $v)) {
                                $val = $v[1];
                                if (preg_match('#t="s"#', $attrs)) {
                                    $val = $shared[(int) $val] ?? '';
                                }
                            } elseif (preg_match('#<t[^>]*>(.*?)</t>#s', $inner, $t)) {
                                $val = $t[1];
                            }
                            $cells[] = html_entity_decode((string) $val, ENT_QUOTES | ENT_XML1, 'UTF-8');
                        }
                    }
                    $out .= implode("\t", $cells) . "\n";
                }
            }
        }
        $zip->close();
        return $out;
    }

    /** صورة مصغّرة JPEG كـ data URI (أقصى بُعد $maxDim) — بتقلّل التوكنز وحجم الطلب. */
    public static function imageDataUri(string $path, int $maxDim = 1568): ?string
    {
        if (!is_file($path) || filesize($path) > 25_000_000) {
            return null;
        }
        $bytes = @file_get_contents($path);
        if (!is_string($bytes) || $bytes === '') {
            return null;
        }
        return self::bytesToDataUri($bytes, $maxDim);
    }

    public static function bytesToDataUri(string $bytes, int $maxDim = 1568): ?string
    {
        $info = @getimagesizefromstring($bytes);
        if (!is_array($info) || !in_array($info['mime'] ?? '', ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
            return null;
        }
        if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
            $src = @imagecreatefromstring($bytes);
            if ($src !== false) {
                $w = imagesx($src);
                $h = imagesy($src);
                $scale = min(1.0, $maxDim / max($w, $h));
                $nw = max(1, (int) round($w * $scale));
                $nh = max(1, (int) round($h * $scale));
                $dst = imagecreatetruecolor($nw, $nh);
                imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // شفافية PNG -> خلفية بيضا
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
                ob_start();
                imagejpeg($dst, null, 85);
                $jpeg = (string) ob_get_clean();
                imagedestroy($src);
                imagedestroy($dst);
                if ($jpeg !== '') {
                    return 'data:image/jpeg;base64,' . base64_encode($jpeg);
                }
            }
        }
        return 'data:' . $info['mime'] . ';base64,' . base64_encode($bytes);
    }

    /**
     * أول صفحات PDF كصور (للـ PDF الممسوح ضوئيًا اللي مفيهوش نص). محتاج pdftoppm على السيرفر (poppler-utils).
     * @return string[] data URIs
     */
    public static function pdfPagesAsImages(string $path, int $pages = 2, int $maxDim = 1400): array
    {
        if (!is_file($path) || !self::hasBinary('pdftoppm')) {
            return [];
        }
        $dir = sys_get_temp_dir() . '/uipai_' . bin2hex(random_bytes(6));
        if (!@mkdir($dir, 0700, true)) {
            return [];
        }
        $out = [];
        try {
            @shell_exec('timeout 30 pdftoppm -jpeg -r 90 -f 1 -l ' . max(1, min(4, $pages)) . ' ' . escapeshellarg($path) . ' ' . escapeshellarg($dir . '/p') . ' 2>/dev/null');
            $files = glob($dir . '/p*.jpg') ?: [];
            sort($files);
            foreach ($files as $f) {
                $uri = self::imageDataUri($f, $maxDim);
                if ($uri !== null) {
                    $out[] = $uri;
                }
            }
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
        return $out;
    }
}
