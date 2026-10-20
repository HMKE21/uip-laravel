<?php

namespace App\Support;

/**
 * قارئ ملفات CSV/XLSX من غير أي composer package (زي أسلوب Totp.php —
 * php-spreadsheet مش موجودة في composer.json، فبنستخدم ZipArchive +
 * SimpleXML المدمجين في PHP لقراءة XLSX يدويًا). الاستخدام الحالي:
 * AcademicStaffApiController::import() -> AcademicStaffManagementService::
 * importRows().
 *
 * readAssoc() بيرجع array من الصفوف كـ associative array مفتاحه أسماء
 * أعمدة الهيدر (lowercase, trimmed) — أول صف في الملف لازم يكون هيدر.
 */
class TabularFileReader
{
    /**
     * @return array<int,array<string,string>>
     */
    public static function readAssoc(string $path, string $extension): array
    {
        $extension = strtolower($extension);

        return match ($extension) {
            'csv', 'txt' => self::readCsv($path),
            'xlsx' => self::readXlsx($path),
            default => throw new \RuntimeException('Unsupported file type. Please upload a .csv or .xlsx file.'),
        };
    }

    private static function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Could not open the uploaded file.');
        }

        // BOM اختياري (Excel بيحطه غالبًا) — بنشيله من أول بايتات لو موجود
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            return [];
        }
        $header = self::normalizeHeader($header);

        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            if (count(array_filter($line, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue; // صف فاضي بالكامل — يتجاهل
            }
            $rows[] = self::zipHeaderWithRow($header, $line);
        }
        fclose($handle);

        return $rows;
    }

    /**
     * XLSX هو zip فيه xl/worksheets/sheet1.xml + xl/sharedStrings.xml —
     * بنقرأهم بـ ZipArchive/SimpleXML من غير أي مكتبة خارجية. بياخد بس
     * أول Sheet في الملف.
     */
    private static function readXlsx(string $path): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Server is missing the zip extension required to read .xlsx files.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Could not open the .xlsx file — it may be corrupted.');
        }

        $sharedStrings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            $sharedStrings = self::parseSharedStrings($sharedXml);
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if ($sheetXml === false) {
            throw new \RuntimeException('Could not read the first sheet of the .xlsx file.');
        }

        $grid = self::parseSheetToGrid($sheetXml, $sharedStrings);
        if (empty($grid)) {
            return [];
        }

        $header = self::normalizeHeader(array_shift($grid));

        $rows = [];
        foreach ($grid as $line) {
            if (count(array_filter($line, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $rows[] = self::zipHeaderWithRow($header, $line);
        }

        return $rows;
    }

    /** @return string[] */
    private static function parseSharedStrings(string $xml): array
    {
        $doc = simplexml_load_string($xml);
        if ($doc === false) {
            return [];
        }
        $doc->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $strings = [];
        foreach ($doc->si as $si) {
            // نص بسيط (t) أو نص متعدد التنسيقات (r/t متكرر) — بنجمع كل الأجزاء
            if (isset($si->t)) {
                $strings[] = (string) $si->t;
            } else {
                $text = '';
                foreach ($si->r as $r) {
                    $text .= (string) $r->t;
                }
                $strings[] = $text;
            }
        }

        return $strings;
    }

    /** @return array<int,array<int,string>> صفوف مفهرسة برقم عمود (0-based) */
    private static function parseSheetToGrid(string $xml, array $sharedStrings): array
    {
        $doc = simplexml_load_string($xml);
        if ($doc === false) {
            return [];
        }

        $grid = [];
        foreach ($doc->sheetData->row as $row) {
            $line = [];
            foreach ($row->c as $cell) {
                $ref = (string) $cell['r']; // e.g. "C5"
                $colIndex = self::columnLetterToIndex(preg_replace('/\d+/', '', $ref));
                $type = (string) $cell['t'];

                if (!isset($cell->v)) {
                    $value = '';
                } elseif ($type === 's') {
                    $value = $sharedStrings[(int) $cell->v] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = (string) ($cell->is->t ?? '');
                } else {
                    $value = (string) $cell->v;
                }

                $line[$colIndex] = $value;
            }

            if (empty($line)) {
                continue;
            }
            $maxIndex = max(array_keys($line));
            $normalized = [];
            for ($i = 0; $i <= $maxIndex; $i++) {
                $normalized[] = $line[$i] ?? '';
            }
            $grid[] = $normalized;
        }

        return $grid;
    }

    private static function columnLetterToIndex(string $letters): int
    {
        $letters = strtoupper($letters);
        $index = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $index = $index * 26 + (ord($letters[$i]) - ord('A') + 1);
        }
        return $index - 1;
    }

    /** @return string[] */
    private static function normalizeHeader(array $header): array
    {
        return array_map(fn ($h) => mb_strtolower(trim((string) $h)), $header);
    }

    /** @return array<string,string> */
    private static function zipHeaderWithRow(array $header, array $line): array
    {
        $row = [];
        foreach ($header as $i => $key) {
            if ($key === '') {
                continue;
            }
            $row[$key] = trim((string) ($line[$i] ?? ''));
        }
        return $row;
    }
}
