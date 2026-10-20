<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\MeetingRepository;
use App\Services\MeetingFileService;
use App\Services\MeetingPolicyService;
use App\Services\MeetingSignalingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * سطح /api/v1/meetings/{uuid}/files/* — Round 7 (Collaboration
 * Extras): بند 19 (File Sharing). نفس نمط MeetingsChatApiController
 * بالظبط (uip.auth.optional، resolveActorOr403) — الضيف المقبول
 * (بند 23) لازم يقدر يشارك/يحمّل ملفات برضو، مفيش تفرقة في المواصفة.
 *
 * الرفع الفعلي عبر FileUploadService الموجود (فئة 'meetings') —
 * راجع docblock MeetingFileService لتفاصيل التكامل.
 */
class MeetingsFilesApiController extends Controller
{
    public function __construct(
        private MeetingRepository $meetings,
        private MeetingSignalingService $signaling,
        private MeetingFileService $files,
        private MeetingPolicyService $policy
    ) {
    }

    private function authUserId(Request $request): ?int
    {
        $id = $request->attributes->get('uip_user_id');

        return $id !== null ? (int) $id : null;
    }

    private function findMeeting(string $uuid)
    {
        return $this->meetings->findByUuid($uuid);
    }

    private function resolveActorOr403(Request $request, $meeting)
    {
        try {
            return $this->signaling->resolveActor($meeting, $this->authUserId($request), $request->input('guest_token'));
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 403);
        }
    }

    private function present($file): array
    {
        return [
            'id'                    => $file->id,
            'original_name'         => $file->original_name,
            'extension'             => $file->extension,
            'mime_type'             => $file->mime_type,
            'size_bytes'            => $file->size_bytes,
            'uploader_key'          => $file->uploader_key,
            'uploader_display_name' => $file->uploader_display_name,
            'download_url'          => asset($file->stored_path),
            'created_at'            => $file->created_at,
        ];
    }

    public function index(Request $request, string $uuid)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $actor = $this->resolveActorOr403($request, $meeting);
        if ($actor instanceof \Illuminate\Http\JsonResponse) {
            return $actor;
        }

        $files = array_map(fn ($file) => $this->present($file), $this->files->listFor($meeting));

        return $this->apiSuccess(['files' => $files], 'Files retrieved successfully.');
    }

    public function store(Request $request, string $uuid)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'file'        => 'required|file',
            'guest_token' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $actor = $this->resolveActorOr403($request, $meeting);
        if ($actor instanceof \Illuminate\Http\JsonResponse) {
            return $actor;
        }

        try {
            $file = $this->files->upload($meeting, $actor, $request->file('file'));
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess($this->present($file), 'File shared successfully.', 201);
    }

    public function destroy(Request $request, string $uuid, string $fileId)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $actor = $this->resolveActorOr403($request, $meeting);
        if ($actor instanceof \Illuminate\Http\JsonResponse) {
            return $actor;
        }

        $file = $this->meetings->findMeetingFile($meeting->id, (int) $fileId);
        if (!$file) {
            return $this->apiError('File not found.', null, 404);
        }

        try {
            $this->files->delete($meeting, $actor, $file, $this->policy);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 403);
        }

        return $this->apiSuccess(null, 'File removed successfully.');
    }
}
