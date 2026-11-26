<?php

namespace App\Services\Export;

/**
 * كاتب موحّد لكل صيغ تصدير الجداول في المنصة (Reports + Exports):
 * pdf / docx / xlsx / csv / json / html / md / txt / xml.
 *
 * بيعيد استخدام الكُتّاب الموجودين (PdfWriter / SpreadsheetWriter /
 * CsvWriter / JsonWriter / ReportDocumentWriter) بدل ما يكتب منطق جديد،
 * فكل صفحة (Reports / Exports) بتطلّع نفس الملف بنفس الشكل، وبنفس
 * القائمة اللي ExportMenu في الفرونت بيعرضها. $rtl = true لما لغة الملف عربي.
 */
final class ExportFileBuilder
{
    public const FORMATS = ['pdf', 'docx', 'xlsx', 'csv', 'json', 'html', 'md', 'txt', 'xml'];

    public static function supports(string $format): bool
    {
        return in_array($format, self::FORMATS, true);
    }

    public static function mime(string $format): string
    {
        return [
            'pdf'  => 'application/pdf',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'csv'  => 'text/csv; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
        ][$format] ?? ReportDocumentWriter::mime($format);
    }

    /**
     * @param string[] $header
     * @param array<int,array<int,mixed>> $rows
     */
    public static function write(string $format, string $path, string $title, array $header, array $rows, bool $rtl = false): void
    {
        $header = array_values($header);
        $rows = array_map('array_values', array_values($rows));

        switch ($format) {
            case 'csv':
                CsvWriter::writeTable($path, $header, $rows);
                return;

            case 'xlsx':
                SpreadsheetWriter::write($path, $header, $rows, $rtl);
                return;

            case 'pdf':
                PdfWriter::writeTable($path, $title, $header, $rows, $rtl);
                return;

            case 'json':
                $objects = [];
                foreach ($rows as $row) {
                    $obj = [];
                    foreach ($header as $i => $label) {
                        $key = trim((string) $label) !== '' ? (string) $label : 'column_' . ($i + 1);
                        $obj[$key] = $row[$i] ?? null;
                    }
                    $objects[] = $obj;
                }
                JsonWriter::write($path, $objects);
                return;

            case 'docx':
            case 'html':
            case 'md':
            case 'txt':
            case 'xml':
                $meta = [
                    ExportLocalizer::text('Generated At') => date('Y-m-d H:i'),
                ];
                $sections = [[
                    'title'  => ExportLocalizer::recordsLabel(count($rows)),
                    'header' => $header,
                    'rows'   => $rows,
                ]];
                ReportDocumentWriter::write($format, $path, $title, $meta, $sections, $rtl);
                return;
        }

        throw new \InvalidArgumentException("Unsupported export format [{$format}].");
    }
}
