<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use App\Exceptions\GoogleCalendarException;
use App\Integrations\GoogleCalendar\GoogleApiCalendarGateway;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('external-api')]
class GoogleCalendarFailureTest extends TestCase
{
    #[DataProvider('operations')]
    public function test_sdk_connection_failure_is_sanitized_without_implicit_retry(string $operation): void
    {
        config(['services.google_calendar.client_id' => 'synthetic-client', 'services.google_calendar.client_secret' => 'synthetic-secret',
            'services.google_calendar.redirect_uri' => 'http://localhost/callback']);
        $history = [];
        $handler = HandlerStack::create(new MockHandler([new ConnectException('synthetic-private-provider-detail', new Request('GET', 'https://synthetic.example.test'))]));
        $handler->push(Middleware::history($history));
        $gateway = new GoogleApiCalendarGateway(new Client(['handler' => $handler]));
        try {
            match ($operation) {
                'exchange' => $gateway->exchange('synthetic-code', str_repeat('v', 96)),
                'refresh' => $gateway->refresh('synthetic-refresh'),
                'busy' => $gateway->busy('synthetic-access', Carbon::now(), Carbon::now()->addHour()),
                'create' => $gateway->createEvent('synthetic-access', ['id' => 'synthetic-event']),
                'delete' => $gateway->deleteEvent('synthetic-access', 'synthetic-event'),
            };
            $this->fail('Transport failure must be reported safely.');
        } catch (GoogleCalendarException $error) {
            $this->assertFalse($error->authenticationFailure);
            $this->assertNull($error->getPrevious());
            $this->assertSame('Google Calendar is temporarily unavailable.', $error->getMessage());
        }
        $this->assertCount(1, $history);
    }

    public static function operations(): array
    {
        return [['exchange'], ['refresh'], ['busy'], ['create'], ['delete']];
    }

    #[DataProvider('invalidTokens')]
    public function test_malformed_successful_token_response_is_not_accepted(array $tokens): void
    {
        config(['services.google_calendar.client_id' => 'synthetic-client', 'services.google_calendar.client_secret' => 'synthetic-secret',
            'services.google_calendar.redirect_uri' => 'http://localhost/callback']);
        $history = [];
        $handler = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode($tokens)),
        ]));
        $handler->push(Middleware::history($history));
        $gateway = new GoogleApiCalendarGateway(new Client(['handler' => $handler]));
        try {
            $gateway->refresh('synthetic-refresh');
            $this->fail('Malformed token response must not be accepted.');
        } catch (GoogleCalendarException $error) {
            $this->assertFalse($error->authenticationFailure);
            $this->assertNull($error->getPrevious());
        }
        $this->assertCount(1, $history);
    }

    public static function invalidTokens(): array
    {
        return [[['expires_in' => 3600]], [['access_token' => 'synthetic', 'expires_in' => 0]], [['access_token' => 'synthetic', 'expires_in' => -1]]];
    }
}
