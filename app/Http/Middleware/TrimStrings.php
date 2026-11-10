<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TrimStrings as Middleware;

class TrimStrings extends Middleware
{
    /**
     * The names of the attributes that should not be trimmed.
     *
     * @var array<int, string>
     */
    protected $except = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    protected function transform($key, $value)
    {
        // Do not normalize invalid email line breaks into a valid address before validation.
        if (($key === 'email' || str_ends_with($key, '.email')) && is_string($value) && strpbrk($value, "\r\n") !== false) {
            return $value;
        }

        return parent::transform($key, $value);
    }
}
