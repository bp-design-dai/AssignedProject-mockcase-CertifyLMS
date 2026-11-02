<?php

declare(strict_types=1);

namespace App\Integrations\GoogleCalendar;

use App\Contracts\GoogleCalendarGateway;
use App\Exceptions\GoogleCalendarException;
use Carbon\CarbonInterface;
use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\FreeBusyRequest;
use Google\Service\Exception as ServiceException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

final class GoogleApiCalendarGateway implements GoogleCalendarGateway
{
    public const SCOPES = [Calendar::CALENDAR_FREEBUSY, Calendar::CALENDAR_EVENTS_OWNED];

    public function __construct(private readonly ClientInterface $http) {}

    private function client(): Client
    {
        foreach (['client_id', 'client_secret', 'redirect_uri'] as $key) {
            if (! is_string(config('services.google_calendar.'.$key)) || config('services.google_calendar.'.$key) === '') {
                throw new GoogleCalendarException;
            }
        }
        $client = new Client(['retry' => ['retries' => 0]]);
        $client->setClientId(config('services.google_calendar.client_id'));
        $client->setClientSecret(config('services.google_calendar.client_secret'));
        $client->setRedirectUri(config('services.google_calendar.redirect_uri'));
        $client->setScopes(self::SCOPES);
        $client->setHttpClient($this->http);

        return $client;
    }

    public function authorizationUrl(string $state, string $verifier): string
    {
        $client = $this->client();
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setState($state);

        return $client->createAuthUrl(null, [
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);
    }

    public function exchange(string $code, string $verifier): array
    {
        return $this->tokens(fn () => $this->client()->fetchAccessTokenWithAuthCode($code, $verifier));
    }

    public function refresh(string $refreshToken): array
    {
        return $this->tokens(fn () => $this->client()->fetchAccessTokenWithRefreshToken($refreshToken));
    }

    private function tokens(callable $request): array
    {
        $tokens = $this->guard($request);
        if (isset($tokens['error'])) {
            throw new GoogleCalendarException(in_array($tokens['error'], ['invalid_grant', 'invalid_token'], true));
        }
        if (! is_string($tokens['access_token'] ?? null) || $tokens['access_token'] === '' || ($tokens['expires_in'] ?? 0) <= 0) {
            throw new GoogleCalendarException;
        }

        return $tokens;
    }

    private function calendar(string $token): Calendar
    {
        $client = $this->client();
        // Refresh is explicit in ConnectionService, not hidden inside the SDK.
        $client->setAccessToken(['access_token' => $token, 'expires_in' => 3600, 'created' => time()]);

        return new Calendar($client);
    }

    public function busy(string $accessToken, CarbonInterface $start, CarbonInterface $end): array
    {
        return $this->guard(function () use ($accessToken, $start, $end) {
            $response = $this->calendar($accessToken)->freebusy->query(new FreeBusyRequest([
                'timeMin' => $start->toRfc3339String(), 'timeMax' => $end->toRfc3339String(),
                'timeZone' => config('app.timezone'), 'items' => [['id' => 'primary']],
            ]));
            $calendar = $response->getCalendars()['primary'] ?? null;
            if ($calendar === null || $calendar->getErrors()) {
                throw new GoogleCalendarException;
            }

            return array_map(fn ($period) => ['start' => $period->getStart(), 'end' => $period->getEnd()], $calendar->getBusy() ?? []);
        });
    }

    public function createEvent(string $accessToken, array $event): void
    {
        $this->guard(function () use ($accessToken, $event) {
            $calendar = $this->calendar($accessToken);
            try {
                $calendar->events->insert('primary', new Event($event), ['sendUpdates' => 'none']);
            } catch (ServiceException $exception) {
                if ($exception->getCode() !== 409) {
                    throw $exception;
                }
                // A lost response/replayed event cannot create a second appointment.
                $existing = $calendar->events->get('primary', $event['id']);
                if (($existing->getExtendedProperties()?->getPrivate()['lms_meeting_id'] ?? null) !== $event['extendedProperties']['private']['lms_meeting_id']) {
                    throw new GoogleCalendarException;
                }
            }
        });
    }

    public function deleteEvent(string $accessToken, string $eventId): void
    {
        $this->guard(function () use ($accessToken, $eventId) {
            try {
                $this->calendar($accessToken)->events->delete('primary', $eventId, ['sendUpdates' => 'none']);
            } catch (ServiceException $exception) {
                if (! in_array($exception->getCode(), [404, 410], true)) {
                    throw $exception;
                }
            }
        });
    }

    private function guard(callable $request): mixed
    {
        try {
            return $request();
        } catch (GoogleCalendarException $exception) {
            throw $exception;
        } catch (ServiceException $exception) {
            throw new GoogleCalendarException($exception->getCode() === 401);
        } catch (RequestException $exception) {
            $error = json_decode((string) $exception->getResponse()?->getBody(), true)['error'] ?? null;
            throw new GoogleCalendarException($exception->getCode() === 401 || in_array($error, ['invalid_grant', 'invalid_token'], true));
        } catch (\Throwable) {
            throw new GoogleCalendarException;
        }
    }
}
