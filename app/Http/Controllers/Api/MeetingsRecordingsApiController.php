<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\MeetingRepository;
use App\Services\AuditLogService;
use App\Services\MeetingRecordingService;
use App\Services\MeetingSignalingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * سطح /api/v1/meetings/{uuid}/recordings/* — Round 9 (Recording): بند 18.
 * نفس نمط MeetingsFilesApiController بالظبط (uip.auth.optional،
 * resolveActorOr403) — القراءة (index/show) مسموحة لأي actor شايف
 * الاجتماع (بند "Do not expose recordings to unauthorized users")،
 * بدء/إيقاف/حذف تسجيل مقصور على الهوست/co-host (راجع
 * MeetingRecordingService::requireManager).
 */
class MeetingsRecordingsApiController extends Controller
{
    public function __construct(
        private MeetingRepository $meetings,
        private MeetingSignalingService $signaling,
        private MeetingRecordingService $recordings,
        private AuditLogService $auditLog
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

    private function present($recording): array
    {
        return [
            'id'                       => $recording->id,
            'kind'                     => $recording->kind,
            'status'                   => $recording->status,
            'started_by_key'           => $recording->started_by_key,
            'started_by_display_name' => $recording->started_by_display_name,
            'started_at'               => $recording->started_at,
            'stopped_at'               => $recording->stopped_at,
            'duration_seconds'         => $recording->duration_seconds,
            'original_name'            => $recording->original_name,
            'mime_type'                => $recording->mime_type,
            'size_bytes'               => $recording->size_bytes,
            'download_url'             => $recording->stored_path ? asset($recording->stored_path) : null,
            'failure_reason'           => $recording->status === 'failed' ? $recording->failure_reason : null,
            'created_at'               => $recording->created_at,
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

        $recordings = array_map(fn ($r) => $this->present($r), $this->recordings->listFor($meeting));

        return $this->apiSuccess(['recordings' => $recordings], 'Recordings retrieved successfully.');
    }

    public function store(Request $request, string $uuid)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'kind'        => 'nullable|string|in:' . implode(',', MeetingRecordingService::ALLOWED_KINDS),
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
            $recording = $this->recordings->start($meeting, $actor, $request->input('kind', 'video'));
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 403);
        }

        // بند 38 (Audit & Security Logs — "Recording started").
        $this->auditLog->record($this->authUserId($request) ?? null, 'meetings.recording_started', 'Meeting', $meeting->id, null, ['recording_id' => $recording->id, 'kind' => $recording->kind]);

        return $this->apiSuccess($this->present($recording), 'Recording started.', 201);
    }

    public function stop(Request $request, string $uuid, string $recordingId)
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

        $recording = $this->meetings->findMeetingRecording($meeting->id, (int) $recordingId);
        if (!$recording) {
            return $this->apiError('Recording not found.', null, 404);
        }

        try {
            $recording = $this->recordings->stop($meeting, $actor, $recording, $request->file('file'));
        } catch (\RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'Only the host') ? 403 : 422;

            return $this->apiError($e->getMessage(), null, $status);
        }

        // بند 38 (Audit & Security Logs — "Recording stopped").
        $this->auditLog->record($this->authUserId($request) ?? null, 'meetings.recording_stopped', 'Meeting', $meeting->id, null, ['recording_id' => $recording->id]);

        return $this->apiSuccess($this->present($recording), 'Recording stopped, processing.');
    }

    public function destroy(Request $request, string $uuid, string $recordingId)
    {
        $meeting = $this->findMeeting($uuid);
        if (!$meeting) {
            return $this->apiError('Meeting not found.', null, 404);
        }

        $actor = $this->resolveActorOr403($request, $meeting);
        if ($actor instanceof \Illuminate\Http\JsonResponse) {
            return $actor;
        }

        $recording = $this->meetings->findMeetingRecording($meeting->id, (int) $recordingId);
        if (!$recording) {
            return $this->apiError('Recording not found.', null, 404);
        }

        try {
            $this->recordings->delete($meeting, $actor, $recording);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 403);
        }

        return $this->apiSuccess(null, 'Recording removed successfully.');
    }
}
