<?php

namespace App\Services\Export;

/**
 * منقولة من app/Services/Export/CsvWriter.php القديمة — كاتب CSV بلا أي
 * اعتمادية خارجية، بنفس اتفاقية الميثودز الثابتة زي SpreadsheetWriter/
 * PdfWriter في نفس الـ namespace. BOM UTF-8 بيتكتب الأول عشان Excel يفتح
 * النص العربي صح بدل ما يشوّهه.
 *
 * بتدعم شكلين:
 *  - writeTable(): جدول واحد مسطّح header+rows (بيطابق توقيع
 *    SpreadsheetWriter::write()/PdfWriter::writeTable()).
 *  - writeSections(): عدة جداول مسماة بعد بعض في نفس الملف (سطر فاضي +
 *    صف علامة "## Title" بينهم) — تستخدمها تقارير AI Code Review تحت،
 *    اللي فيها كذا جدول مميز (Project Info / Scores / Issues / Admin
 *    Notes) في تصدير واحد.
 */
class CsvWriter
{
    /**
     * @param string $path المسار المطلق لكتابة ملف .csv فيه
     * @param string[] $header
     * @param array<int,array<int,mixed>> $rows
     */
    public static function writeTable(string $path, array $header, array $rows): void
    {
        self::writeSections($path, [['title' => null, 'header' => $header, 'rows' => $rows]]);
    }

    /**
     * @param string $path المسار المطلق لكتابة ملف .csv فيه
     * @param array<int,array{title:?string,header:string[],rows:array<int,array<int,mixed>>}> $sections
     */
    public static function writeSections(string $path, array $sections): void
    {
        $fh = fopen($path, 'w');
        if ($fh === false) {
            throw new \RuntimeException('Could not create the CSV file.');
        }

        // UTF-8 BOM عشان Excel/Windows يكتشفوا الترميز صح للنص العربي.
        fwrite($fh, "\xEF\xBB\xBF");

        foreach ($sections as $i => $section) {
            if ($i > 0) {
                fputcsv($fh, []);
            }
            if (!empty($section['title'])) {
                fputcsv($fh, ['## ' . $section['title']]);
            }
            if (!empty($section['header'])) {
                fputcsv($fh, $section['header']);
            }
            foreach ($section['rows'] as $row) {
                fputcsv($fh, array_values($row));
            }
        }

        fclose($fh);
    }
}
