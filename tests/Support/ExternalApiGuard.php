<?php

declare(strict_types=1);

namespace Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Assert;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface as StripeClientInterface;
use Stripe\HttpClient\StreamingClientInterface;

final class ExternalApiGuard
{
    private static array $violations = [];

    private static bool $initialized = false;

    public static function initializeProcess(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;
        self::protectStripe();
        register_shutdown_function(function (): void {
            if (self::$violations !== []) {
                fwrite(STDERR, 'Unmocked external API request blocked: '.implode(', ', self::$violations).PHP_EOL);
                exit(1);
            }
        });
    }

    public static function beginTest(): void
    {
        self::initializeProcess();
        self::$violations = [];
        self::protectStripe();
    }

    public static function install(Application $app): void
    {
        if (! $app->environment('testing')) {
            throw new \LogicException('ExternalApiGuard is only available in testing.');
        }
        self::initializeProcess();
        // No real credentials inherited from a developer's .env are used by default.
        $app['config']->set([
            'services.google_calendar.client_id' => null,
            'services.google_calendar.client_secret' => null,
            'services.stripe.secret' => null,
            'services.stripe.webhook_secret' => null,
            'ai-chat.gemini.api_key' => null,
        ]);
        Http::swap(new GuardedHttpFactory($app['events']));
        Http::preventStrayRequests();
        $app->bind(ClientInterface::class, fn () => new Client(['handler' => HandlerStack::create(self::guzzleHandler()), 'connect_timeout' => 2, 'timeout' => 5]));
        self::protectStripe();
    }

    public static function guzzleHandler(string $channel = 'google'): \Closure
    {
        return fn () => self::deny($channel);
    }

    public static function deny(string $channel): never
    {
        // Store only the transport label: never retain URL, query, headers, body or credentials.
        self::$violations[] = $channel;
        throw new \LogicException('Unmocked external API request blocked: '.$channel);
    }

    public static function protectStripe(): void
    {
        $client = new class implements StreamingClientInterface, StripeClientInterface
        {
            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                ExternalApiGuard::deny('stripe');
            }

            public function requestStream($method, $absUrl, $headers, $params, $hasFile, $readBodyChunkCallable, $maxNetworkRetries = null)
            {
                ExternalApiGuard::deny('stripe');
            }
        };
        ApiRequestor::setHttpClient($client);
        ApiRequestor::setStreamingHttpClient($client);
    }

    public static function assertClean(): void
    {
        $violations = self::consumeViolations();
        if ($violations !== []) {
            Assert::fail('Unmocked external API request blocked: '.implode(', ', $violations));
        }
    }

    /** Used only by guard self-tests that deliberately verify denial. */
    public static function consumeViolations(): array
    {
        $violations = self::$violations;
        self::$violations = [];

        return $violations;
    }
}
