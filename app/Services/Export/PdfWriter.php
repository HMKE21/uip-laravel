<?php
/**
 * Service: PdfWriter
 * Status: Active - Phase 13 (Data Analysis Portal — PDF export)
 *
 * الرسم الأساسي دلوقتي بـ mPDF (composer require mpdf/mpdf): HTML -> PDF
 * بخط Unicode مدمج (DejaVu Sans) وتشكيل/اتجاه عربي حقيقي (RTL)، جدول
 * منسّق بهيدر متكرر في كل صفحة، وأرقام صفحات. لو mPDF مش متسطّب (أو فشل
 * لأي سبب) بنرجع تلقائيًا للكاتب اليدوي القديم (Courier / Latin-1 بس،
 * العربي بيظهر "?") عشان التصدير ميتعطّلش.
 *
 * @package UIP
 */

namespace App\Services\Export;

class PdfWriter
{
    private const PAGE_WIDTH = 595.28;  // A4, points
    private const PAGE_HEIGHT = 841.89;
    private const MARGIN = 40;
    private const FONT_SIZE = 9;
    private const LINE_HEIGHT = 13;
    private const CHART_HEIGHT = 130;       // bar height budget, points
    private const CHART_LABEL_SPACE = 26;   // room below bars for axis labels

    /**
     * @param string $path absolute path to write the .pdf file to
     * @param string $title printed at the top of the report
     * @param string[] $header
     * @param array<int,array<int,mixed>> $rows
     */
    public static function writeTable(string $path, string $title, array $header, array $rows, bool $rtl = false): void
    {
        if (self::mpdfAvailable()) {
            try {
                self::$rtlTable = $rtl;
                self::writeTableMpdf($path, $title, $header, $rows);
                self::$rtlTable = false;
                return;
            } catch (\Throwable $e) {
                self::$rtlTable = false;
                self::warn('mPDF table export failed, using the basic PDF writer: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
            }
        }

        $lines = self::buildLines($title, $header, $rows);
        $pages = self::paginate($lines);
        file_put_contents($path, self::buildPdf($pages));
    }

    /**
     * Multi-section report with an optional real vector bar chart —
     * closes the "التقارير مينفعش تتضمن Charts / Sections منسّقة" gap:
     * PdfWriter used to be a single flat table only. Used by the AI Code
     * Review exports (project/university/faculty/comparison reports)
     * below for a document with distinct Project Info / Scores /
     * Findings / Recommendations / Admin Decision sections plus a bar
     * chart of the six review scores — a genuine filled-rectangle PDF
     * chart drawn with real content-stream operators, not an image.
     *
     * @param string $path absolute path to write the .pdf file to
     * @param string $title report title
     * @param array<string,string> $meta label => value lines under the title (e.g. "Project", "Generated At")
     * @param array<int,array{title:string,header:string[],rows:array<int,array<int,mixed>>}> $sections
     * @param ?array{labels:string[],values:array<int,?int>} $chart 0-100 scale bar values, drawn on page 1 only
     */
    public static function writeReport(string $path, string $title, array $meta, array $sections, ?array $chart = null): void
    {
        if (self::mpdfAvailable()) {
            try {
                self::writeReportMpdf($path, $title, $meta, $sections, $chart);
                return;
            } catch (\Throwable $e) {
                self::warn('mPDF report export failed, using the basic PDF writer: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
            }
        }

        $headerLines = self::buildHeaderLines($title, $meta);
        $bodyLines = [];
        foreach ($sections as $i => $section) {
            if ($i > 0) {
                $bodyLines[] = '';
            }
            $bodyLines = array_merge($bodyLines, self::buildSectionLines($section['title'], $section['header'], $section['rows']));
        }

        $allLines = array_merge($headerLines, [''], $bodyLines);

        $perPage = max((int) floor((self::PAGE_HEIGHT - 2 * self::MARGIN) / self::LINE_HEIGHT), 1);
        $reservedForChart = ($chart && !empty($chart['values']))
            ? (int) ceil((self::CHART_HEIGHT + self::CHART_LABEL_SPACE + self::LINE_HEIGHT) / self::LINE_HEIGHT)
            : 0;
        $perPageFirst = max($perPage - $reservedForChart, 1);

        $pages = [];
        $remaining = $allLines;
        $pages[] = array_splice($remaining, 0, $perPageFirst);
        while ($remaining) {
            $pages[] = array_splice($remaining, 0, $perPage);
        }

        $chartForPage0 = ($chart && !empty($chart['values'])) ? self::chartStream($chart['labels'] ?? [], $chart['values']) : null;

        file_put_contents($path, self::buildPdf($pages, $chartForPage0));
    }

    // ------------------------------------------------------------------
    //  mPDF renderer (Unicode + Arabic RTL + styled tables)
    // ------------------------------------------------------------------

    private const ACCENT = '#4830F0';

    private static function mpdfAvailable(): bool
    {
        return class_exists(\Mpdf\Mpdf::class);
    }

    private static function warn(string $message): void
    {
        // stderr كمان، عشان يظهر في لوج Railway حتى لو Laravel بيكتب في ملف.
        @error_log('[PdfWriter] ' . $message);
        if (class_exists(\Illuminate\Support\Facades\Log::class)) {
            try {
                \Illuminate\Support\Facades\Log::warning($message);
            } catch (\Throwable) {
                // logging must never break an export
            }
        }
    }

    private static function tempDir(): string
    {
        $base = function_exists('storage_path') ? storage_path('app/mpdf') : sys_get_temp_dir() . '/mpdf';
        if (!is_dir($base)) {
            @mkdir($base, 0775, true);
        }
        return is_dir($base) && is_writable($base) ? $base : sys_get_temp_dir();
    }

    private static function newMpdf(bool $landscape, string $footerLabel, int $rowCount = 0): \Mpdf\Mpdf
    {
        // mPDF بياخد وقت وذاكرة مع الجداول الكبيرة (آلاف الصفوف).
        if ($rowCount > 300) {
            if ((int) ini_get('memory_limit') !== -1 && self::memoryBytes() < 768 * 1048576) {
                @ini_set('memory_limit', '768M');
            }
            @set_time_limit(300);
            @ini_set('pcre.backtrack_limit', '10000000');
            @ini_set('pcre.recursion_limit', '1000000');
        }

        $mpdf = new \Mpdf\Mpdf([
            'mode'              => 'utf-8',
            'format'            => $landscape ? 'A4-L' : 'A4',
            'margin_left'       => 12,
            'margin_right'      => 12,
            'margin_top'        => 14,
            'margin_bottom'     => 16,
            'margin_footer'     => 7,
            'default_font'      => 'dejavusans',
            'default_font_size' => 9,
            'tempDir'           => self::tempDir(),
            'autoScriptToLang'  => true,
            'autoLangToFont'    => true,
            'useSubstitutions'  => $rowCount <= 300,   // تقيل جداً على الجداول الكبيرة
        ]);
        if ($rowCount > 300) {
            $mpdf->simpleTables = true;   // أسرع وأخف بكتير على الجداول الطويلة
            $mpdf->packTableData = true;
        }
        $mpdf->SetTitle($footerLabel);
        $mpdf->SetCreator('University Innovation Platform');
        $mpdf->SetFooter('<table width="100%" style="font-size:7.5pt;color:#6b7280;border-top:0.4pt solid #d1d5db"><tr>'
            . '<td width="70%">' . self::esc($footerLabel) . ' &nbsp;·&nbsp; ' . date('Y-m-d H:i') . '</td>'
            . '<td width="30%" align="right">{PAGENO} / {nbpg}</td></tr></table>');
        return $mpdf;
    }

    private static function memoryBytes(): int
    {
        $v = trim((string) ini_get('memory_limit'));
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    }

    /** عرض الجدول من اليمين لليسار (تصدير بالعربي) — بيتظبط في writeTable وبيتصفّر بعد الكتابة. */
    private static bool $rtlTable = false;

    private static function css(): string
    {
        $a = self::ACCENT;
        $thAlign = self::$rtlTable ? 'right' : 'left';
        return "
            body { font-family: dejavusans; font-size: 9pt; color: #1f2937; }
            .brand { color: {$a}; font-size: 7.5pt; letter-spacing: 1.5pt; font-weight: bold; }
            h1 { font-size: 17pt; margin: 2pt 0 0 0; color: #111827; }
            h2 { font-size: 11.5pt; margin: 14pt 0 5pt 0; color: {$a}; }
            .sub { color: #6b7280; font-size: 8.5pt; margin: 3pt 0 0 0; }
            .rule { border-bottom: 1.6pt solid {$a}; height: 6pt; margin-bottom: 10pt; }
            table.data { border-collapse: collapse; width: 100%; border: 0.4pt solid #e5e7eb; }
            table.data th { background-color: {$a}; color: #ffffff; font-size: 8.3pt; padding: 5pt 6pt; text-align: {$thAlign}; border: 0.4pt solid #e5e7eb; }
            table.data td { padding: 4pt 6pt; border-bottom: 0.4pt solid #e5e7eb; vertical-align: top; }
            table.data tr.alt td { background-color: #f5f6fb; }
            table.meta td { padding: 2.5pt 6pt 2.5pt 0; vertical-align: top; }
            td.k { color: #6b7280; width: 28%; }
            .none { color: #9ca3af; font-style: italic; }
            .bar-lbl { font-size: 8.3pt; padding: 3pt 8pt 3pt 0; width: 110pt; }
            .bar-val { font-size: 8.3pt; padding: 3pt 0 3pt 8pt; width: 30pt; text-align: left; font-weight: bold; }
        ";
    }

    private static function esc($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function isRtl(string $s): bool
    {
        return (bool) preg_match('/[\x{0590}-\x{08FF}\x{FB1D}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $s);
    }

    /** snake_case / raw column keys -> readable header ("created_at" => "Created At"). */
    private static function humanize($label): string
    {
        $label = trim((string) $label);
        if ($label !== '' && preg_match('/^[a-z0-9_\-]+$/', $label)) {
            return ucwords(str_replace(['_', '-'], ' ', $label));
        }
        return $label;
    }

    private static function cell($value, string $tag = 'td', string $extraStyle = ''): string
    {
        $text = trim((string) $value);
        $attrs = '';
        $style = $extraStyle;
        if ($text !== '' && self::isRtl($text)) {
            $attrs = ' dir="rtl"';
            $style .= 'text-align:right;';
        }
        return "<{$tag}{$attrs}" . ($style !== '' ? " style=\"{$style}\"" : '') . '>' . self::esc($text) . "</{$tag}>";
    }

    /** @return bool[] column index => true if every non-empty value is numeric */
    private static function numericColumns(array $header, array $rows): array
    {
        $cols = [];
        for ($i = 0, $n = count($header); $i < $n; $i++) {
            $seen = false;
            $numeric = true;
            foreach ($rows as $row) {
                $v = trim((string) (array_values($row)[$i] ?? ''));
                if ($v === '') {
                    continue;
                }
                $seen = true;
                if (!preg_match('/^-?[\d.,]+%?$/', $v)) {
                    $numeric = false;
                    break;
                }
            }
            $cols[$i] = $seen && $numeric;
        }
        return $cols;
    }

    private static function tableHtml(array $header, array $rows, ?array $numeric = null): string
    {
        $header = array_values($header);
        if (!$header && $rows) {
            $header = array_fill(0, count(array_values(reset($rows))), '');
        }
        $numeric ??= self::numericColumns($header, $rows);

        $html = '<table class="data" repeat_header="1"' . (self::$rtlTable ? ' dir="rtl"' : '') . '><thead><tr>';
        foreach ($header as $i => $h) {
            $html .= self::cell(self::humanize($h), 'th', !empty($numeric[$i]) ? 'text-align:right;' : '');
        }
        $html .= '</tr></thead><tbody>';
        $n = 0;
        foreach ($rows as $row) {
            $row = array_values($row);
            $html .= '<tr' . (($n++ % 2) ? ' class="alt"' : '') . '>';
            foreach ($header as $i => $_) {
                $html .= self::cell($row[$i] ?? '', 'td', !empty($numeric[$i]) ? 'text-align:right;white-space:nowrap;' : '');
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table>';
    }

    private static function titleBlock(string $title, ?string $subtitle = null): string
    {
        return '<div class="brand">UIP · UNIVERSITY INNOVATION PLATFORM</div>'
            . '<h1' . (self::isRtl($title) || self::$rtlTable ? ' dir="rtl"' : '') . '>' . self::esc($title) . '</h1>'
            . ($subtitle !== null ? '<div class="sub"' . (self::$rtlTable ? ' dir="rtl"' : '') . '>' . self::esc($subtitle) . '</div>' : '')
            . '<div class="rule"></div>';
    }

    private static function writeTableMpdf(string $path, string $title, array $header, array $rows): void
    {
        $landscape = count($header) > 5;
        $mpdf = self::newMpdf($landscape, $title, count($rows));
        $mpdf->WriteHTML('<style>' . self::css() . '</style>', \Mpdf\HTMLParserMode::HEADER_CSS);

        $count = count($rows);
        $mpdf->WriteHTML(self::titleBlock($title, \App\Services\Export\ExportLocalizer::recordsLabel($count)), \Mpdf\HTMLParserMode::HTML_BODY);
        if ($count === 0) {
            $mpdf->WriteHTML('<p class="none"' . (self::$rtlTable ? ' dir="rtl"' : '') . '>' . (self::$rtlTable ? 'لا توجد بيانات.' : 'No data.') . '</p>', \Mpdf\HTMLParserMode::HTML_BODY);
        } elseif ($count <= 150) {
            $mpdf->WriteHTML(self::tableHtml($header, $rows), \Mpdf\HTMLParserMode::HTML_BODY);
        } else {
            // جدول HTML واحد بآلاف الصفوف بيفشّل mPDF (حد الـ regex/الذاكرة)،
            // فبنكتبه على دفعات — كل دفعة جدول بنفس الهيدر.
            $cleanHeader = array_values($header);
            $numeric = self::numericColumns($cleanHeader, $rows);
            foreach (array_chunk($rows, 150) as $chunk) {
                $mpdf->WriteHTML(self::tableHtml($cleanHeader, $chunk, $numeric), \Mpdf\HTMLParserMode::HTML_BODY);
            }
        }

        $mpdf->Output($path, \Mpdf\Output\Destination::FILE);
        if (!is_file($path)) {
            throw new \RuntimeException('Could not write the PDF file.');
        }
    }

    private static function writeReportMpdf(string $path, string $title, array $meta, array $sections, ?array $chart): void
    {
        $widest = 0;
        foreach ($sections as $sec) {
            $widest = max($widest, count($sec['header'] ?? []));
        }
        $totalRows = 0;
        foreach ($sections as $sec) {
            $totalRows += count($sec['rows'] ?? []);
        }
        $mpdf = self::newMpdf($widest > 6, $title, $totalRows);
        $mpdf->WriteHTML('<style>' . self::css() . '</style>', \Mpdf\HTMLParserMode::HEADER_CSS);

        $html = self::titleBlock($title);

        if ($meta) {
            $html .= '<table class="meta">';
            foreach ($meta as $label => $value) {
                $html .= '<tr><td class="k">' . self::esc($label) . '</td>' . self::cell($value) . '</tr>';
            }
            $html .= '</table>';
        }

        if ($chart && !empty($chart['values'])) {
            $html .= '<h2>Scores</h2>';
            foreach (array_values($chart['values']) as $i => $value) {
                $v = max(0, min(100, (int) ($value ?? 0)));
                $color = $v >= 70 ? '#1C9E6B' : ($v >= 40 ? '#DE9E17' : '#D43D3D');
                $label = (string) ($chart['labels'][$i] ?? '');
                // عرض ثابت بالـ pt: mPDF بيتجاهل النسب % جوّه الجداول المتداخلة.
                $filled = max($v, 1) * 3.4;
                $rest = (100 - max($v, 1)) * 3.4;
                // جدول منفصل لكل شريط: الأعمدة جوّه الجدول الواحد بتتشارك العرض بين الصفوف.
                $html .= '<table style="border-collapse:collapse;margin-bottom:2pt"><tr><td class="bar-lbl">' . self::esc($label) . '</td>'
                    . '<td style="width:' . $filled . 'pt;background-color:' . $color . ';height:9pt;font-size:3pt">&nbsp;</td>'
                    . '<td style="width:' . $rest . 'pt;background-color:#eef0f6;font-size:3pt">&nbsp;</td>'
                    . '<td class="bar-val">' . ($value === null ? '—' : $v) . '</td></tr></table>';
            }
        }

        foreach ($sections as $section) {
            $html .= '<h2>' . self::esc($section['title'] ?? '') . '</h2>';
            $header = $section['header'] ?? [];
            $rows = $section['rows'] ?? [];
            $html .= (empty($header) && empty($rows)) ? '<p class="none">None</p>' : self::tableHtml($header, $rows);
        }

        $mpdf->WriteHTML($html, \Mpdf\HTMLParserMode::HTML_BODY);
        $mpdf->Output($path, \Mpdf\Output\Destination::FILE);
        if (!is_file($path)) {
            throw new \RuntimeException('Could not write the PDF file.');
        }
    }

    /** @return string[] */
    private static function buildHeaderLines(string $title, array $meta): array
    {
        $lines = [self::clampWidth($title)];
        foreach ($meta as $label => $value) {
            $lines[] = self::clampWidth($label . ': ' . $value);
        }
        return $lines;
    }

    /** @return string[] */
    private static function buildSectionLines(string $sectionTitle, array $header, array $rows): array
    {
        if (empty($header) && empty($rows)) {
            return [self::clampWidth('-- ' . $sectionTitle . ' --'), self::clampWidth('  (none)')];
        }

        $all = array_merge([$header], $rows);
        $widths = [];
        foreach ($all as $row) {
            foreach (array_values($row) as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen((string) $cell));
            }
        }
        $formatRow = function (array $row) use ($widths): string {
            $parts = [];
            foreach (array_values($row) as $i => $cell) {
                $parts[] = str_pad((string) $cell, $widths[$i] ?? 0);
            }
            return self::clampWidth(implode('  ', $parts));
        };
        $sepWidth = array_sum($widths) + max(count($widths) - 1, 0) * 2;

        $lines = [];
        $lines[] = self::clampWidth('-- ' . $sectionTitle . ' --');
        $lines[] = $formatRow($header);
        $lines[] = str_repeat('-', min($sepWidth, self::maxChars()));
        foreach ($rows as $row) {
            $lines[] = $formatRow($row);
        }
        return $lines;
    }

    /** @return string[] */
    private static function buildLines(string $title, array $header, array $rows): array
    {
        $all = array_merge([$header], $rows);

        $widths = [];
        foreach ($all as $row) {
            foreach (array_values($row) as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen((string) $cell));
            }
        }

        $formatRow = function (array $row) use ($widths): string {
            $parts = [];
            foreach (array_values($row) as $i => $cell) {
                $parts[] = str_pad((string) $cell, $widths[$i] ?? 0);
            }
            return self::clampWidth(implode('  ', $parts));
        };

        $sepWidth = array_sum($widths) + max(count($widths) - 1, 0) * 2;

        $lines = [];
        $lines[] = self::clampWidth($title);
        $lines[] = '';
        $lines[] = $formatRow($header);
        $lines[] = str_repeat('-', max(min($sepWidth, self::maxChars()), mb_strlen($title)));
        foreach ($rows as $row) {
            $lines[] = $formatRow($row);
        }
        return $lines;
    }

    private static function maxChars(): int
    {
        // Courier is exactly 0.6em wide per character.
        return (int) floor((self::PAGE_WIDTH - 2 * self::MARGIN) / (0.6 * self::FONT_SIZE));
    }

    private static function clampWidth(string $line): string
    {
        $max = self::maxChars();
        if (mb_strlen($line) <= $max) {
            return $line;
        }
        return mb_substr($line, 0, max($max - 1, 0)) . '…';
    }

    /** @param string[] $lines @return array<int,string[]> */
    private static function paginate(array $lines): array
    {
        $perPage = max((int) floor((self::PAGE_HEIGHT - 2 * self::MARGIN) / self::LINE_HEIGHT), 1);
        $pages = array_chunk($lines, $perPage);
        return $pages ?: [[]];
    }

    /** @param array<int,string[]> $pages @param ?string $chartForPage0 raw content-stream ops appended after page 1's text */
    private static function buildPdf(array $pages, ?string $chartForPage0 = null): string
    {
        $pageCount = count($pages);
        $objects = [];

        // Numbering: 1 catalog, 2 pages, 3 font, then page/content pairs.
        $kids = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $kids[] = (4 + $i * 2) . ' 0 R';
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $pageCount . ' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>';

        foreach ($pages as $i => $lines) {
            $pageObjNum = 4 + $i * 2;
            $contentObjNum = 5 + $i * 2;

            $objects[$pageObjNum] = '<< /Type /Page /Parent 2 0 R '
                . '/MediaBox [0 0 ' . self::PAGE_WIDTH . ' ' . self::PAGE_HEIGHT . '] '
                . '/Resources << /Font << /F1 3 0 R >> >> '
                . '/Contents ' . $contentObjNum . ' 0 R >>';

            $stream = self::contentStream($lines);
            if ($i === 0 && $chartForPage0 !== null) {
                $stream .= "\n" . $chartForPage0;
            }
            $objects[$contentObjNum] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        }

        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsetMap = [];
        foreach ($objects as $num => $content) {
            $offsetMap[$num] = strlen($pdf);
            $pdf .= $num . " 0 obj\n" . $content . "\nendobj\n";
        }

        $maxObjNum = max(array_keys($objects));
        $xrefOffset = strlen($pdf);
        $pdf .= 'xref' . "\n" . '0 ' . ($maxObjNum + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($n = 1; $n <= $maxObjNum; $n++) {
            $pdf .= isset($offsetMap[$n])
                ? sprintf("%010d 00000 n \n", $offsetMap[$n])
                : "0000000000 00000 f \n";
        }
        $pdf .= "trailer\n<< /Size " . ($maxObjNum + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";

        return $pdf;
    }

    /** @param string[] $lines */
    private static function contentStream(array $lines): string
    {
        $top = self::PAGE_HEIGHT - self::MARGIN;
        $stream = "BT\n/F1 " . self::FONT_SIZE . " Tf\n" . self::LINE_HEIGHT . " TL\n"
            . self::MARGIN . ' ' . $top . " Td\n";

        foreach ($lines as $i => $line) {
            $escaped = self::escapeText($line);
            $stream .= $i === 0 ? "($escaped) Tj\n" : "T*\n($escaped) Tj\n";
        }

        return $stream . 'ET';
    }

    private static function escapeText(string $text): string
    {
        $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        // Core Type1 fonts only cover Latin-1 — anything else becomes "?".
        return preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '';
    }

    /**
     * Real vector bar chart — filled rectangles (`re`/`f`) plus an axis
     * line, drawn directly with PDF content-stream operators (no image,
     * no external library). Anchored to the bottom-left of the page so it
     * sits in the space reserved by writeReport()'s $reservedForChart,
     * independent of how many text lines happen to be above it. Bars are
     * color-coded by value the same way the admin UI badges scores
     * (>=70 green / >=40 amber / else red) so the exported PDF matches
     * what an admin sees on screen.
     * @param string[] $labels
     * @param array<int,?int> $values 0-100 scale; null renders as an empty/zero bar
     */
    private static function chartStream(array $labels, array $values): string
    {
        $n = max(count($values), 1);
        $chartLeft = self::MARGIN;
        $chartRight = self::PAGE_WIDTH - self::MARGIN;
        $chartWidth = $chartRight - $chartLeft;
        $gap = 8;
        $barWidth = max((($chartWidth - $gap * ($n - 1)) / $n), 4);
        $chartBottom = self::MARGIN + self::CHART_LABEL_SPACE;
        $chartTop = $chartBottom + self::CHART_HEIGHT;

        $stream = "q\n";
        // Baseline axis.
        $stream .= "0.6 0.6 0.6 RG\n0.75 w\n"
            . $chartLeft . ' ' . $chartBottom . " m " . $chartRight . ' ' . $chartBottom . " l S\n";

        foreach (array_values($values) as $i => $value) {
            $v = max(0, min(100, (int) ($value ?? 0)));
            $barH = ($v / 100) * self::CHART_HEIGHT;
            $x = $chartLeft + $i * ($barWidth + $gap);
            $y = $chartBottom;

            [$r, $g, $b] = $v >= 70 ? [0.11, 0.62, 0.42] : ($v >= 40 ? [0.87, 0.62, 0.09] : [0.83, 0.24, 0.24]);
            $stream .= sprintf("%.2F %.2F %.2F rg\n", $r, $g, $b);
            $stream .= sprintf("%.2F %.2F %.2F %.2F re f\n", $x, $y, $barWidth, max($barH, 1));

            // Value, centered above the bar.
            $valueText = self::escapeText((string) $v);
            $tx = $x + ($barWidth / 2) - (strlen($valueText) * self::FONT_SIZE * 0.3);
            $ty = $y + $barH + 4;
            $stream .= "0 0 0 rg\nBT\n/F1 " . (self::FONT_SIZE - 1) . " Tf\n1 0 0 1 " . round($tx, 2) . ' ' . round($ty, 2) . " Tm\n($valueText) Tj\nET\n";

            // Label, below the axis.
            $label = self::escapeText((string) ($labels[$i] ?? ''));
            $maxLabelChars = (int) floor(($barWidth + $gap) / (0.55 * (self::FONT_SIZE - 2)));
            if (strlen($label) > $maxLabelChars) {
                $label = substr($label, 0, max($maxLabelChars - 1, 1)) . '.';
            }
            $lx = $x + ($barWidth / 2) - (strlen($label) * (self::FONT_SIZE - 2) * 0.3);
            $ly = $chartBottom - 12;
            $stream .= "BT\n/F1 " . (self::FONT_SIZE - 2) . " Tf\n1 0 0 1 " . round($lx, 2) . ' ' . round($ly, 2) . " Tm\n($label) Tj\nET\n";
        }

        $stream .= "Q";
        return $stream;
    }
}
