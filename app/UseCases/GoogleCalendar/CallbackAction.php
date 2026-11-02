<?php

declare(strict_types=1);

namespace App\UseCases\GoogleCalendar;

use App\Contracts\GoogleCalendarGateway;
use App\Enums\UserRole;
use App\Exceptions\GoogleCalendarException;
use App\Integrations\GoogleCalendar\GoogleApiCalendarGateway;
use App\Models\GoogleCalendarCredential;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class CallbackAction
{
    public function __construct(private readonly GoogleCalendarGateway $gateway) {}

    public function __invoke(User $coach, array $input, Session $session): void
    {
        abort_unless($coach->role === UserRole::Coach, 403);
        $pending = $session->pull('google_calendar_oauth');
        abort_unless(is_array($pending) && ($pending['user_id'] ?? null) === $coach->id
            && ($pending['expires'] ?? 0) > now()->timestamp && is_string($input['state'] ?? null)
            && is_string($pending['state'] ?? null) && is_string($pending['verifier'] ?? null)
            && hash_equals($pending['state'], $input['state']), 403);
        if (isset($input['error']) || empty($input['code'])) {
            throw new GoogleCalendarException;
        }
        $tokens = $this->gateway->exchange($input['code'], $pending['verifier']);
        if (empty($tokens['refresh_token']) || (isset($tokens['scope'])
            && array_diff(GoogleApiCalendarGateway::SCOPES, explode(' ', $tokens['scope'])) !== [])) {
            throw new GoogleCalendarException;
        }
        try {
            DB::transaction(function () use ($coach, $tokens) {
                User::whereKey($coach->id)->lockForUpdate()->firstOrFail();
                GoogleCalendarCredential::where('user_id', $coach->id)->delete();
                GoogleCalendarCredential::create(['user_id' => $coach->id, 'calendar_id' => 'primary',
                    'access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'],
                    'expires_at' => now()->addSeconds($tokens['expires_in']), 'connected_at' => now()]);
            });
        } catch (QueryException) {
            Log::warning('Google Calendar credential save failed');
            throw new GoogleCalendarException;
        }
    }
}
