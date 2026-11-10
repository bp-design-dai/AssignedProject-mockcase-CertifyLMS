<?php

declare(strict_types=1);

namespace App\Validation;

use Illuminate\Validation\Validator;
use Stringable;

final class EmailValidator extends Validator
{
    public function validateEmail($attribute, $value, $parameters): bool
    {
        // Keep the existing email rule, options and error message for every caller.
        if ((is_string($value) || $value instanceof Stringable) && strpbrk((string) $value, "\r\n") !== false) {
            return false;
        }

        return parent::validateEmail($attribute, $value, $parameters);
    }
}
