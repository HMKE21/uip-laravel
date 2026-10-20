<?php

namespace App\Services;

use App\Models\SecurityReportFile;
use App\Repositories\SecurityReportFileRepository;
use Illuminate\Http\UploadedFile;

/**
 * منقولة من app/Services/SecurityReportFileService.php القديمة — بند
 * 25 batch 5 (Report Management). Upload/replace (-> صف جديد في
 * security_report_file_versions)، أهلية المعاينة، عدادات
 * download/view، أرشفة/إلغاء أرشفة، وحذف نهائي آمن (صف الـ DB +
 * الملف/الملفات الفعلية، مُسجَّل). فوق FileUploadService العام (بيتحقق
 * ضد config('upload.allowed.security_reports') — PDF/XLSX/CSV/JSON/
 * XML/DOCX/PPTX) وSecurityReportFileRepository للتخزين، وكل تعديل
 * بيتسجل عبر AuditLogService — نفس شكل DataAnalysisReportFileService
 * بالظبط.
 */
class SecurityReportFileService
{
    /** الامتدادات اللي المتصفح يقدر يعرضها مباشرة من غير تحميل. */
    private const PREVIEWABLE = ['pdf'];

    public function __construct(
        private SecurityReportFileRepository $files,
        private FileUploadService $uploader,
        private AuditLogService $auditLog
    ) {
    }

    /**
     * @param array $meta title, description, category_id, tags(array of strings)
     * @throws \RuntimeException on validation failure (safe to show the user)
     */
    public function upload(int $userId, ?UploadedFile $file, array $meta, ?string $ip = null): SecurityReportFile
    {
        $title = trim((string) ($meta['title'] ?? ''));
        if ($title === '') {
            throw new \RuntimeException('Please give the report a title.');
        }
        if (!$file) {
            throw new \RuntimeException('Please choose a file to upload.');
        }

        $stored = $this->uploader->store($file, 'security_reports', 'u' . $userId);

        $reportFile = SecurityReportFile::create([
            'uploaded_by'       => $userId,
            'category_id'       => $meta['category_id'] ?: null,
            'title'             => $title,
            'description'       => $meta['description'] ?? null,
            'original_filename' => $stored['original_name'],
            'file_extension'    => $stored['extension'],
            'mime_type'         => $stored['mime_type'],
            'file_size_bytes'   => $stored['size_bytes'],
            'storage_path'      => $stored['stored_path'],
        ]);

        if (!empty($meta['tags'])) {
            $this->files->syncTags((int) $reportFile->id, $this->resolveTagIds($meta['tags']));
        }

        $this->files->createVersion([
            'security_report_file_id' => $reportFile->id,
            'version_number'          => 1,
            'storage_path'            => $stored['stored_path'],
            'original_filename'       => $stored['original_name'],
            'file_extension'          => $stored['extension'],
            'file_size_bytes'         => $stored['size_bytes'],
            'notes'                   => 'Initial upload',
            'uploaded_by'             => $userId,
        ]);

        $this->auditLog->record($userId, 'security.report_file.upload', 'security_report_file', $reportFile->id, null, [
            'title' => $title, 'filename' => $stored['original_name'],
        ], $ip);

        return $reportFile;
    }

    /** بيرفع نسخة جديدة من ملف تقرير موجود، ومياخد snapshot لتاريخ النسخ. */
    public function replace(int $userId, SecurityReportFile $reportFile, UploadedFile $file, ?string $notes = null, ?string $ip = null): void
    {
        $stored = $this->uploader->store($file, 'security_reports', 'u' . $userId);

        $nextVersion = $this->files->nextVersionNumber((int) $reportFile->id);
        $this->files->createVersion([
            'security_report_file_id' => $reportFile->id,
            'version_number'          => $nextVersion,
            'storage_path'            => $stored['stored_path'],
            'original_filename'       => $stored['original_name'],
            'file_extension'          => $stored['extension'],
            'file_size_bytes'         => $stored['size_bytes'],
            'notes'                   => $notes,
            'uploaded_by'             => $userId,
        ]);

        $reportFile->original_filename = $stored['original_name'];
        $reportFile->file_extension = $stored['extension'];
        $reportFile->mime_type = $stored['mime_type'];
        $reportFile->file_size_bytes = $stored['size_bytes'];
        $reportFile->storage_path = $stored['stored_path'];
        $reportFile->save();

        $this->auditLog->record($userId, 'security.report_file.replace', 'security_report_file', $reportFile->id, null, [
            'version' => $nextVersion,
        ], $ip);
    }

    public function archive(int $userId, SecurityReportFile $reportFile, ?string $ip = null): void
    {
        $this->files->setArchived((int) $reportFile->id, true);
        $this->auditLog->record($userId, 'security.report_file.archive', 'security_report_file', $reportFile->id, null, null, $ip);
    }

    public function unarchive(int $userId, SecurityReportFile $reportFile, ?string $ip = null): void
    {
        $this->files->setArchived((int) $reportFile->id, false);
        $this->auditLog->record($userId, 'security.report_file.unarchive', 'security_report_file', $reportFile->id, null, null, $ip);
    }

    /**
     * حذف نهائي: بتشيل صف الـ DB وكل ملف فعلي على الديسك (النسخة
     * الحالية + كل نسخة قديمة)، وبتسجل الإجراء في الـ Audit Log. تأكيد
     * الحذف نفسه بيعيش في زرار العميل (client-side).
     */
    public function delete(int $userId, SecurityReportFile $reportFile, array $versions, ?string $ip = null): void
    {
        foreach ($versions as $version) {
            $this->uploader->delete((string) $version['storage_path']);
        }
        $this->uploader->delete((string) $reportFile->storage_path);

        $this->files->delete((int) $reportFile->id);

        $this->auditLog->record($userId, 'security.report_file.delete', 'security_report_file', $reportFile->id, [
            'title' => $reportFile->title, 'filename' => $reportFile->original_filename,
        ], null, $ip);
    }

    public function recordDownload(SecurityReportFile $reportFile): void
    {
        $this->files->incrementDownloadCount((int) $reportFile->id);
    }

    public function recordView(SecurityReportFile $reportFile): void
    {
        $this->files->incrementViewCount((int) $reportFile->id);
    }

    public function previewable(string $extension): bool
    {
        return in_array(strtolower($extension), self::PREVIEWABLE, true);
    }

    /** @param array<int,string> $names */
    private function resolveTagIds(array $names): array
    {
        $ids = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $ids[] = $this->files->findOrCreateTag($name);
            }
        }
        return $ids;
    }
}
