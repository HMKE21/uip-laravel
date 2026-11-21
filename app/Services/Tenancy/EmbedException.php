<?php

namespace App\Services\Tenancy;

/** خطأ في Embed SDK: كود HTTP + كود ثابت مقروء للآلة (errors.code). */
final class EmbedException extends \DomainException
{
    public function __construct(string $message, int $status, public readonly string $errorCode)
    {
        parent::__construct($message, $status);
    }
}
