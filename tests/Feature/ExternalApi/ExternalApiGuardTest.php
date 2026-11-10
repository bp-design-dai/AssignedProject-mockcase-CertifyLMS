<?php

declare(strict_types=1);

namespace Tests\Feature\ExternalApi;

use App\Contracts\GoogleCalendarGateway;
use App\Contracts\StripeCheckoutGateway;
use App\Exceptions\GoogleCalendarException;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Stripe\ApiRequestor;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Process\Process;
use Tests\Support\ExternalApiGuard;
use Tests\TestCase;

#[Group('external-api')]
class ExternalApiGuardTest extends TestCase
{
    public function test_laravel_only_allows_explicitly_matched_fakes(): void
    {
        Http::fake(['https://synthetic.example.test/allowed' => Http::response(['mock' => true])]);
        $this->assertTrue(Http::get('https://synthetic.example.test/allowed')->json('mock'));
        try {
            Http::get('https://synthetic.example.test/other?private=must-not-escape');
            $this->fail('Unmatched HTTP request must be blocked.');
        } catch (\LogicException $error) {
            $this->assertSame('Unmocked external API request blocked: laravel', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
        $this->assertSame(['laravel'], ExternalApiGuard::consumeViolations());
        Http::assertSentCount(1);
    }

    public function test_laravel_terminal_handler_still_blocks_when_stray_flag_is_disabled(): void
    {
        Http::preventStrayRequests(false);
        try {
            Http::get('https://synthetic.example.test/unmocked');
            $this->fail('Terminal network handler must deny the request.');
        } catch (\LogicException $error) {
            $this->assertSame('Unmocked external API request blocked: laravel', $error->getMessage());
        }
        $this->assertSame(['laravel'], ExternalApiGuard::consumeViolations());
    }

    public function test_container_resolved_google_sdk_is_blocked_below_the_gateway(): void
    {
        config(['services.google_calendar.client_id' => 'synthetic-client', 'services.google_calendar.client_secret' => 'synthetic-secret',
            'services.google_calendar.redirect_uri' => 'http://localhost/callback']);
        try {
            app(GoogleCalendarGateway::class)->busy('synthetic-token', Carbon::now(), Carbon::now()->addHour());
            $this->fail('Google SDK must not reach a real transport.');
        } catch (GoogleCalendarException $error) {
            $this->assertNull($error->getPrevious());
        }
        $this->assertSame(['google'], ExternalApiGuard::consumeViolations());
    }

    public function test_container_resolved_stripe_sdk_is_blocked_below_the_gateway(): void
    {
        config(['services.stripe.secret' => 'sk_test_synthetic', 'services.stripe.webhook_secret' => 'whsec_synthetic']);
        try {
            app(StripeCheckoutGateway::class)->retrieve('cs_test_synthetic');
            $this->fail('Stripe SDK must not reach a real transport.');
        } catch (ServiceUnavailableHttpException $error) {
            $this->assertNull($error->getPrevious());
        }
        $this->assertSame(['stripe'], ExternalApiGuard::consumeViolations());
    }

    public function test_stripe_streaming_transport_is_also_blocked(): void
    {
        try {
            ApiRequestor::streamingHttpClient()->requestStream('get', 'https://api.stripe.com/synthetic', [], [], false, fn () => null);
            $this->fail('Streaming transport must be blocked.');
        } catch (\LogicException $error) {
            $this->assertSame('Unmocked external API request blocked: stripe', $error->getMessage());
        }
        $this->assertSame(['stripe'], ExternalApiGuard::consumeViolations());
    }

    #[DataProvider('channels')]
    public function test_swallowed_transport_exception_still_fails_an_independent_phpunit_process(string $channel): void
    {
        $process = new Process([PHP_BINARY, 'vendor/bin/phpunit', '--no-configuration', '--do-not-cache-result', '--bootstrap', 'tests/bootstrap.php',
            '--colors=never', 'tests/Support/Fixtures/UnmockedExternalApiProbe.php'], base_path(), ['TA04_PROBE_CHANNEL' => $channel]);
        $process->setTimeout(30)->run();
        $output = $process->getOutput().$process->getErrorOutput();
        $this->assertSame(1, $process->getExitCode(), $output);
        $this->assertStringContainsString('Unmocked external API request blocked: '.$channel, $output);
        $this->assertStringContainsString('Failures: 1', $output);
        $this->assertStringNotContainsString('probe-secret', $output);
        $this->assertStringNotContainsString('probe-key', $output);
        $this->assertStringNotContainsString('https://', $output);
    }

    public static function channels(): array
    {
        return [['laravel'], ['google'], ['stripe']];
    }
}
