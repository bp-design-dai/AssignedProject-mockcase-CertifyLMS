<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\GoogleCalendarGateway;
use App\Exceptions\GoogleCalendarException;
use App\Models\GoogleCalendarCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class GoogleCalendarConnectionService
{
    public function __construct(private readonly GoogleCalendarGateway $gateway, private readonly GoogleCalendarFailureNotifier $notifier) {}

    public function request(GoogleCalendarCredential $credential, callable $operation): mixed
    {
        try {
            $alreadyRefreshing = $credential->requires_reconnect || $credential->expires_at->lessThanOrEqualTo(now()->addSeconds(30));
            $token = $this->token($credential);
            try {
                return $operation($token);
            } catch (GoogleCalendarException $exception) {
                if (! $exception->authenticationFailure || $alreadyRefreshing) {
                    throw $exception;
                }

                // One authentication refresh/retry covers server-side early expiry; never a retry queue.
                return $operation($this->token($credential, forceRefresh: true));
            }
        } catch (GoogleCalendarException $exception) {
            if ($exception->authenticationFailure) {
                if (! $credential->requires_reconnect) {
                    $credential->update(['requires_reconnect' => true]);
                }
                $this->notifier->notify($credential, 'authentication');
            }
            throw $exception;
        }
    }

    private function token(GoogleCalendarCredential $credential, bool $forceRefresh = false): string
    {
        if ($credential->requires_reconnect) {
            throw new GoogleCalendarException(true);
        }
        if (! $forceRefresh && $credential->expires_at->greaterThan(now()->addSeconds(30))) {
            return $credential->access_token;
        }
        try {
            $tokens = $this->gateway->refresh($credential->refresh_token);
        } catch (GoogleCalendarException $exception) {
            $this->notifier->notify($credential, $exception->authenticationFailure ? 'authentication' : 'refresh');
            Log::warning('Google Calendar token refresh failed', ['authentication_failure' => $exception->authenticationFailure]);
            throw $exception;
        }
        $attributes = ['access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? $credential->refresh_token,
            'expires_at' => now()->addSeconds($tokens['expires_in'])];
        // Persist outside the booking/sync transaction; token-storage errors must not leak SQL bindings.
        DB::afterCommit(function () use ($credential, $attributes) {
            try {
                $credential->fresh()?->update($attributes);
            } catch (\Throwable $exception) {
                Log::warning('Google Calendar token storage failed', ['exception_class' => $exception::class]);
                $this->notifier->notify($credential, 'refresh');
            }
        });

        return $tokens['access_token'];
    }
}
