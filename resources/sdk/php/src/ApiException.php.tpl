<?php

declare(strict_types=1);

namespace Uipe;

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
