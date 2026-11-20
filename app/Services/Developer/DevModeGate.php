<?php

namespace App\Services\Developer;

/**
 * فصل sandbox عن live من مكان واحد (بيتطبق في DevAuthMiddleware قبل أي controller).
 * مصدر الحقيقة: حقل `modes` في DevOpenApi::catalog() لكل endpoint: both | live | sandbox.
 *   live    → مفتاح sandbox بيتحجب (422 sandbox_unsupported)
 *   sandbox → أي اعتماد مش sandbox بيتحجب (422 sandbox_only)
 *   both    → الـ controller مسؤول إنه يفلتر بيانات الـ mode (زي الـ webhooks) أو يوجّه لـ sandbox/live.
 * endpoint مش موجود في الكتالوج (unknown) بيتعامل كـ live-only (fail closed): مفتاح sandbox مبيوصلهوش.
 */
final class DevModeGate
{
    /** @return array{code:string,message:string}|null null = مسموح */
    public static function check(string $credentialMode, string $endpointMode): ?array
    {
        $sandbox = $credentialMode === 'sandbox';
        if ($sandbox && in_array($endpointMode, ['live', 'unknown'], true)) {
            return ['code' => 'sandbox_unsupported', 'message' => 'This endpoint is not available in sandbox mode.'];
        }
        if (!$sandbox && $endpointMode === 'sandbox') {
            return ['code' => 'sandbox_only', 'message' => 'Only available with a sandbox key.'];
        }
        return null;
    }
}
