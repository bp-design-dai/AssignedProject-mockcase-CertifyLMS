<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Provider responses/codes/tokens must never become a public exception message. */
final class GoogleCalendarException extends RuntimeException
{
    public function __construct(public readonly bool $authenticationFailure = false)
    {
        parent::__construct('Google Calendar is temporarily unavailable.');
    }
}
