<?php

namespace App\Support;

use RuntimeException;

/**
 * خطأ متوقع في "جهازي" — الـ code هو HTTP status اللي الـ controller بيرجّعه (404/409/413/422).
 * $errors اختياري: بيتبعت للفرونت في حقل errors (مثلًا {code:'share_gate', needs_password:true} أو أخطاء حقول البوابة).
 */
class DriveException extends RuntimeException
{
    public function __construct(string $message, int $status = 422, private ?array $errors = null)
    {
        parent::__construct($message, $status);
    }

    public function errors(): ?array
    {
        return $this->errors;
    }
}
