<?php
/**
 * Service: SpreadsheetWriter
 * Status: Active - Phase 13 (Data Analysis Portal — Excel export)
 *
 * Writes a genuine, Excel-openable .xlsx file (Office Open XML) using only
 * PHP's built-in `ZipArchive` extension (bundled with XAMPP/PHP by default)
 * — no Composer/PhpSpreadsheet dependency needed. Produces a single-sheet
 * workbook: header row + data rows, numeric values stored as real numbers,
 * everything else as inline strings so no shared-strings table is required.
 * @package UIP
 */

namespace App\Services\Export;

class SpreadsheetWriter
{
    /**
     * @param string $path absolute path to write the .xlsx file to
     * @param string[] $header
     * @param array<int,array<int,mixed>> $rows
     */
    public static function write(string $path, array $header, array $rows, bool $rtl = false): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is required for Excel exports.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the Excel file.');
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypes());
        $zip->addFromString('_rels/.rels', self::rootRels());
        $zip->addFromString('xl/workbook.xml', self::workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels());
        $zip->addFromString('xl/worksheets/sheet1.xml', self::sheet($header, $rows, $rtl));
        $zip->close();
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>';
    }

    private static function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Export" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>';
    }

    /**
     * @param string[] $header
     * @param array<int,array<int,mixed>> $rows
     */
    private static function sheet(array $header, array $rows, bool $rtl = false): string
    {
        $allRows = array_merge([$header], $rows);
        $xmlRows = '';
        foreach ($allRows as $i => $row) {
            $rowNum = $i + 1;
            $cells = '';
            foreach (array_values($row) as $colIdx => $value) {
                $cells .= self::cellXml(self::columnLetter($colIdx) . $rowNum, $value);
            }
            $xmlRows .= '<row r="' . $rowNum . '">' . $cells . '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . ($rtl ? '<sheetViews><sheetView rightToLeft="1" workbookViewId="0"/></sheetViews>' : '')
            . '<sheetData>' . $xmlRows . '</sheetData>'
            . '</worksheet>';
    }

    private static function cellXml(string $ref, $value): string
    {
        if ($value === null || $value === '') {
            return '<c r="' . $ref . '"/>';
        }

        // A genuinely numeric, non-leading-zero value is stored as a real
        // number so Excel treats it as such (sums, right-alignment, etc.).
        if (is_numeric($value) && !preg_match('/^0[0-9]/', (string) $value)) {
            return '<c r="' . $ref . '"><v>' . self::escape((string) $value) . '</v></c>';
        }

        return '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . self::escape((string) $value) . '</t></is></c>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** 0 -> A, 1 -> B, ... 25 -> Z, 26 -> AA ... */
    private static function columnLetter(int $index): string
    {
        $letter = '';
        $index++;
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $index = intdiv($index - $mod, 26);
        }
        return $letter;
    }
}
