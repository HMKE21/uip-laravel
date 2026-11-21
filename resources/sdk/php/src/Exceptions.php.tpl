<?php

declare(strict_types=1);

namespace Uipe;

/** Base class of every error raised by this SDK. */
class UipeException extends \RuntimeException
{
}

/** The API answered with a non-2xx status. `$errorCode` is the machine readable `errors.code`. */
class ApiException extends UipeException
{
    public int $status;
    public ?string $errorCode;
    /** @var mixed */
    public $errors;
    /** @var array<string,string> lowercase header names */
    public array $headers;
    public ?string $body;

    /** @param mixed $errors */
    public function __construct(int $status, string $message, ?string $errorCode = null, $errors = null, array $headers = [], ?string $body = null)
    {
        parent::__construct($message, $status);
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->errors = $errors;
        $this->headers = $headers;
        $this->body = $body;
    }

    /** Seconds to wait before retrying (Retry-After), or null. */
    public function retryAfter(): ?int
    {
        $v = $this->headers['retry-after'] ?? null;
        return $v !== null && is_numeric($v) ? (int) $v : null;
    }
}

/** The request never got an answer (DNS, connection, timeout). */
class NetworkException extends UipeException
{
}

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
