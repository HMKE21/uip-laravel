<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * ملف اتجمّع من رفع بالأجزاء (ChunkedUploadService). بيتصرّف زي أي UploadedFile عادي
 * (test mode = المسار ده مش ملف PHP tmp، فـ move() بتعمل rename عادي) بس MessagingService بيعرفه من النوع
 * عشان يطبّق سقف الحجم الخاص بالرفع بالأجزاء.
 */
class ChunkedUploadedFile extends UploadedFile
{
    public function __construct(string $path, string $originalName, ?string $mimeType, public readonly string $uploadId = '')
    {
        parent::__construct($path, $originalName, $mimeType, null, true);
    }
}
