<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use App\Exceptions\GoogleCalendarException;
use App\Integrations\GoogleCalendar\GoogleApiCalendarGateway;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoogleCalendarGatewayTest extends TestCase
{
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google_calendar' => ['client_id' => 'synthetic-client', 'client_secret' => 'synthetic-secret',
            'redirect_uri' => 'http://localhost:8000/settings/google-calendar/callback']]);
    }

    private function gateway(array $responses): GoogleApiCalendarGateway
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new GoogleApiCalendarGateway(new Client(['handler' => $stack]));
    }

    private function mockResponse(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    public function test_auth_url_has_state_offline_consent_pkce_scopes_and_no_secret(): void
    {
        $url = $this->gateway([])->authorizationUrl('state-value', str_repeat('p', 96));
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('accounts.google.com', parse_url($url, PHP_URL_HOST));
        $this->assertSame('state-value', $query['state']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', str_repeat('p', 96), true)), '+/', '-_'), '='), $query['code_challenge']);
        $this->assertEqualsCanonicalizing(GoogleApiCalendarGateway::SCOPES, explode(' ', $query['scope']));
        $this->assertArrayNotHasKey('client_secret', $query);
    }

    public function test_exchange_and_refresh_use_mocked_oauth_requests(): void
    {
        $gateway = $this->gateway([$this->mockResponse(['access_token' => 'access-one', 'refresh_token' => 'refresh-one', 'expires_in' => 3600]),
            $this->mockResponse(['access_token' => 'access-two', 'expires_in' => 3600])]);
        $this->assertSame('access-one', $gateway->exchange('code-one', str_repeat('v', 96))['access_token']);
        $this->assertSame('access-two', $gateway->refresh('refresh-one')['access_token']);
        parse_str((string) $this->history[0]['request']->getBody(), $exchange);
        parse_str((string) $this->history[1]['request']->getBody(), $refresh);
        $this->assertSame('authorization_code', $exchange['grant_type']);
        $this->assertSame(str_repeat('v', 96), $exchange['code_verifier']);
        $this->assertSame('refresh_token', $refresh['grant_type']);
        $this->assertSame('refresh-one', $refresh['refresh_token']);
    }

    public function test_freebusy_requests_only_primary_and_returns_only_expanded_busy_periods(): void
    {
        $periods = [['start' => '2030-06-17T00:00:00+09:00', 'end' => '2030-06-18T00:00:00+09:00']];
        $gateway = $this->gateway([$this->mockResponse(['calendars' => ['primary' => ['busy' => $periods]]])]);
        $result = $gateway->busy('synthetic-access', Carbon::parse('2030-06-17 00:00:00'), Carbon::parse('2030-06-18 00:00:00'));
        $this->assertSame($periods, $result);
        $request = $this->history[0]['request'];
        $this->assertSame('/calendar/v3/freeBusy', $request->getUri()->getPath());
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame([['id' => 'primary']], $body['items']);
        $this->assertSame('Asia/Tokyo', $body['timeZone']);
        $this->assertArrayNotHasKey('summary', $body);
    }

    public function test_event_creation_uses_primary_no_attendees_or_conference_and_no_invite_updates(): void
    {
        $gateway = $this->gateway([$this->mockResponse(['id' => str_repeat('a', 64)])]);
        $gateway->createEvent('synthetic-access', ['id' => str_repeat('a', 64), 'summary' => 'Certify LMS面談｜検証受講生',
            'location' => 'https://meet.example.test/fixed', 'start' => ['dateTime' => '2030-06-17T10:00:00+09:00'], 'end' => ['dateTime' => '2030-06-17T11:00:00+09:00']]);
        $request = $this->history[0]['request'];
        $body = json_decode((string) $request->getBody(), true);
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertSame('/calendar/v3/calendars/primary/events', $request->getUri()->getPath());
        $this->assertSame('none', $query['sendUpdates']);
        $this->assertSame('Certify LMS面談｜検証受講生', $body['summary']);
        $this->assertSame('https://meet.example.test/fixed', $body['location']);
        $this->assertArrayNotHasKey('attendees', $body);
        $this->assertArrayNotHasKey('conferenceData', $body);
    }

    #[DataProvider('deletedStatuses')]
    public function test_delete_is_primary_silent_and_idempotent(int $status): void
    {
        $gateway = $this->gateway([$status === 204 ? new Response(204) : $this->mockResponse(['error' => ['code' => $status, 'message' => 'synthetic-private-error']], $status)]);
        $gateway->deleteEvent('synthetic-access', str_repeat('b', 64));
        $request = $this->history[0]['request'];
        $this->assertSame('DELETE', $request->getMethod());
        $this->assertSame('/calendar/v3/calendars/primary/events/'.str_repeat('b', 64), $request->getUri()->getPath());
        $this->assertStringContainsString('sendUpdates=none', $request->getUri()->getQuery());
    }

    public static function deletedStatuses(): array
    {
        return [[204], [404], [410]];
    }

    public function test_replayed_create_checks_the_existing_meeting_identity(): void
    {
        $gateway = $this->gateway([$this->mockResponse(['error' => ['code' => 409, 'message' => 'duplicate']], 409),
            $this->mockResponse(['id' => str_repeat('c', 64), 'extendedProperties' => ['private' => ['lms_meeting_id' => 'meeting-one']]])]);
        $gateway->createEvent('synthetic-access', ['id' => str_repeat('c', 64), 'extendedProperties' => ['private' => ['lms_meeting_id' => 'meeting-one']]]);
        $this->assertCount(2, $this->history);
        $this->assertSame('GET', $this->history[1]['request']->getMethod());
    }

    public function test_a_conflicting_unrelated_event_is_not_treated_as_success(): void
    {
        $gateway = $this->gateway([$this->mockResponse(['error' => ['code' => 409, 'message' => 'duplicate']], 409),
            $this->mockResponse(['extendedProperties' => ['private' => ['lms_meeting_id' => 'other-meeting']]])]);
        $this->expectException(GoogleCalendarException::class);
        $gateway->createEvent('synthetic-access', ['id' => str_repeat('c', 64), 'extendedProperties' => ['private' => ['lms_meeting_id' => 'meeting-one']]]);
    }

    #[DataProvider('apiErrors')]
    public function test_provider_errors_are_sanitized_and_not_retried(int $status, bool $auth): void
    {
        $gateway = $this->gateway([$this->mockResponse(['error' => ['code' => $status, 'message' => 'private-event-and-token']], $status)]);
        try {
            $gateway->busy('synthetic-access', Carbon::now(), Carbon::now()->addHour());
            $this->fail('Expected Google failure.');
        } catch (GoogleCalendarException $exception) {
            $this->assertSame($auth, $exception->authenticationFailure);
            $this->assertStringNotContainsString('private-event-and-token', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $this->assertCount(1, $this->history);
    }

    public static function apiErrors(): array
    {
        return [[401, true], [403, false], [500, false]];
    }

    public function test_invalid_grant_on_refresh_is_a_sanitized_authentication_failure(): void
    {
        $gateway = $this->gateway([$this->mockResponse(['error' => 'invalid_grant', 'error_description' => 'private-secret'], 400)]);
        try {
            $gateway->refresh('synthetic-refresh');
            $this->fail('Expected refresh failure.');
        } catch (GoogleCalendarException $exception) {
            $this->assertTrue($exception->authenticationFailure);
            $this->assertStringNotContainsString('private-secret', $exception->getMessage());
        }
    }

    public function test_partial_freebusy_errors_are_not_misread_as_an_empty_calendar(): void
    {
        $gateway = $this->gateway([$this->mockResponse(['calendars' => ['primary' => ['errors' => [['reason' => 'internalError']]]]])]);
        $this->expectException(GoogleCalendarException::class);
        $gateway->busy('synthetic-access', Carbon::now(), Carbon::now()->addHour());
    }
}
