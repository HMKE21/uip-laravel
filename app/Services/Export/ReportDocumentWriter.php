<?php

namespace App\Services\Export;

/**
 * كاتب تقارير "مستندية" بصيغ زيادة على الأربعة الأساسية (csv/xlsx/pdf/json):
 * docx (Word حقيقي عبر ZipArchive — من غير مكتبات)، html، md، txt، xml.
 * نفس شكل الداتا بتاع PdfWriter::writeReport(): $title, $meta (key => value),
 * $sections [['title','header','rows']]. $rtl = true لما اللغة عربي.
 */
final class ReportDocumentWriter
{
    public const FORMATS = ['docx', 'html', 'md', 'txt', 'xml'];

    public static function mime(string $format): string
    {
        return [
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'html' => 'text/html; charset=utf-8',
            'md'   => 'text/markdown; charset=utf-8',
            'txt'  => 'text/plain; charset=utf-8',
            'xml'  => 'application/xml; charset=utf-8',
        ][$format] ?? 'application/octet-stream';
    }

    public static function write(string $format, string $path, string $title, array $meta, array $sections, bool $rtl): void
    {
        switch ($format) {
            case 'docx':
                self::docx($path, $title, $meta, $sections, $rtl);
                return;
            case 'html':
                file_put_contents($path, self::html($title, $meta, $sections, $rtl));
                return;
            case 'md':
                file_put_contents($path, self::markdown($title, $meta, $sections));
                return;
            case 'txt':
                file_put_contents($path, "\xEF\xBB\xBF" . self::text($title, $meta, $sections));
                return;
            case 'xml':
                file_put_contents($path, self::xml($title, $meta, $sections));
                return;
        }
        throw new \RuntimeException('Unsupported report format.');
    }

    private static function s($v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_array($v) || is_object($v)) {
            return json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        // شيل محارف التحكم اللي بتبوّظ XML.
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $v) ?? '';
    }

    private static function esc($v): string
    {
        return htmlspecialchars(self::s($v), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    // -- HTML ------------------------------------------------------------

    private static function html(string $title, array $meta, array $sections, bool $rtl): string
    {
        $dir = $rtl ? 'rtl' : 'ltr';
        $lang = $rtl ? 'ar' : 'en';
        $h = '<!DOCTYPE html><html lang="' . $lang . '" dir="' . $dir . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>' . self::esc($title) . '</title>'
            . '<style>body{font-family:Segoe UI,Tahoma,Arial,sans-serif;margin:32px auto;max-width:1000px;padding:0 16px;color:#0f172a;line-height:1.6}'
            . 'h1{font-size:24px;margin:0 0 16px}h2{font-size:18px;margin:28px 0 8px;border-bottom:2px solid #e2e8f0;padding-bottom:4px}'
            . 'table{border-collapse:collapse;width:100%;margin:8px 0;font-size:14px}th,td{border:1px solid #cbd5e1;padding:6px 10px;vertical-align:top;text-align:start}'
            . 'th{background:#f1f5f9}td.k{width:30%;font-weight:600;background:#f8fafc}pre,code{white-space:pre-wrap;word-break:break-word;direction:ltr;text-align:left}'
            . '@media print{body{margin:0}}</style></head><body>';
        $h .= '<h1>' . self::esc($title) . '</h1>';
        if ($meta) {
            $h .= '<table>';
            foreach ($meta as $k => $v) {
                $h .= '<tr><td class="k">' . self::esc($k) . '</td><td>' . nl2br(self::esc($v)) . '</td></tr>';
            }
            $h .= '</table>';
        }
        foreach ($sections as $sec) {
            $h .= '<h2>' . self::esc($sec['title'] ?? '') . '</h2>';
            $rows = $sec['rows'] ?? [];
            if (!$rows) {
                $h .= '<p>—</p>';
                continue;
            }
            $h .= '<table>';
            if (!empty($sec['header'])) {
                $h .= '<tr>' . implode('', array_map(fn ($c) => '<th>' . self::esc($c) . '</th>', $sec['header'])) . '</tr>';
            }
            foreach ($rows as $r) {
                $h .= '<tr>' . implode('', array_map(fn ($c) => '<td>' . nl2br(self::esc($c)) . '</td>', array_values($r))) . '</tr>';
            }
            $h .= '</table>';
        }
        return $h . '</body></html>';
    }

    // -- Markdown --------------------------------------------------------

    private static function mdCell($v): string
    {
        return str_replace(['|', "\r\n", "\n", "\r"], ['\\|', '<br>', '<br>', '<br>'], self::s($v));
    }

    private static function markdown(string $title, array $meta, array $sections): string
    {
        $o = '# ' . self::s($title) . "\n\n";
        foreach ($meta as $k => $v) {
            $o .= '- **' . self::s($k) . ':** ' . str_replace(["\r\n", "\n"], ' ', self::s($v)) . "\n";
        }
        foreach ($sections as $sec) {
            $o .= "\n## " . self::s($sec['title'] ?? '') . "\n\n";
            $rows = $sec['rows'] ?? [];
            $header = $sec['header'] ?? [];
            if (!$rows) {
                $o .= "—\n";
                continue;
            }
            $cols = max(count($header), ...array_map('count', $rows));
            $header = array_pad($header ?: array_fill(0, $cols, ' '), $cols, ' ');
            $o .= '| ' . implode(' | ', array_map([self::class, 'mdCell'], $header)) . " |\n";
            $o .= '|' . str_repeat(' --- |', $cols) . "\n";
            foreach ($rows as $r) {
                $r = array_pad(array_values($r), $cols, '');
                $o .= '| ' . implode(' | ', array_map([self::class, 'mdCell'], $r)) . " |\n";
            }
        }
        return $o;
    }

    // -- Plain text ------------------------------------------------------

    private static function text(string $title, array $meta, array $sections): string
    {
        $o = self::s($title) . "\n" . str_repeat('=', max(10, mb_strlen(self::s($title)))) . "\n\n";
        foreach ($meta as $k => $v) {
            $o .= self::s($k) . ': ' . str_replace(["\r\n", "\n"], ' ', self::s($v)) . "\n";
        }
        foreach ($sections as $sec) {
            $t = self::s($sec['title'] ?? '');
            $o .= "\n" . $t . "\n" . str_repeat('-', max(5, mb_strlen($t))) . "\n";
            $rows = $sec['rows'] ?? [];
            $header = $sec['header'] ?? [];
            if (!$rows) {
                $o .= "—\n";
                continue;
            }
            foreach ($rows as $i => $r) {
                $r = array_values($r);
                $parts = [];
                foreach ($r as $j => $cell) {
                    $label = $header[$j] ?? '';
                    $parts[] = ($label !== '' && count($r) > 2 ? self::s($label) . ': ' : '') . str_replace(["\r\n", "\n"], ' ', self::s($cell));
                }
                $o .= (count($r) > 2 ? ($i + 1) . '. ' : '') . implode(count($r) > 2 ? "\n   " : ': ', $parts) . "\n";
            }
        }
        return $o;
    }

    // -- XML -------------------------------------------------------------

    private static function xml(string $title, array $meta, array $sections): string
    {
        $o = '<?xml version="1.0" encoding="UTF-8"?>' . "\n<report>\n  <title>" . self::esc($title) . "</title>\n  <meta>\n";
        foreach ($meta as $k => $v) {
            $o .= '    <item name="' . self::esc($k) . '">' . self::esc($v) . "</item>\n";
        }
        $o .= "  </meta>\n";
        foreach ($sections as $sec) {
            $header = $sec['header'] ?? [];
            $o .= '  <section title="' . self::esc($sec['title'] ?? '') . "\">\n";
            foreach (($sec['rows'] ?? []) as $r) {
                $o .= "    <row>\n";
                foreach (array_values($r) as $j => $cell) {
                    $o .= '      <cell column="' . self::esc($header[$j] ?? ('c' . ($j + 1))) . '">' . self::esc($cell) . "</cell>\n";
                }
                $o .= "    </row>\n";
            }
            $o .= "  </section>\n";
        }
        return $o . "</report>\n";
    }

    // -- DOCX ------------------------------------------------------------

    private static function docx(string $path, string $title, array $meta, array $sections, bool $rtl): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is required for Word exports.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the Word file.');
        }

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>');

        $body = self::dPara($title, $rtl, true, 36);
        if ($meta) {
            $rows = [];
            foreach ($meta as $k => $v) {
                $rows[] = [$k, $v];
            }
            $body .= self::dTable([], $rows, $rtl, [3000, 6360]);
        }
        foreach ($sections as $sec) {
            $body .= self::dPara((string) ($sec['title'] ?? ''), $rtl, true, 28, 240);
            $rows = $sec['rows'] ?? [];
            if (!$rows) {
                $body .= self::dPara('—', $rtl);
                continue;
            }
            $body .= self::dTable($sec['header'] ?? [], $rows, $rtl);
        }
        $body .= '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1000" w:right="900" w:bottom="1000" w:left="900" w:header="708" w:footer="708" w:gutter="0"/>'
            . ($rtl ? '<w:bidi/>' : '') . '</w:sectPr>';

        $zip->addFromString('word/document.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $body . '</w:body></w:document>');
        $zip->close();
    }

    private static function dRun(string $text, bool $rtl, bool $bold = false, int $size = 21, ?string $color = null): string
    {
        $lines = preg_split('/\r\n|\n|\r/', self::s($text)) ?: [''];
        $out = '';
        foreach ($lines as $i => $line) {
            $rpr = '<w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Arial"/>' . ($bold ? '<w:b/><w:bCs/>' : '')
                . ($color ? '<w:color w:val="' . $color . '"/>' : '')
                . '<w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/>' . ($rtl ? '<w:rtl/>' : '') . '</w:rPr>';
            $out .= '<w:r>' . $rpr . ($i > 0 ? '<w:br/>' : '') . '<w:t xml:space="preserve">' . self::esc($line) . '</w:t></w:r>';
        }
        return $out;
    }

    private static function dPara(string $text, bool $rtl, bool $bold = false, int $size = 21, int $before = 0): string
    {
        return '<w:p><w:pPr>' . ($rtl ? '<w:bidi/>' : '') . '<w:spacing w:before="' . $before . '" w:after="100"/>'
            . ($rtl ? '<w:jc w:val="left"/>' : '') . '</w:pPr>' . self::dRun($text, $rtl, $bold, $size) . '</w:p>';
    }

    /** @param string[] $header @param array<int,array<int,mixed>> $rows @param int[]|null $widths */
    private static function dTable(array $header, array $rows, bool $rtl, ?array $widths = null): string
    {
        $cols = max(count($header), ...array_map('count', $rows));
        $cols = max(1, $cols);
        $total = 9360;
        $widths = $widths ?: array_fill(0, $cols, (int) floor($total / $cols));
        $border = '<w:tblBorders>' . implode('', array_map(
            fn ($b) => '<w:' . $b . ' w:val="single" w:sz="4" w:space="0" w:color="CBD5E1"/>',
            ['top', 'left', 'bottom', 'right', 'insideH', 'insideV']
        )) . '</w:tblBorders>';
        $t = '<w:tbl><w:tblPr><w:tblW w:w="' . $total . '" w:type="dxa"/>' . ($rtl ? '<w:bidiVisual/>' : '') . $border
            . '<w:tblLayout w:type="fixed"/><w:tblCellMar><w:left w:w="80" w:type="dxa"/><w:right w:w="80" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>'
            . implode('', array_map(fn ($w) => '<w:gridCol w:w="' . $w . '"/>', $widths)) . '</w:tblGrid>';

        $cell = function ($v, int $w, bool $head) use ($rtl) {
            return '<w:tc><w:tcPr><w:tcW w:w="' . $w . '" w:type="dxa"/>' . ($head ? '<w:shd w:val="clear" w:color="auto" w:fill="F1F5F9"/>' : '') . '</w:tcPr>'
                . '<w:p><w:pPr>' . ($rtl ? '<w:bidi/>' : '') . '<w:spacing w:before="40" w:after="40"/>' . ($rtl ? '<w:jc w:val="left"/>' : '') . '</w:pPr>'
                . self::dRun(self::s($v), $rtl, $head, 19) . '</w:p></w:tc>';
        };

        if ($header) {
            $header = array_pad(array_values($header), $cols, '');
            $t .= '<w:tr><w:trPr><w:tblHeader/></w:trPr>';
            foreach ($header as $i => $c) {
                $t .= $cell($c, $widths[$i] ?? $widths[0], true);
            }
            $t .= '</w:tr>';
        }
        foreach ($rows as $r) {
            $r = array_pad(array_values($r), $cols, '');
            $t .= '<w:tr><w:trPr><w:cantSplit/></w:trPr>';
            foreach ($r as $i => $c) {
                $t .= $cell($c, $widths[$i] ?? $widths[0], false);
            }
            $t .= '</w:tr>';
        }
        return $t . '</w:tbl>' . '<w:p/>';
    }
}
