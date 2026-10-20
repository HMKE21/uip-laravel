<?php

namespace App\Services;

use App\Models\University;
use Illuminate\Http\UploadedFile;

/**
 * Stores the images a university stamps on its certificates (signature, stamp, dean signature).
 *
 * Order matters: the upload is checked and re-encoded BEFORE it is handed to FileUploadService,
 * so the original bytes the user sent never reach public/. Re-encoding through GD drops metadata and
 * anything appended to the file, and the dimension limits stop a certificate from loading a huge image.
 * FileUploadService then does its own generic checks (size, extension allow-list, content sniffing,
 * ClamAV) on the clean file and stores it under a random name (so a replaced image is never served
 * from a stale cache).
 */
class UniversityBrandingService
{
    public const MAX_KB = 1024;
    public const MAX_SIDE = 1200;
    public const MIN_SIDE = 40;

    public function __construct(private FileUploadService $uploads)
    {
    }

    /**
     * @throws \RuntimeException with a message that is safe to show to the user
     * @return string the new relative path (already saved on the university)
     */
    public function store(University $university, string $kind, ?UploadedFile $file): string
    {
        $column = University::BRANDING_KINDS[$kind] ?? null;
        if ($column === null) {
            throw new \RuntimeException('Unknown image type.');
        }
        if (!$file) {
            throw new \RuntimeException('No file was uploaded.');
        }
        if (!$file->isValid()) {
            throw new \RuntimeException('The upload failed. Please try again.');
        }
        if ($file->getSize() > self::MAX_KB * 1024) {
            throw new \RuntimeException('The image is too large. Maximum is ' . self::MAX_KB . ' KB.');
        }

        $clean = tempnam(sys_get_temp_dir(), 'brand_');
        if ($clean === false) {
            throw new \RuntimeException('Could not process the image.');
        }

        try {
            if (!@copy($file->getRealPath(), $clean)) {
                throw new \RuntimeException('Could not process the image.');
            }
            $this->normalizePng($clean);

            $cleanUpload = new UploadedFile($clean, $kind . '.png', 'image/png', null, true);
            $stored = $this->uploads->store($cleanUpload, 'branding', (string) $university->id, self::MAX_KB);
        } finally {
            if (is_file($clean)) {
                @unlink($clean);
            }
        }

        $old = $university->{$column};
        $university->{$column} = $stored['stored_path'];
        $university->save();

        if ($old && $old !== $stored['stored_path']) {
            $this->uploads->delete($old);
        }

        return $stored['stored_path'];
    }

    /** Removes one image (file and column). Returns the path that was removed, or null if there was none. */
    public function remove(University $university, string $kind): ?string
    {
        $column = University::BRANDING_KINDS[$kind] ?? null;
        if ($column === null) {
            throw new \RuntimeException('Unknown image type.');
        }

        $old = $university->{$column};
        $university->{$column} = null;
        $university->save();

        if ($old) {
            $this->uploads->delete($old);
        }

        return $old ?: null;
    }

    /**
     * Dean name / title printed under the right-hand line. Blank becomes null.
     *
     * @param array<string,mixed> $input
     * @throws \RuntimeException
     * @return array<string,?string> the values saved
     */
    public function saveDean(University $university, array $input): array
    {
        $saved = [];
        foreach (['dean_name_en', 'dean_name_ar', 'dean_title_en', 'dean_title_ar'] as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $value = $input[$field];
            if ($value !== null && !is_scalar($value)) {
                throw new \RuntimeException('Invalid value for ' . $field . '.');
            }
            $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value) ?? '');
            if (mb_strlen($value) > 200) {
                throw new \RuntimeException('The ' . $field . ' field must not exceed 200 characters.');
            }
            $saved[$field] = $value === '' ? null : $value;
        }

        if ($saved) {
            $university->fill($saved);
            $university->save();
        }

        return $saved;
    }

    /**
     * Checks that the file really is a PNG of a sane size and rewrites it in place
     * (decode + encode), keeping transparency.
     *
     * @throws \RuntimeException
     */
    protected function normalizePng(string $path): void
    {
        $fh = @fopen($path, 'rb');
        $magic = $fh ? fread($fh, 8) : '';
        if ($fh) {
            fclose($fh);
        }
        if ($magic !== "\x89PNG\r\n\x1a\n") {
            throw new \RuntimeException('The image must be a PNG file.');
        }

        $info = @getimagesize($path);
        if ($info === false || ($info[2] ?? null) !== IMAGETYPE_PNG) {
            throw new \RuntimeException('The image must be a PNG file.');
        }
        [$w, $h] = $info;
        // Checked from the header, before decoding, so a tiny file that claims a gigantic canvas is refused cheaply.
        if (max($w, $h) > self::MAX_SIDE) {
            throw new \RuntimeException('The image is too large. Maximum is ' . self::MAX_SIDE . ' pixels on the longer side.');
        }
        if (max($w, $h) < self::MIN_SIDE) {
            throw new \RuntimeException('The image is too small to print clearly.');
        }

        $bytes = @file_get_contents($path);
        $im = $bytes === false ? false : @imagecreatefromstring($bytes);
        if ($im === false) {
            throw new \RuntimeException('The image could not be read. It may be corrupted.');
        }

        if (!imageistruecolor($im)) {
            imagepalettetotruecolor($im);
        }
        imagealphablending($im, false);
        imagesavealpha($im, true);

        $ok = @imagepng($im, $path, 6);
        imagedestroy($im);
        if (!$ok) {
            throw new \RuntimeException('Could not process the image.');
        }
    }
}
