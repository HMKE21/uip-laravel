<?php
/**
 * Helper: ReportExportWriter
 * Status: Active - Phase 22 (Reports — multi-format export)
 *
 * Writes a [header, rows] report table to disk in one of the five
 * formats reports.format supports at the schema level
 * (ENUM('pdf','csv','xlsx','json','docx'), migrations 024/061) —
 * ReportService::generate() used to only ever actually write 'csv'.
 * No composer dependencies (project is deliberately vanilla PHP/no
 * Laravel) — xlsx and docx are written as minimal valid Office Open
 * XML packages via the bundled ZipArchive extension, and pdf as a
 * minimal hand-rolled PDF 1.4 stream (base-14 Helvetica, WinAnsi only
 * — see toPdf()'s docblock for the one real limitation that comes
 * with not embedding a Unicode font). docx has no such limitation:
 * Word's own text runs are plain UTF-8, so Arabic report content
 * renders correctly there (each cell's paragraph direction/alignment
 * is set per-cell based on whether it contains Arabic, so a report
 * mixing English headers and Arabic names lays out correctly either
 * way — unlike the PDF path, nothing needs to be placeholder'd out).
 *
 * xml and pptx (added for spec section 11 "Export APIs", which asks
 * for two more formats than the reports.format enum has room for)
 * follow the exact same "no dependencies, hand-rolled OOXML/plain XML"
 * approach as the five above — see toXml()/toPptx()'s own docblocks.
 * @package UIP
 */

namespace App\Helpers;

class ReportExportWriter
{
    /**
     * @param string[] $header
     * @param array<int,array<int,mixed>> $rows
     */
    public static function write(string $format, array $header, array $rows, string $fullPath, ?string $title = null): void
    {
        match ($format) {
            'csv'  => self::toCsv($header, $rows, $fullPath),
            'json' => self::toJson($header, $rows, $fullPath),
            'xlsx' => self::toXlsx($header, $rows, $fullPath),
            'pdf'  => self::toPdfBest($header, $rows, $fullPath, $title),
            'docx' => self::toDocx($header, $rows, $fullPath),
            'xml'  => self::toXml($header, $rows, $fullPath),
            'pptx' => self::toPptx($header, $rows, $fullPath),
            default => throw new \InvalidArgumentException("Unsupported export format [{$format}]."),
        };
    }

    private static function toCsv(array $header, array $rows, string $fullPath): void
    {
        $handle = fopen($fullPath, 'w');
        if (!$handle) {
            throw new \RuntimeException('Could not write the report file.');
        }
        // UTF-8 BOM so Excel opens Arabic-containing CSVs without mangling them.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $header);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);
    }

    private static function toJson(array $header, array $rows, string $fullPath): void
    {
        $records = array_map(fn ($row) => array_combine($header, array_pad($row, count($header), null)), $rows);
        $payload = json_encode(['columns' => $header, 'rows' => $records], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (file_put_contents($fullPath, $payload) === false) {
            throw new \RuntimeException('Could not write the report file.');
        }
    }

    /**
     * Plain UTF-8 XML — one <row> per record, one child element per
     * column (element name is the header text, slugified into a valid
     * XML name). Same "column -> element" shape spreadsheet/BI tools
     * expect from a generic tabular XML export.
     */
    private static function toXml(array $header, array $rows, string $fullPath): void
    {
        $slug = function (string $label): string {
            $tag = preg_replace('/[^A-Za-z0-9_]+/', '_', trim($label));
            $tag = trim($tag, '_');
            if ($tag === '' || preg_match('/^[0-9]/', $tag)) {
                $tag = 'col_' . $tag;
            }
            return $tag;
        };
        $tags = array_map($slug, $header);
        $escape = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<rows>\n";
        foreach ($rows as $row) {
            $xml .= "  <row>\n";
            foreach ($row as $i => $value) {
                $tag = $tags[$i] ?? ('col_' . $i);
                $xml .= "    <{$tag}>" . $escape($value) . "</{$tag}>\n";
            }
            $xml .= "  </row>\n";
        }
        $xml .= "</rows>\n";

        if (file_put_contents($fullPath, $xml) === false) {
            throw new \RuntimeException('Could not write the report file.');
        }
    }

    /**
     * Minimal but valid .xlsx (a zip of OOXML parts) — one sheet, cell
     * values written inline (t="inlineStr") rather than via a shared-
     * strings table, which keeps this to four small XML parts instead of
     * five and is perfectly valid OOXML. UTF-8 throughout, so Arabic
     * content (unlike the PDF path below) renders correctly in Excel.
     */
    private static function toXlsx(array $header, array $rows, string $fullPath): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is required to export Excel files.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($fullPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the Excel file.');
        }

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
            '<Default Extension="xml" ContentType="application/xml"/>' .
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
            '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
            '</Types>');

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
            '</Relationships>');

        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
            '</Relationships>');

        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
            '<sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets>' .
            '</workbook>');

        $colLetter = function (int $index): string {
            $letters = '';
            $index++;
            while ($index > 0) {
                $rem = ($index - 1) % 26;
                $letters = chr(65 + $rem) . $letters;
                $index = intdiv($index - 1, 26);
            }
            return $letters;
        };

        $escape = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $sheetRows = '<row r="1">';
        foreach ($header as $c => $value) {
            $sheetRows .= '<c r="' . $colLetter($c) . '1" t="inlineStr"><is><t>' . $escape($value) . '</t></is></c>';
        }
        $sheetRows .= '</row>';

        foreach ($rows as $r => $row) {
            $rowNum = $r + 2;
            $sheetRows .= '<row r="' . $rowNum . '">';
            foreach ($row as $c => $value) {
                $sheetRows .= '<c r="' . $colLetter($c) . $rowNum . '" t="inlineStr"><is><t>' . $escape($value) . '</t></is></c>';
            }
            $sheetRows .= '</row>';
        }

        $zip->addFromString('xl/worksheets/sheet1.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<sheetData>' . $sheetRows . '</sheetData>' .
            '</worksheet>');

        $zip->close();
    }

    /**
     * PDF حقيقي (mPDF: Unicode + عربي + جدول منسّق) لو متسطّب، وإلا الكاتب
     * اليدوي البسيط تحت (Latin-1 بس).
     */
    private static function toPdfBest(array $header, array $rows, string $fullPath, ?string $title): void
    {
        if (!class_exists(\Mpdf\Mpdf::class)) {
            @error_log('[PdfWriter] mPDF class not found (vendor/mpdf missing) - using basic PDF writer');
        } else {
            try {
                \App\Services\Export\PdfWriter::writeTable($fullPath, $title ?: 'Report', $header, $rows);
                if (is_file($fullPath) && str_starts_with((string) file_get_contents($fullPath, false, null, 0, 5), '%PDF')) {
                    @error_log('[PdfWriter] mPDF OK: ' . ($title ?: 'Report'));
                    return;
                }
                @error_log('[PdfWriter] mPDF produced no valid PDF - using basic PDF writer');
            } catch (\Throwable $e) {
                @error_log('[PdfWriter] mPDF failed in ReportExportWriter: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
            }
        }
        self::toPdf($header, $rows, $fullPath);
    }

    /**
     * Minimal hand-rolled PDF 1.4 — a plain text table, paginated at ~50
     * rows/page, using the base-14 Helvetica font (no font file to
     * embed, so no extra dependency). LIMITATION: Helvetica/WinAnsi has
     * no Arabic glyphs and this writer doesn't do RTL shaping — Arabic
     * cells are transliterated to a "[non-Latin text — see CSV/XLSX
     * export]" placeholder rather than emitting bytes that would render
     * as blank boxes. English/numeric reports are unaffected.
     */
    private static function toPdf(array $header, array $rows, string $fullPath): void
    {
        $escapeText = function ($value): string {
            $value = (string) $value;
            if (preg_match('/[\x{0600}-\x{06FF}]/u', $value)) {
                $value = '[non-Latin text -- see CSV/XLSX export]';
            }
            // Strip anything outside printable ASCII (WinAnsi-safe) so we never emit an unrenderable byte.
            $value = preg_replace('/[^\x20-\x7E]/', '', $value) ?? '';
            $value = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
            return $value;
        };

        $colWidth = 26;
        $formatRow = function (array $row) use ($colWidth, $escapeText): string {
            return implode('  ', array_map(fn ($v) => str_pad(mb_substr($escapeText($v), 0, $colWidth), $colWidth), $row));
        };

        $lines = [$formatRow($header), str_repeat('-', (count($header) * ($colWidth + 2)))];
        foreach ($rows as $row) {
            $lines[] = $formatRow($row);
        }

        $rowsPerPage = 50;
        $pages = array_chunk($lines, $rowsPerPage);
        if (empty($pages)) {
            $pages = [['(no data)']];
        }

        $objects = [];
        $pageIds = [];
        $objIndex = 4; // 1=Catalog, 2=Pages, 3=Font — page/content objects start at 4

        foreach ($pages as $pageLines) {
            $pageObjId = $objIndex++;
            $contentObjId = $objIndex++;

            $stream = "BT /F1 8 Tf 12 TL 40 760 Td\n";
            foreach ($pageLines as $line) {
                $stream .= '(' . $line . ") Tj T*\n";
            }
            $stream .= "ET";

            $objects[$contentObjId] = "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}\nendstream";
            $objects[$pageObjId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] "
                . "/Resources << /Font << /F1 3 0 R >> >> /Contents {$contentObjId} 0 R >>";
            $pageIds[] = $pageObjId;
        }

        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[2] = "<< /Type /Pages /Kids [" . implode(' ', array_map(fn ($id) => "{$id} 0 R", $pageIds)) . "] /Count " . count($pageIds) . " >>";
        $objects[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";

        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $xrefStart = strlen($pdf);
        $maxId = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($maxId + 1) . "\n0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            $pdf .= isset($offsets[$id]) ? sprintf("%010d 00000 n \n", $offsets[$id]) : "0000000000 00000 f \n";
        }
        $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefStart}\n%%EOF";

        if (file_put_contents($fullPath, $pdf) === false) {
            throw new \RuntimeException('Could not write the report file.');
        }
    }

    /**
     * Minimal but valid .docx (a zip of OOXML parts) — single-section
     * document, one table, header row bold + shaded. Each cell's
     * paragraph is direction-aware: cells containing Arabic get
     * w:bidi + right alignment + an rtl run, everything else stays
     * plain left-aligned LTR — so a report mixing English column
     * headers with Arabic names/titles renders both correctly in the
     * same table, which the hand-rolled PDF export above cannot do.
     */
    private static function toDocx(array $header, array $rows, string $fullPath): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is required to export Word files.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($fullPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the Word file.');
        }

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
            '<Default Extension="xml" ContentType="application/xml"/>' .
            '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>' .
            '</Types>');

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>' .
            '</Relationships>');

        $escape = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $isArabic = fn ($v) => (bool) preg_match('/[\x{0600}-\x{06FF}]/u', (string) $v);

        $cell = function ($value, bool $bold = false) use ($escape, $isArabic): string {
            $rtl = $isArabic($value);
            $pPr = '<w:pPr>' . ($rtl ? '<w:bidi/><w:jc w:val="right"/>' : '<w:jc w:val="left"/>') . '</w:pPr>';
            $rPr = ($bold || $rtl) ? ('<w:rPr>' . ($bold ? '<w:b/>' : '') . ($rtl ? '<w:rtl/>' : '') . '</w:rPr>') : '';
            $text = '<w:t xml:space="preserve">' . $escape($value) . '</w:t>';
            $shd = $bold ? '<w:shd w:val="clear" w:fill="EDEDED"/>' : '';
            return '<w:tc><w:tcPr><w:tcBorders/>' . $shd . '</w:tcPr><w:p>' . $pPr . '<w:r>' . $rPr . $text . '</w:r></w:p></w:tc>';
        };

        $tblBorders = '<w:tblBorders>'
            . '<w:top w:val="single" w:sz="4" w:color="CCCCCC"/>'
            . '<w:left w:val="single" w:sz="4" w:color="CCCCCC"/>'
            . '<w:bottom w:val="single" w:sz="4" w:color="CCCCCC"/>'
            . '<w:right w:val="single" w:sz="4" w:color="CCCCCC"/>'
            . '<w:insideH w:val="single" w:sz="4" w:color="CCCCCC"/>'
            . '<w:insideV w:val="single" w:sz="4" w:color="CCCCCC"/>'
            . '</w:tblBorders>';

        $table = '<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/>' . $tblBorders . '</w:tblPr>';
        $table .= '<w:tr>' . implode('', array_map(fn ($h) => $cell($h, true), $header)) . '</w:tr>';
        foreach ($rows as $row) {
            $table .= '<w:tr>' . implode('', array_map(fn ($v) => $cell($v, false), $row)) . '</w:tr>';
        }
        $table .= '</w:tbl>';

        $body = $table . '<w:sectPr><w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/>'
            . '<w:pgMar w:top="720" w:right="720" w:bottom="720" w:left="720"/></w:sectPr>';

        $zip->addFromString('word/document.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">' .
            '<w:body>' . $body . '</w:body></w:document>');

        $zip->close();
    }

    /**
     * Minimal but valid .pptx (a zip of OOXML parts) — one slide per
     * page of ~15 rows, each slide holding a title placeholder and a
     * DrawingML graphicFrame table (the native PowerPoint table
     * element, not a picture) so cell text stays selectable/editable.
     * Same ZipArchive-of-XML-parts approach as toXlsx()/toDocx() above,
     * just for the presentationml part set (widescreen 16:9, EMU units).
     */
    private static function toPptx(array $header, array $rows, string $fullPath): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is required to export PowerPoint files.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($fullPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the PowerPoint file.');
        }

        $escape = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $rowsPerSlide = 15;
        $pages = array_chunk($rows, $rowsPerSlide) ?: [[]];
        $slideCount = count($pages);

        // -- fixed, slide-count-independent parts --------------------------
        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
            '<Default Extension="xml" ContentType="application/xml"/>' .
            '<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>' .
            '<Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>' .
            '<Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>' .
            '<Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>' .
            implode('', array_map(
                fn ($i) => '<Override PartName="/ppt/slides/slide' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>',
                range(1, $slideCount)
            )) .
            '</Types>');

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>' .
            '</Relationships>');

        $zip->addFromString('ppt/presentation.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<p:presentation xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" ' .
            'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" ' .
            'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">' .
            '<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rIdMaster"/></p:sldMasterIdLst>' .
            '<p:sldIdLst>' . implode('', array_map(
                fn ($i) => '<p:sldId id="' . (255 + $i) . '" r:id="rIdSlide' . $i . '"/>',
                range(1, $slideCount)
            )) . '</p:sldIdLst>' .
            '<p:sldSz cx="12192000" cy="6858000" type="screen16x9"/>' .
            '<p:notesSz cx="6858000" cy="9144000"/>' .
            '</p:presentation>');

        $presRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rIdMaster" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>' .
            implode('', array_map(
                fn ($i) => '<Relationship Id="rIdSlide' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide' . $i . '.xml"/>',
                range(1, $slideCount)
            )) . '</Relationships>';
        $zip->addFromString('ppt/_rels/presentation.xml.rels', $presRels);

        $zip->addFromString('ppt/theme/theme1.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="UIP Export">' .
            '<a:themeElements><a:clrScheme name="UIP"><a:dk1><a:sysClr val="windowText" lastClr="000000"/></a:dk1>' .
            '<a:lt1><a:sysClr val="window" lastClr="FFFFFF"/></a:lt1><a:dk2><a:srgbClr val="1F3864"/></a:dk2>' .
            '<a:lt2><a:srgbClr val="EDEDED"/></a:lt2><a:accent1><a:srgbClr val="2E5AAC"/></a:accent1>' .
            '<a:accent2><a:srgbClr val="70AD47"/></a:accent2><a:accent3><a:srgbClr val="FFC000"/></a:accent3>' .
            '<a:accent4><a:srgbClr val="C00000"/></a:accent4><a:accent5><a:srgbClr val="7030A0"/></a:accent5>' .
            '<a:accent6><a:srgbClr val="808080"/></a:accent6><a:hlink><a:srgbClr val="0563C1"/></a:hlink>' .
            '<a:folHlink><a:srgbClr val="954F72"/></a:folHlink></a:clrScheme>' .
            '<a:fontScheme name="UIP"><a:majorFont><a:latin typeface="Calibri"/></a:majorFont>' .
            '<a:minorFont><a:latin typeface="Calibri"/></a:minorFont></a:fontScheme>' .
            '<a:fmtScheme name="UIP"><a:fillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill>' .
            '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill>' .
            '</a:fillStyleLst><a:lnStyleLst><a:ln><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln>' .
            '<a:ln><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln>' .
            '<a:ln><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln></a:lnStyleLst>' .
            '<a:effectStyleLst><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle>' .
            '<a:effectStyle><a:effectLst/></a:effectStyle></a:effectStyleLst>' .
            '<a:bgFillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill>' .
            '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill>' .
            '</a:bgFillStyleLst></a:fmtScheme></a:themeElements></a:theme>');

        $zip->addFromString('ppt/slideMasters/slideMaster1.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<p:sldMaster xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" ' .
            'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" ' .
            'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">' .
            '<p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>' .
            '<p:grpSpPr/></p:spTree></p:cSld>' .
            '<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" ' .
            'accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>' .
            '<p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/></p:sldLayoutIdLst>' .
            '</p:sldMaster>');

        $zip->addFromString('ppt/slideMasters/_rels/slideMaster1.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>' .
            '</Relationships>');

        $zip->addFromString('ppt/slideLayouts/slideLayout1.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<p:sldLayout xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" ' .
            'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" ' .
            'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" type="blank">' .
            '<p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>' .
            '<p:grpSpPr/></p:spTree></p:cSld>' .
            '</p:sldLayout>');

        $zip->addFromString('ppt/slideLayouts/_rels/slideLayout1.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/>' .
            '</Relationships>');

        // -- one slide per page of rows, each with a title + table --------
        $colCount = max(1, count($header));
        $tableWidth = 11000000; // EMU, fits a 12192000-wide 16:9 slide with margins
        $colWidth = intdiv($tableWidth, $colCount);

        $gridCols = implode('', array_fill(0, $colCount, '<a:gridCol w="' . $colWidth . '"/>'));

        $cellXml = function ($value, bool $header) use ($escape): string {
            $fill = $header ? '<a:solidFill><a:srgbClr val="2E5AAC"/></a:solidFill>' : '';
            $color = $header ? '<a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill>' : '<a:solidFill><a:srgbClr val="000000"/></a:solidFill>';
            $bold = $header ? ' b="1"' : '';
            return '<a:tc><a:txBody><a:bodyPr/><a:lstStyle/><a:p><a:r><a:rPr lang="en-US" sz="1200"' . $bold . '>' . $color . '</a:rPr>' .
                '<a:t>' . $escape($value) . '</a:t></a:r></a:p></a:txBody>' .
                '<a:tcPr>' . $fill . '</a:tcPr></a:tc>';
        };

        for ($p = 0; $p < $slideCount; $p++) {
            $pageRows = $pages[$p];
            $rowHeight = 370000;

            $rowsXml = '<a:tr h="' . $rowHeight . '">' . implode('', array_map(fn ($h) => $cellXml($h, true), $header)) . '</a:tr>';
            foreach ($pageRows as $row) {
                $rowsXml .= '<a:tr h="' . $rowHeight . '">' . implode('', array_map(fn ($v) => $cellXml($v, false), $row)) . '</a:tr>';
            }

            $slideNum = $p + 1;
            $title = 'Export' . ($slideCount > 1 ? ' (page ' . $slideNum . ' of ' . $slideCount . ')' : '');

            $slideXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
                '<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" ' .
                'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" ' .
                'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">' .
                '<p:cSld><p:spTree>' .
                '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/>' .
                '<p:sp><p:nvSpPr><p:cNvPr id="2" name="Title"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr/></p:nvSpPr>' .
                '<p:spPr><a:xfrm><a:off x="457200" y="274638"/><a:ext cx="11277600" cy="581025"/></a:xfrm></p:spPr>' .
                '<p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:r><a:rPr lang="en-US" sz="2400" b="1"/>' .
                '<a:t>' . $escape($title) . '</a:t></a:r></a:p></p:txBody></p:sp>' .
                '<p:graphicFrame>' .
                '<p:nvGraphicFramePr><p:cNvPr id="3" name="Table"/><p:cNvGraphicFramePr><a:graphicFrameLocks noGrp="1"/></p:cNvGraphicFramePr><p:nvPr/></p:nvGraphicFramePr>' .
                '<p:xfrm><a:off x="457200" y="1000125"/><a:ext cx="' . $tableWidth . '" cy="' . ($rowHeight * (count($pageRows) + 1)) . '"/></p:xfrm>' .
                '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/table">' .
                '<a:tbl><a:tblPr firstRow="1" bandRow="1"/><a:tblGrid>' . $gridCols . '</a:tblGrid>' . $rowsXml . '</a:tbl>' .
                '</a:graphicData></a:graphic></p:graphicFrame>' .
                '</p:spTree></p:cSld></p:sld>';

            $zip->addFromString('ppt/slides/slide' . $slideNum . '.xml', $slideXml);
            $zip->addFromString('ppt/slides/_rels/slide' . $slideNum . '.xml.rels',
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
                '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
                '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>' .
                '</Relationships>');
        }

        $zip->close();
    }
}
