<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AiCodeAssistService;
use Illuminate\Http\Request;

/**
 * مساعد الكود داخل محرر MyPC — /api/v1/ai-code/*
 *
 * محمي في routes/api.php بـ uip.auth + uip.exam_lock + uip.can:ai.code_assist:
 *  - uip.exam_lock: الطالب في امتحان شغّال ما يقدرش يستخدمه خالص (على السيرفر).
 *  - ai.code_assist: صلاحية مستقلة يتحكم فيها الأدمن من /admin/roles.
 *
 * الـ streaming بنفس صيغة المساعد العام: `data: {json}\n\n` (fetch + ReadableStream في الفرونت).
 * أخطاء ما قبل الستريم (صلاحية/حجم/rate limit) بترجع JSON عادي بـ apiError وكود HTTP مناسب.
 */
class AiCodeAssistController extends Controller
{
    public function __construct(private AiCodeAssistService $assist)
    {
    }

    /** GET /api/v1/ai-code/status — الفرونت يعرض الزرار على أساسه. */
    public function status()
    {
        return $this->apiSuccess($this->assist->status());
    }

    /**
     * POST /api/v1/ai-code/fix — body: code, error, language?, filename?, line?, mode? ('tutor'|'assistant'), locale? ('ar'|'en')
     *
     * أحداث الستريم: start → token* → [edits] → done، أو error في أي لحظة.
     */
    public function fix(Request $request)
    {
        $userId = (int) $request->attributes->get('uip_user_id');

        try {
            $input = $this->assist->normalizeInput($request->only(['code', 'error', 'language', 'filename', 'line', 'mode', 'locale']));
            $this->assist->assertCanServe($userId);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, $this->httpStatus($e));
        }

        return $this->sse(fn (callable $send) => $this->assist->fix($userId, $input, $send));
    }

    /**
     * POST /api/v1/ai-code/assist — أوامر قائمة الكليك يمين.
     * body: action (explain|fix|improve|comment|convert|test), code, sel_from?, sel_to?, target? (للتحويل),
     *       language?, filename?, mode? ('tutor'|'assistant'), locale? ('ar'|'en')
     *
     * نفس أحداث الستريم: start → token* → [edits] → done، أو error في أي لحظة.
     */
    public function assist(Request $request)
    {
        $userId = (int) $request->attributes->get('uip_user_id');

        try {
            $input = $this->assist->normalizeAssistInput($request->only(['code', 'action', 'sel_from', 'sel_to', 'target', 'language', 'filename', 'mode', 'locale']));
            $this->assist->assertCanServe($userId);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, $this->httpStatus($e));
        }

        return $this->sse(fn (callable $send) => $this->assist->assist($userId, $input, $send));
    }

    /**
     * POST /api/v1/ai-code/chat — شات اللوحة الجانبية.
     * body: messages[{role,content}], active_path?, files[{path,content}], tree[]?, sel_from?, sel_to?, error?, mode?, locale?
     *
     * نفس أحداث الستريم: start → token* → [edits] → done، أو error في أي لحظة. التعديلات متعددة الملفات:
     * { path, kind: 'edit'|'create', search, replace, start_line, end_line }.
     */
    public function chat(Request $request)
    {
        $userId = (int) $request->attributes->get('uip_user_id');

        try {
            $input = $this->assist->normalizeChatInput($request->only(['messages', 'active_path', 'files', 'tree', 'sel_from', 'sel_to', 'error', 'mode', 'locale']));
            $this->assist->assertCanServe($userId);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, $this->httpStatus($e));
        }

        return $this->sse(fn (callable $send) => $this->assist->chat($userId, $input, $send));
    }

    /**
     * POST /api/v1/ai-code/complete — الإكمال التلقائي (ghost text). body: prefix, suffix?, language?, filename?
     * رد عادي (مش ستريم): { completion: "…" } — '' لو مفيش اقتراح. أي فشل من المزوّد = 503 بسيط (الفرونت بيتجاهله).
     */
    public function complete(Request $request)
    {
        $userId = (int) $request->attributes->get('uip_user_id');

        try {
            $input = $this->assist->normalizeCompleteInput($request->only(['prefix', 'suffix', 'language', 'filename']));
            $this->assist->assertCanComplete($userId);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, $this->httpStatus($e));
        }

        try {
            return $this->apiSuccess(['completion' => $this->assist->complete($userId, $input)]);
        } catch (\Throwable $e) {
            return $this->apiError('AI autocomplete is not available right now.', null, 503);
        }
    }

    /** SSE response: `data: {json}\n\n` per event; any exception becomes an `error` event. */
    private function sse(callable $work)
    {
        return response()->stream(function () use ($work) {
            // يقفل أي output buffering ممكن يمنع الـ flush لحظة بلحظة (زي AiAssistantController).
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            $send = function (array $payload): void {
                echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
                @flush();
            };

            try {
                $work($send);
            } catch (\Throwable $e) {
                $send(['type' => 'error', 'message' => $e->getMessage()]);
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream; charset=UTF-8',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }

    private function httpStatus(\RuntimeException $e): int
    {
        $code = (int) $e->getCode();
        return in_array($code, [413, 422, 429, 503], true) ? $code : 422;
    }
}
