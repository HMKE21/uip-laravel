<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SubtitleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * /api/v1/subtitles/auto/* — ترجمة الفيديو التلقائية (مشغّل الفيديو بيبعت قطع صوت قصيرة).
 */
class SubtitlesApiController extends Controller
{
    public function __construct(private SubtitleService $subtitles)
    {
    }

    /** GET /api/v1/subtitles/auto/status — الفرونت بيخفي الخيار لو available=false. */
    public function status()
    {
        return $this->apiSuccess([
            'available'         => $this->subtitles->available(),
            'max_chunk_seconds' => $this->subtitles->maxChunkSeconds(),
            'languages'         => array_keys(SubtitleService::LANGUAGES),
        ], 'Subtitle status retrieved successfully.');
    }

    /** POST /api/v1/subtitles/auto — multipart: audio (wav/mp3/…)، target (ar|en|…|orig)، source (اختياري: ISO-639-1) */
    public function generate(Request $request)
    {
        if (!$this->subtitles->available()) {
            return $this->apiError('Automatic subtitles are not enabled on this server.', ['code' => 'ai_unavailable'], 503);
        }

        $maxKb = (int) config('ai.transcribe.max_upload_kb', 24000);
        $v = Validator::make($request->all(), [
            'audio'  => ['required', 'file', 'max:' . $maxKb, 'mimes:wav,wave,mp3,mpga,m4a,webm,ogg,oga,opus,flac'],
            'target' => ['nullable', 'string', 'in:orig,' . implode(',', array_keys(SubtitleService::LANGUAGES))],
            'source' => ['nullable', 'string', 'regex:/^[a-z]{2,3}$/'],
        ]);
        if ($v->fails()) {
            return $this->apiError('Invalid request.', $v->errors()->toArray(), 422);
        }

        // set_time_limit: تفريغ + ترجمة قطعة ممكن يطوّل على PHP-FPM الافتراضي.
        @set_time_limit(240);

        try {
            $data = $this->subtitles->generate(
                $request->file('audio'),
                (string) $request->input('target', 'orig'),
                $request->filled('source') ? (string) $request->input('source') : null
            );
            return $this->apiSuccess($data, 'Subtitles generated successfully.');
        } catch (\RuntimeException $e) {
            // التفاصيل في اللوج بس (ممكن تحتوي ردود المزوّد).
            Log::warning('Auto subtitles failed', ['error' => mb_substr($e->getMessage(), 0, 500)]);
            return $this->apiError('Could not generate subtitles right now.', ['code' => 'transcribe_failed'], 502);
        }
    }
}
