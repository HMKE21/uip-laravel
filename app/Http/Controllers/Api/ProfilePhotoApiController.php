<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ChatSettingsService;
use App\Services\MessagingService;
use App\Services\PostNotifier;
use App\Services\ProfilePhotoService;
use App\Services\ProfilePrivacyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/profile-photos/{userId}           عرض الصورة بالحجم الكامل (بعد فحص الصلاحية + علامة مائية)
 * GET /api/v1/profile-photos/{userId}/download  تنزيل الأصل — بس لو صاحب الصورة سامح بكده (أو هو صاحبها)
 * أي رفض بيرجع 404 بنفس الرسالة (مايتعرفش هل في صورة ولا لأ).
 */
class ProfilePhotoApiController extends Controller
{
    public function __construct(
        private ProfilePhotoService $photos,
        private ProfilePrivacyService $privacy,
        private MessagingService $messaging,
        private PostNotifier $notifier
    ) {
    }

    private function deny()
    {
        return $this->apiError('Photo not available.', null, 404);
    }

    private function allowed(int $viewer, int $owner): bool
    {
        if ($viewer === $owner) {
            return true;
        }
        $active = DB::table('users')->where('id', $owner)->whereNull('deleted_at')->where('status', 'active')->exists();
        return $active
            && !$this->notifier->blocked($viewer, $owner)
            && !$this->messaging->isAvatarHidden($owner, $viewer)
            && $this->privacy->canSeeProfile($viewer, $owner);
    }

    private function headers(string $mime, string $disposition): array
    {
        return [
            'Content-Type'           => $mime,
            'Content-Disposition'    => $disposition,
            'Cache-Control'          => 'private, no-store, max-age=0',
            'Pragma'                 => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag'           => 'noindex, noimageindex',
            'Cross-Origin-Resource-Policy' => 'same-site',
        ];
    }

    public function show(Request $request, $userId)
    {
        $viewer = (int) $request->attributes->get('uip_user_id');
        $owner = (int) $userId;
        if (!$this->allowed($viewer, $owner)) {
            return $this->deny();
        }
        $file = $this->photos->originalFile($owner);
        if (!$file) {
            return $this->deny();
        }
        if ($viewer === $owner) {
            return response(file_get_contents($file), 200, $this->headers(mime_content_type($file) ?: 'image/jpeg', 'inline; filename="photo"'));
        }
        [$bytes, $mime] = $this->photos->renderWatermarked($file, 'UIPE #' . $viewer);
        return response($bytes, 200, $this->headers($mime, 'inline; filename="photo.jpg"'));
    }

    public function download(Request $request, $userId)
    {
        $viewer = (int) $request->attributes->get('uip_user_id');
        $owner = (int) $userId;
        if (!$this->allowed($viewer, $owner) || !$this->privacy->canDownloadPhoto($viewer, $owner)) {
            return $this->deny();
        }
        $file = $this->photos->originalFile($owner);
        if (!$file) {
            return $this->deny();
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION)) ?: 'jpg';
        return response(file_get_contents($file), 200, $this->headers(mime_content_type($file) ?: 'image/jpeg', 'attachment; filename="profile-' . $owner . '.' . preg_replace('/[^a-z0-9]/', '', $ext) . '"'));
    }
}
