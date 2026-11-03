<?php

namespace App\Services;

use App\Support\UploadMirror;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * الصورة الشخصية المحمية.
 *  - الأصل (الحجم الكامل) بيتخزّن خاص تحت storage/app/private (فئة avatar_originals) — مفيش رابط مباشر ليه.
 *  - اللي في users.avatar_path (والظاهر في كل حتة) نسخة مصغّرة public (حد أقصى THUMB px) — مش بتنفع للتنزيل بجودة.
 *  - الصورة الكبيرة بتتسلّم عبر ProfilePhotoApiController بعد فحص الصلاحية + علامة مائية باسم/رقم المشاهد داخل البكسلات.
 */
class ProfilePhotoService
{
    public const THUMB = 256;
    private const MAX_KB = 5120;
    private const EXT = ['jpg', 'jpeg', 'png', 'webp'];

    public function __construct(private FileUploadService $uploads)
    {
    }

    /** يخزّن الأصل خاص ويعمل المصغّرة. @return string مسار المصغّرة النسبي (يتحط في users.avatar_path) */
    public function storeFor(int $userId, $file): string
    {
        $stored = $this->uploads->store($file, 'avatar_originals', (string) $userId, self::MAX_KB, self::EXT);
        $thumb = $this->makeThumb(FileUploadService::absolutePath($stored['stored_path']), $userId);
        $old = DB::table('user_profile_photos')->where('user_id', $userId)->value('original_path');
        DB::table('user_profile_photos')->updateOrInsert(['user_id' => $userId], ['original_path' => $stored['stored_path'], 'updated_at' => now()]);
        if ($old && $old !== $stored['stored_path']) {
            $this->uploads->delete($old);
        }
        return $thumb;
    }

    /** يحمي صورة قديمة (full-size public): ينقلها خاص ويستبدل العام بمصغّرة. @return ?string المسار الجديد لـ avatar_path */
    public function protectExisting(int $userId, string $publicRel): ?string
    {
        $src = public_path(ltrim($publicRel, '/'));
        if (!is_file($src)) {
            UploadMirror::restore(ltrim($publicRel, '/'), $src);
        }
        if (!is_file($src)) {
            return null;
        }
        $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION)) ?: 'jpg';
        $rel = 'uploads/avatar-originals/' . $userId . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        $dst = FileUploadService::privateRoot() . '/' . $rel;
        if (!is_dir(dirname($dst)) && !@mkdir(dirname($dst), 0775, true) && !is_dir(dirname($dst))) {
            return null;
        }
        if (!@copy($src, $dst)) {
            return null;
        }
        UploadMirror::push($rel, $dst);
        $thumb = $this->makeThumb($dst, $userId);
        DB::table('user_profile_photos')->updateOrInsert(['user_id' => $userId], ['original_path' => $rel, 'updated_at' => now()]);
        $this->uploads->delete($publicRel); // الأصل العام يتشال
        return $thumb;
    }

    public function deleteFor(int $userId): void
    {
        $old = DB::table('user_profile_photos')->where('user_id', $userId)->value('original_path');
        DB::table('user_profile_photos')->where('user_id', $userId)->delete();
        if ($old) {
            $this->uploads->delete($old);
        }
    }

    /** مسار الأصل المطلق (أو null). لو مفيش أصل محمي بيرجع لملف avatar_path القديم. */
    public function originalFile(int $userId): ?string
    {
        $rel = DB::table('user_profile_photos')->where('user_id', $userId)->value('original_path');
        if (!$rel) {
            $rel = DB::table('users')->where('id', $userId)->value('avatar_path');
        }
        if (!$rel) {
            return null;
        }
        $abs = FileUploadService::absolutePath((string) $rel);
        return is_file($abs) ? $abs : null;
    }

    /** يرجّع [bytes, mime] لعرض الصورة للمشاهد مع علامة مائية مدمجة (بدون علامة لو صاحبها). */
    public function renderWatermarked(string $file, string $label): array
    {
        $img = $this->load($file);
        if (!$img) {
            return [file_get_contents($file), mime_content_type($file) ?: 'image/jpeg'];
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $max = 1600; // العرض مايزيدش عن كده — لقطة الشاشة أصلًا مش هتحتاج أكتر
        if (max($w, $h) > $max) {
            $r = $max / max($w, $h);
            $n = imagescale($img, (int) round($w * $r), (int) round($h * $r));
            if ($n) {
                imagedestroy($img);
                $img = $n;
                $w = imagesx($img);
                $h = imagesy($img);
            }
        }
        $this->stamp($img, $label, $w, $h);
        ob_start();
        imagejpeg($img, null, 86);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);
        return [$bytes, 'image/jpeg'];
    }

    // ------------------------------------------------------------------ internals

    private function load(string $file)
    {
        $data = @file_get_contents($file);
        if ($data === false || !function_exists('imagecreatefromstring')) {
            return null;
        }
        $img = @imagecreatefromstring($data);
        if (!$img) {
            return null;
        }
        imagepalettetotruecolor($img);
        return $img;
    }

    private function makeThumb(string $abs, int $userId): string
    {
        $rel = 'uploads/avatars/' . $userId . '/' . bin2hex(random_bytes(12)) . '.jpg';
        $dir = FileUploadService::diskDir(dirname($rel));
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not prepare the upload destination.');
        }
        $img = $this->load($abs);
        if (!$img) {
            Log::warning('ProfilePhoto: GD unavailable, thumbnail not generated', ['user' => $userId]);
            throw new \RuntimeException('Could not process the image.');
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $side = min($w, $h); // مربع من النص
        $sq = imagecrop($img, ['x' => (int) (($w - $side) / 2), 'y' => (int) (($h - $side) / 2), 'width' => $side, 'height' => $side]);
        imagedestroy($img);
        if (!$sq) {
            throw new \RuntimeException('Could not process the image.');
        }
        $t = imagescale($sq, self::THUMB, self::THUMB);
        imagedestroy($sq);
        imagejpeg($t, $dir . '/' . basename($rel), 82);
        imagedestroy($t);
        UploadMirror::push($rel, $dir . '/' . basename($rel));
        return $rel;
    }

    /** علامة مائية متكررة مائلة: "UIP #<id>" — بتظهر في أي لقطة شاشة وبتدل على مين صوّرها. */
    private function stamp($img, string $label, int $w, int $h): void
    {
        imagealphablending($img, true);
        $font = null;
        foreach ([config('upload.watermark_font'), '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf', base_path('resources/fonts/watermark.ttf')] as $f) {
            if ($f && is_file($f)) {
                $font = $f;
                break;
            }
        }
        $size = max(14, (int) round(min($w, $h) / 22));
        $white = imagecolorallocatealpha($img, 255, 255, 255, 88);
        $dark = imagecolorallocatealpha($img, 0, 0, 0, 100);
        $stepX = (int) max(180, $w / 2.2);
        $stepY = (int) max(120, $h / 4);
        for ($y = (int) ($stepY / 2); $y < $h + $stepY; $y += $stepY) {
            for ($x = -$stepX / 2; $x < $w; $x += $stepX) {
                $xx = (int) ($x + (($y / $stepY) % 2 ? $stepX / 2 : 0));
                if ($font) {
                    imagettftext($img, $size, 28, $xx + 1, $y + 1, $dark, $font, $label);
                    imagettftext($img, $size, 28, $xx, $y, $white, $font, $label);
                } else {
                    imagestring($img, 5, $xx, $y, $label, $white);
                }
            }
        }
    }
}
