<?php

namespace App\Support;

use RuntimeException;

/** خطأ متوقع من منطق الدليل (validation 422 / 403 / 404 / 409) — الكنترولر بيحوّله لـ apiError. */
class PortalGuideException extends RuntimeException
{
    /** @param array<string,string>|null $errors */
    public function __construct(string $message, public readonly int $status = 422, public readonly ?array $errors = null)
    {
        parent::__construct($message, $status);
    }
}
