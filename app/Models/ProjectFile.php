<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * يطابق موديل ProjectFile القديم بالظبط (migration 009 + 140 — معرض
 * الوسائط: file_type='video_link' صف بدون ملف مخزّن، external_url بس).
 * بند 4/5 كانوا بس محتاجين toRowArray()/forProjectLatest()؛ بند 11 (هنا)
 * بيكمّلها بالكامل: isMedia()/toGalleryArray() لتاب الـ Gallery.
 */
class ProjectFile extends Model
{
    protected $table = 'project_files';

    // الجدول فيه created_at بس (من غير updated_at) — من غير السطر ده Eloquent بيحاول يكتب updated_at ويفشل بـ SQLSTATE 42S22.
    public const UPDATED_AT = null;

    protected $fillable = [
        'project_id', 'uploaded_by', 'file_type', 'file_path',
        'original_name', 'mime_type', 'size_bytes',
        'version', 'is_latest', 'root_file_id',
        'external_url', 'caption', 'sort_order', 'is_featured',
        'thumbnail_path', 'duration_seconds',
    ];

    protected $casts = [
        'is_latest'   => 'boolean',
        'is_featured' => 'boolean',
    ];

    /** file_type القيم اللي المعرض بيعتبرها وسائط مرئية/قابلة للتشغيل، مقابل مستندات عادية. */
    private const MEDIA_TYPES = ['image', 'video', 'video_link'];

    /** مين رفع الملف (للعرض عند المراجع والفريق). */
    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isMedia(): bool
    {
        return in_array($this->file_type, self::MEDIA_TYPES, true);
    }

    /** لصف 'video_link': 'youtube' | 'vimeo' | null. */
    public function videoProvider(): ?string
    {
        if ($this->file_type !== 'video_link' || empty($this->external_url)) {
            return null;
        }
        if (preg_match('/(youtube\.com|youtu\.be)/i', $this->external_url)) {
            return 'youtube';
        }
        if (preg_match('/vimeo\.com/i', $this->external_url)) {
            return 'vimeo';
        }
        return null;
    }

    /** رابط تشغيل قابل للـ embed لصف video_link، أو null لو المزود/الـ id متعرفش. */
    public function embedUrl(): ?string
    {
        $provider = $this->videoProvider();
        if ($provider === 'youtube') {
            $id = self::extractYoutubeId((string) $this->external_url);
            return $id ? "https://www.youtube.com/embed/{$id}" : null;
        }
        if ($provider === 'vimeo') {
            $id = self::extractVimeoId((string) $this->external_url);
            return $id ? "https://player.vimeo.com/video/{$id}" : null;
        }
        return null;
    }

    /** رابط صورة مصغّرة: فيديو مرفوع بياخد الفريم اللي اتعمله probe، يوتيوب بياخد نمط الصورة القياسي (من غير API call). */
    public function thumbnailUrl(): ?string
    {
        if (!empty($this->thumbnail_path)) {
            return '/' . ltrim($this->thumbnail_path, '/');
        }
        if ($this->videoProvider() === 'youtube') {
            $id = self::extractYoutubeId((string) $this->external_url);
            return $id ? "https://img.youtube.com/vi/{$id}/hqdefault.jpg" : null;
        }
        return null;
    }

    public static function extractYoutubeId(string $url): ?string
    {
        if (preg_match('#(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|shorts/))([A-Za-z0-9_-]{6,})#i', $url, $m)) {
            return $m[1];
        }
        return null;
    }

    public static function extractVimeoId(string $url): ?string
    {
        if (preg_match('#vimeo\.com/(?:video/)?(\d+)#i', $url, $m)) {
            return $m[1];
        }
        return null;
    }

    /** يطابق ProjectFile::toRowArray() القديمة بالظبط — تاب الـ Files / جدول الملفات. */
    public function toRowArray(string $locale = 'en'): array
    {
        return [
            'id'            => $this->id,
            // نفس id لكل نسخ الملف — الـ viewer بيتتبّع بيه الملف بعد ما حفظ جديد يغيّر الـ id.
            'root_id'       => (int) ($this->root_file_id ?: $this->id),
            'original_name' => $this->original_name,
            'extension'     => strtoupper((string) pathinfo((string) $this->original_name, PATHINFO_EXTENSION)),
            'mime_type'     => $this->mime_type,
            'size_human'    => self::humanSize((int) $this->size_bytes),
            'size_bytes'    => (int) $this->size_bytes,
            'url'           => $this->file_path ? '/' . ltrim((string) $this->file_path, '/') : null,
            'version'       => (int) ($this->version ?: 1),
            'uploaded_by'   => $this->uploaded_by !== null ? (int) $this->uploaded_by : null,
            'uploader_name' => $this->uploader?->full_name,
            'created_at'    => $this->created_at,
        ];
    }

    /** يطابق ProjectFile::toGalleryArray() القديمة بالظبط — تاب الـ Gallery + صفحة المشروع العامة. */
    public function toGalleryArray(): array
    {
        return [
            'id'            => $this->id,
            'file_type'     => $this->file_type,
            'original_name' => $this->original_name,
            'caption'       => $this->caption,
            'sort_order'    => (int) $this->sort_order,
            'is_featured'   => (bool) $this->is_featured,
            'url'           => $this->file_path ? '/' . ltrim((string) $this->file_path, '/') : null,
            'external_url'  => $this->external_url,
            'embed_url'     => $this->embedUrl(),
            'thumbnail_url' => $this->thumbnailUrl(),
            'video_provider' => $this->videoProvider(),
            'duration_seconds' => $this->duration_seconds !== null ? (int) $this->duration_seconds : null,
        ];
    }

    private static function humanSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 KB';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);
        return round($bytes / (1024 ** $i), 1) . ' ' . $units[$i];
    }
}
