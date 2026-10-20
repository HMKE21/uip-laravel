<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/Concerns/PollableSnapshot.php القديمة —
 * نفس منطق الـ hash diffing بالظبط (spec section 12 "Real-Time APIs" >
 * Live Dashboard Updates)، بس Core\Response -> Illuminate\Http\JsonResponse
 * و$this->input() -> $request مُمرر صراحة (نفس فرق Paginates trait).
 */
trait PollableSnapshot
{
    /**
     * @param callable():JsonResponse $snapshot بيرجع نفس الـ full-payload
     *   JSON action الموجود أصلًا في الكنترولر (مثلًا $this->dashboardStats()).
     */
    protected function pollSnapshot(Request $request, callable $snapshot): JsonResponse
    {
        $response = $snapshot();

        $decoded = $response->getData(true);
        if ($response->getStatusCode() !== 200 || !is_array($decoded) || empty($decoded['success'])) {
            return $response;
        }

        $hash = hash('xxh128', json_encode($decoded['data'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $clientHash = (string) $request->input('since_hash', '');

        if ($clientHash !== '' && hash_equals($hash, $clientHash)) {
            return $this->apiSuccess(null, 'No changes since last poll.', 200, [
                'changed' => false,
                'hash'    => $hash,
            ]);
        }

        return $this->apiSuccess($decoded['data'] ?? null, $decoded['message'] ?? 'Operation completed successfully.', 200, [
            'changed' => true,
            'hash'    => $hash,
        ]);
    }
}
