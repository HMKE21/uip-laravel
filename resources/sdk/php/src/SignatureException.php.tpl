<?php

declare(strict_types=1);

namespace Uipe;

/**
 * A webhook delivery was rejected. `$reason` is one of: missing_header, malformed_header, missing_secret,
 * signature_mismatch, timestamp_too_old, timestamp_in_future, invalid_payload, replayed_event.
 */
class SignatureException extends UipeException
{
    public string $reason;

    public function __construct(string $reason, ?string $message = null)
    {
        parent::__construct($message ?? $reason);
        $this->reason = $reason;
    }
}
