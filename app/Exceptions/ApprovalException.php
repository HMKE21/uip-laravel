<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * رفض قرار اعتماد لسبب قاعدة عمل (مش خطأ تقني): مش دور المرحلة دي، أو دور استشاري (معيد).
 * الكنترولرز بتحوّلها لـ 403/409 برسالة واضحة بدل 422 عامة.
 */
class ApprovalException extends RuntimeException
{
    public const NOT_YOUR_TURN = 'not_your_turn';
    public const ADVISORY_ROLE = 'advisory_role';

    private function __construct(private string $reason, string $message, private int $status)
    {
        parent::__construct($message);
    }

    public static function notYourTurn(string $awaiting): self
    {
        return new self(self::NOT_YOUR_TURN, "It is not this stage's turn yet. The project is awaiting: {$awaiting}.", 409);
    }

    public static function advisoryRole(): self
    {
        return new self(self::ADVISORY_ROLE, 'Teaching assistants have an advisory link only and cannot approve, reject or request changes.', 403);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }
}
