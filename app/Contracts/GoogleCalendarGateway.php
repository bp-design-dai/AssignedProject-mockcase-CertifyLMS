<?php

declare(strict_types=1);

namespace App\Contracts;

use Carbon\CarbonInterface;

interface GoogleCalendarGateway
{
    public function authorizationUrl(string $state, string $verifier): string;

    public function exchange(string $code, string $verifier): array;

    public function refresh(string $refreshToken): array;

    /** @return list<array{start: string, end: string}> */
    public function busy(string $accessToken, CarbonInterface $start, CarbonInterface $end): array;

    public function createEvent(string $accessToken, array $event): void;

    public function deleteEvent(string $accessToken, string $eventId): void;
}
