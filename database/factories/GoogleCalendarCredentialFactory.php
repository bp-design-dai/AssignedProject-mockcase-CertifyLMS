<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Test fixtures only; never real Google credentials. */
class GoogleCalendarCredentialFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory()->coach(), 'calendar_id' => 'primary', 'access_token' => 'test-only-access',
            'refresh_token' => 'test-only-refresh', 'expires_at' => now()->addHour(), 'connected_at' => now(), 'requires_reconnect' => false];
    }
}
