<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\Request;

/** يحوّل \DomainException(msg, http) من الخدمات لرد JSON بنفس شكل المنصة. */
trait RunsDomain
{
    protected function dom(callable $fn, int $okStatus = 200)
    {
        try {
            return $this->apiSuccess($fn(), 'OK', $okStatus);
        } catch (\DomainException $e) {
            return $this->apiError($e->getMessage(), null, $e->getCode() >= 400 && $e->getCode() < 600 ? (int) $e->getCode() : 422);
        }
    }

    protected function uid(Request $r): int
    {
        return (int) $r->attributes->get('uip_user_id');
    }
}
