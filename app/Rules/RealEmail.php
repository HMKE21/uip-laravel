<?php

namespace App\Rules;

use App\Support\DisposableEmail;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Laravel rule: the address must belong to a real, mail-receiving domain (no temp-mail providers). */
class RealEmail implements ValidationRule
{
    public function __construct(private string $locale = 'en')
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($msg = DisposableEmail::problem($value, $this->locale)) {
            $fail($msg);
        }
    }
}
