<?php

namespace App\Support;

use RuntimeException;

/** خطأ متوقع في "جهازي" — الـ code هو HTTP status اللي الـ controller بيرجّعه (404/409/413/422). */
class DriveException extends RuntimeException
{
    public function __construct(string $message, int $status = 422)
    {
        parent::__construct($message, $status);
    }
}
