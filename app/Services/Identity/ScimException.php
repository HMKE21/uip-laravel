<?php

namespace App\Services\Identity;

/** خطأ SCIM (RFC 7644 §3.12): الكونترولر بيحوّله لـ application/scim+json. */
class ScimException extends \RuntimeException
{
    public function __construct(public int $status, string $detail, public ?string $scimType = null)
    {
        parent::__construct($detail, $status);
    }
}
