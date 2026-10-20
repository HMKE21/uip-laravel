<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\ExamAttempt;
use App\Services\ExamSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * مشترك بين كونترولرز الطالب اللي بتلمس محاولة شغالة: بيقرا هوية الجلسة من X-Exam-Session / X-Device-Id
 * وبيرجّع 409 موحّد لو الجلسة مش بتاعة الجهاز ده.
 */
trait GuardsExamSession
{
    /** @return array{ip:?string,user_agent:?string,device_id:?string} */
    protected function sessionContext(Request $request): array
    {
        return [
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
            'device_id'  => $request->header('X-Device-Id'),
        ];
    }

    /** null لو مسموح، وإلا 409 (session_required | session_replaced). المحاولات الخالصة مابتتفحصش. */
    protected function sessionGuard(Request $request, ExamAttempt $attempt, ExamSessionService $sessions): ?JsonResponse
    {
        if (!$attempt->isActive()) {
            return null;
        }

        $verdict = $sessions->verify($attempt, $request->header('X-Exam-Session'));
        if ($verdict === 'ok') {
            return null;
        }

        return $this->sessionErrorResponse($verdict === 'replaced' ? 'session_replaced' : 'session_required');
    }

    protected function sessionErrorResponse(string $code, ?string $message = null, array $extra = []): JsonResponse
    {
        $messages = [
            'session_replaced' => 'This exam attempt is now open on another device or tab.',
            'session_required' => 'This exam attempt must be opened from this device first.',
            'session_conflict' => 'This exam attempt is already open on another device.',
        ];

        return response()->json([
            'success' => false,
            'message' => $message ?? ($messages[$code] ?? 'Session error.'),
            'data'    => array_merge(['code' => $code], $extra),
            'errors'  => null,
            'meta'    => (object) [],
        ], 409);
    }
}
