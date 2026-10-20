<?php

namespace App\Services;

use App\Models\ApiFile;
use App\Repositories\ApiFileRepository;
use Illuminate\Http\UploadedFile;

/**
 * نسخة طبق الأصل من app/Services/ApiFileService.php القديمة — نفس
 * المنطق حرف بحرف، بس $file بقى Illuminate\Http\UploadedFile بدل الـ
 * $_FILES-array (شوف FileUploadService لتفاصيل التحويل).
 */
class ApiFileService
{
    public function __construct(
        private ApiFileRepository $files,
        private FileUploadService $upload
    ) {
    }

    /** @throws \RuntimeException on validation failure (message is safe to show the user) — same as FileUploadService::store() */
    public function upload(?UploadedFile $file, int $uploadedBy, string $category = 'general'): ApiFile
    {
        $category = $category !== '' ? $category : 'general';
        if (!config("upload.allowed.{$category}", null)) {
            $category = 'general';
        }

        $stored = $this->upload->store($file, $category, (string) $uploadedBy);

        return $this->files->create([
            'uploaded_by'   => $uploadedBy,
            'category'      => $category,
            'original_name' => $stored['original_name'],
            'stored_path'   => $stored['stored_path'],
            'mime_type'     => $stored['mime_type'],
            'extension'     => $stored['extension'],
            'size_bytes'    => $stored['size_bytes'],
            'is_deleted'    => 0,
        ]);
    }

    public function search($userId, array $filters = []): array
    {
        return $this->files->search($userId, $filters);
    }

    public function findOwned($id, $userId): ?ApiFile
    {
        return $this->files->findOwned($id, $userId);
    }

    public function recordDownload($id): void
    {
        $this->files->incrementDownloadCount($id);
    }

    /** بيمسح الصف (soft) والملف الفعلي مع بعض — عكس الخدمات الأخرى، مفيش حد تاني بيرجع للصف ده. */
    public function delete($id, $userId): bool
    {
        $file = $this->files->findOwned($id, $userId);
        if (!$file) {
            return false;
        }
        $ok = $this->files->softDelete($id, $userId);
        if ($ok) {
            $this->upload->delete($file->stored_path);
        }
        return $ok;
    }
}
