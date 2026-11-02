<?php

declare(strict_types=1);

namespace App\UseCases\GoogleCalendar;

use App\Contracts\GoogleCalendarGateway;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

final class ConnectAction
{
    public function __construct(private readonly GoogleCalendarGateway $gateway) {}

    public function __invoke(User $coach, Session $session): string
    {
        abort_unless($coach->role === UserRole::Coach, 403);
        $state = Str::random(64);
        $verifier = Str::random(96);
        $url = $this->gateway->authorizationUrl($state, $verifier);
        $session->put('google_calendar_oauth', ['state' => $state, 'verifier' => $verifier, 'user_id' => $coach->id, 'expires' => now()->addMinutes(10)->timestamp]);

        return $url;
    }
}
