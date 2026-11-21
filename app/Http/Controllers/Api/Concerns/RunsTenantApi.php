<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Services\Tenancy\EmbedException;
use Illuminate\Http\Request;

/** مساعد لنقاط الـ Developer API الخاصة بالـ tenant: مفتاح tenant بس، وأخطاء بكود ثابت (errors.code). */
trait RunsTenantApi
{
    /** tenant_id لمفتاح تابع لـ tenant، أو null (مفتاح شخصي / توكن OAuth). */
    protected function tenantIdOf(Request $r): ?int
    {
        $c = (array) $r->attributes->get('dev_ctx');
        return ($c['kind'] ?? '') === 'key' && !empty($c['tenant_id']) ? (int) $c['tenant_id'] : null;
    }

    protected function tenantRequired()
    {
        return $this->apiError('This endpoint needs an API key that belongs to a workspace.', ['code' => 'tenant_required'], 403);
    }

    protected function runApi(callable $fn, int $ok = 200)
    {
        try {
            return $this->apiSuccess($fn(), 'OK', $ok);
        } catch (EmbedException $e) {
            return $this->apiError($e->getMessage(), ['code' => $e->errorCode], $e->getCode() >= 400 && $e->getCode() < 600 ? (int) $e->getCode() : 422);
        } catch (\DomainException $e) {
            $st = $e->getCode() >= 400 && $e->getCode() < 600 ? (int) $e->getCode() : 422;
            return $this->apiError($e->getMessage(), ['code' => match ($st) { 404 => 'not_found', 403 => 'forbidden', 409 => 'conflict', default => 'invalid_request' }], $st);
        }
    }
}
