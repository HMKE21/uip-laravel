<?php

namespace App\Services\Forms;

class FormException extends \RuntimeException
{
    public function __construct(public string $errorCode, string $message, public int $httpStatus = 422, public array $extra = [])
    {
        parent::__construct($message);
    }
}
