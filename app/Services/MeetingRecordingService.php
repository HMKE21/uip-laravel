<?php

namespace App\Services;

use App\Events\Meetings\MeetingRecordingReady;
use App\Events\Meetings\MeetingRecordingStarted;
use App\Events\Meetings\MeetingRecordingStopped;
use App\Jobs\ProcessMeetingRecordingJob;
use App\Models\Meeting;
use App\Models\MeetingRecording;
use App\Repositories\MeetingRepository;
use Illuminate\Http\UploadedFile;

/**
 * Meetings & Collaboration Platform — Round 9 (Recording): بند 18.
 * "Client-Side recording as a practical temporary solution (without
 * SFU)" — مفيش سيرفر بيسجّل الصوت/الفيديو بنفسه (ده يحتاج SFU/media
 * server خارج نطاق البند)؛ كل مشارك بيسجل في المتصفح بنفسه
 * (MediaRecorder API)، والـ API هنا مسؤول بس عن: دورة حياة التسجيل
 * (start/stop/status)، استقبال الملف النهائي وقت stop()، وضبط الوصول
 * (بند "Do not expose recordings to unauthorized users").
 *
 * الصلاحيات (بند "Recording permissions" في قائمة إعدادات الاجتماع،
 * وبند 5 Host Controls بشكل عام): بدء/إيقاف/حذف تسجيل = هوست/co-host
 * بس (canManage) — نفس مبدأ لوك الاجتماع، مش حاجة أي مشارك عادي يقدر
 * يعملها. القراءة (index/show/download) = أي actor شايف الاجتماع
 * (canView) — يعني هوست أو أي مشارك دخل فعليًا، مش الجمهور العام ولا
 * حتى مدعو لسه ما دخلش.
 *
 * recording_enabled (meeting.settings) بيتحكم في تفعيل/تعطيل الميزة
 * دي بالكامل للاجتماع ده (زي allow_file_sharing/allow_chat بالظبط).
 */
class MeetingRecordingService
{
    public function __construct(
        private MeetingRepository $meetings,
        private FileUploadService $uploads,
        private MeetingPolicyService $policy
    ) {
    }

    public const ALLOWED_KINDS = ['audio', 'video', 'screen_share'];

    /** إضافات الملف المسموحة للتسجيل النهائي (فئة رفع منفصلة عن 'meetings' العادية — فيديو/صوت مش مستندات). */
    private const ALLOWED_EXTENSIONS = ['webm', 'mp4', 'ogg', 'mp3', 'wav', 'm4a'];

    public function isRecordingEnabled(Meeting $meeting): bool
    {
        return (bool) ($meeting->settings['recording_enabled'] ?? true);
    }

    /**
     * بدء/إيقاف/حذف تسجيل = هوست/co-host بس (راجع docblock الكلاس).
     * نفس نمط MeetingPollService::requireManager() بالظبط.
     * @throws \RuntimeException actor مش هوست/co-host.
     */
    private function requireManager(Meeting $meeting, array $actor): void
    {
        $userId = $actor['type'] === 'participant' ? (int) str_replace('user:', '', $actor['key']) : 0;
        if (!$this->policy->canManage($meeting, $userId, $this->meetings)) {
            throw new \RuntimeException('Only the host or co-host can manage recordings.');
        }
    }

    /** @return MeetingRecording[] */
    public function listFor(Meeting $meeting): array
    {
        return $this->meetings->meetingRecordingsFor($meeting->id);
    }

    /**
     * بند 18 "Recording status": بيبدأ صف جديد status=recording — الملف
     * لسه في المتصفح، هيوصل بس وقت stop().
     * @throws \RuntimeException التسجيل معطّل لهذا الاجتماع، أو فيه تسجيل شغال بالفعل من نفس النوع.
     */
    public function start(Meeting $meeting, array $actor, string $kind = 'video'): MeetingRecording
    {
        $this->requireManager($meeting, $actor);

        if (!$this->isRecordingEnabled($meeting)) {
            throw new \RuntimeException('Recording is disabled for this meeting.');
        }

        if (!in_array($kind, self::ALLOWED_KINDS, true)) {
            throw new \InvalidArgumentException('kind must be one of: ' . implode(', ', self::ALLOWED_KINDS));
        }

        $alreadyRecording = $this->meetings->activeRecordingFor($meeting->id, $kind);
        if ($alreadyRecording) {
            throw new \RuntimeException('A ' . $kind . ' recording is already in progress for this meeting.');
        }

        $recording = $this->meetings->createMeetingRecording([
            'meeting_id'              => $meeting->id,
            'kind'                    => $kind,
            'started_by_key'          => $actor['key'],
            'started_by_display_name' => $actor['display_name'],
            'status'                  => 'recording',
            'started_at'              => now(),
        ]);

        // بند "Recording must be clearly indicated to all participants."
        event(new MeetingRecordingStarted($meeting->uuid, $recording->id, $kind, $actor['key'], $actor['display_name']));

        return $recording;
    }

    /**
     * بند 18 "Recording processing" + "Use asynchronous processing where
     * appropriate": الملف بيتاپلود هنا (sync، زي أي رفع عادي — ده جزء
     * من الـ HTTP request نفسه، مفيش بديل تاني للاستقبال)، لكن أي
     * finalization بعد كده (حساب/تأكيد الميتاداتا) بتحصل في Job منفصل
     * (ProcessMeetingRecordingJob) عشان الـ response يرجع للفرونت بسرعة
     * من غير ما ينتظر أي معالجة إضافية.
     * @throws \RuntimeException التسجيل مش شغال أصلًا، أو الملف مرفوض (نوع/حجم).
     */
    public function stop(Meeting $meeting, array $actor, MeetingRecording $recording, ?UploadedFile $file): MeetingRecording
    {
        $this->requireManager($meeting, $actor);

        if ((int) $recording->meeting_id !== (int) $meeting->id) {
            throw new \RuntimeException('Recording not found.');
        }
        if (!$recording->isActive()) {
            throw new \RuntimeException('This recording has already been stopped.');
        }

        $stoppedAt = now();
        $durationSeconds = max(0, $stoppedAt->diffInSeconds($recording->started_at));

        try {
            $stored = $this->uploads->store(
                $file,
                'meeting-recordings',
                $meeting->uuid,
                (int) config('meetings.recording_max_kb', 512000),
                self::ALLOWED_EXTENSIONS
            );
        } catch (\RuntimeException $e) {
            $recording->fill([
                'status'          => 'failed',
                'stopped_at'      => $stoppedAt,
                'duration_seconds' => $durationSeconds,
                'failure_reason'  => $e->getMessage(),
            ]);
            $recording->save();

            event(new MeetingRecordingStopped($meeting->uuid, $recording->id, 'failed'));

            throw $e;
        }

        $recording->fill([
            'status'            => 'processing',
            'stopped_at'        => $stoppedAt,
            'duration_seconds'  => $durationSeconds,
            'original_name'     => $stored['original_name'],
            'stored_path'       => $stored['stored_path'],
            'mime_type'         => $stored['mime_type'],
            'size_bytes'        => $stored['size_bytes'],
        ]);
        $recording->save();

        event(new MeetingRecordingStopped($meeting->uuid, $recording->id, 'processing'));

        ProcessMeetingRecordingJob::dispatch($recording->id);

        return $recording;
    }

    /**
     * بيتنادى من ProcessMeetingRecordingJob بس — "processing" الفعلي هنا
     * مجرد تأكيد إن الملف اتخزن صح (مفيش transcoding حقيقي، راجع docblock
     * الكلاس)؛ نقطة التمديد المستقبلية (لو SFU/ffmpeg اتضافوا لاحقًا)
     * هي هنا بالظبط.
     */
    public function finalize(MeetingRecording $recording): void
    {
        if ($recording->status !== 'processing') {
            return;
        }

        $status = !empty($recording->stored_path) ? 'completed' : 'failed';
        $recording->fill(['status' => $status]);
        if ($status === 'failed') {
            $recording->failure_reason = $recording->failure_reason ?? 'No recording file was stored.';
        }
        $recording->save();

        $meeting = $recording->meeting;
        if ($meeting) {
            event(new MeetingRecordingReady($meeting->uuid, $recording->id, $status));
        }
    }

    /** @throws \RuntimeException actor مش هوست/co-host. */
    public function delete(Meeting $meeting, array $actor, MeetingRecording $recording): void
    {
        $this->requireManager($meeting, $actor);

        if (!empty($recording->stored_path)) {
            $this->uploads->delete($recording->stored_path);
        }
        $recording->delete();
    }
}
