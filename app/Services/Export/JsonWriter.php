<?php

namespace App\Services\Export;

/**
 * منقولة من app/Services/Export/JsonWriter.php القديمة — تصدير JSON
 * مُهيكل. على عكس CSV/XLSX/PDF (كلها جداول مسطّحة)، ده بيحافظ على الشكل
 * المتداخل الحقيقي (كائن scores، array issues، حقول قرار الأدمن، array
 * النسخ التاريخية) عشان المستهلك اللي بيستخدم التصدير برمجيًا مايضطرش
 * يعيد تفكيك جدول مسطّح لبنية.
 */
class JsonWriter
{
    /**
     * @param string $path المسار المطلق لكتابة ملف .json فيه
     * @param array<string,mixed> $data
     */
    public static function write(string $path, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Could not encode export data as JSON: ' . json_last_error_msg());
        }
        if (file_put_contents($path, $json) === false) {
            throw new \RuntimeException('Could not create the JSON file.');
        }
    }
}
