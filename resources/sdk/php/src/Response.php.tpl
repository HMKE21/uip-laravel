<?php

declare(strict_types=1);

namespace Uipe;

/** A successful answer: `$data` is the unwrapped `data` field of the envelope. */
final class Response
{
    /** @var mixed */
    public $data;
    /** @var mixed */
    public $meta;
    public ?string $message;
    public int $status;
    /** @var array<string,string> lowercase header names */
    public array $headers;

    /** @param mixed $data @param mixed $meta */
    public function __construct(int $status, array $headers, $data, $meta, ?string $message)
    {
        $this->status = $status;
        $this->headers = $headers;
        $this->data = $data;
        $this->meta = $meta;
        $this->message = $message;
    }

    /** @return array{limit:?int,remaining:?int} */
    public function rateLimit(): array
    {
        $num = fn (string $k): ?int => isset($this->headers[$k]) && is_numeric($this->headers[$k]) ? (int) $this->headers[$k] : null;
        return ['limit' => $num('x-ratelimit-limit'), 'remaining' => $num('x-ratelimit-remaining')];
    }

    /** 'live' or 'sandbox': the mode of the credential that made the request. */
    public function mode(): ?string
    {
        return $this->headers['x-uipe-mode'] ?? null;
    }
}
