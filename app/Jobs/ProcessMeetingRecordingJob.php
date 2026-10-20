<?php

namespace App\Jobs;

use App\Models\MeetingRecording;
use App\Services\MeetingRecordingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Round 9 (Recording) — بند 18 ("Recording processing" + "Use
 * asynchronous processing where appropriate"). نفس فكرة
 * GradeEssayAnswerWithAiJob بالظبط: غلاف رفيع بياخد id بس (مش موديل
 * كامل، تجنب stale-model serialization)، كل المنطق الفعلي جوه
 * MeetingRecordingService::finalize().
 *
 * handle() عمدًا مبيرميش استثناء لفوق لو الصف اتمسح قبل ما الـ job
 * يتنفذ (مثلًا الهوست مسح التسجيل بعد stop() مباشرة) — ده سيناريو
 * سباق نادر لكن مشروع، مفيش داعي الـ job يفشل ويعمل retry عليه.
 */
class ProcessMeetingRecordingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(private int $recordingId)
    {
    }

    public function handle(MeetingRecordingService $recordings): void
    {
        $recording = MeetingRecording::find($this->recordingId);
        if (!$recording) {
            return;
        }

        $recordings->finalize($recording);
    }
}
