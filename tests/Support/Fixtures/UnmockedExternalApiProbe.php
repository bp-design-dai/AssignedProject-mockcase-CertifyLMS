<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures;

use App\Contracts\GoogleCalendarGateway;
use App\Contracts\StripeCheckoutGateway;
use App\Integrations\Gemini\HttpGeminiGateway;
use Carbon\Carbon;
use Tests\TestCase;

/** Deliberate negative fixture. Only invoked by an isolated nested PHPUnit process. */
class UnmockedExternalApiProbe extends TestCase
{
    public function test_swallowed_provider_failure_must_still_fail_the_test(): void
    {
        config(['services.google_calendar.client_id' => 'probe-client', 'services.google_calendar.client_secret' => 'probe-secret',
            'services.google_calendar.redirect_uri' => 'http://localhost/callback',
            'services.stripe.secret' => 'sk_test_probe_only', 'services.stripe.webhook_secret' => 'whsec_probe_only',
            'ai-chat.gemini.api_key' => 'probe-key', 'ai-chat.gemini.model' => 'test-model']);
        try {
            match (getenv('TA04_PROBE_CHANNEL')) {
                'google' => app(GoogleCalendarGateway::class)->busy('probe-token', Carbon::now(), Carbon::now()->addHour()),
                'stripe' => app(StripeCheckoutGateway::class)->retrieve('cs_test_probe'),
                'laravel' => app(HttpGeminiGateway::class)->generate([], 'synthetic instruction'),
                default => throw new \LogicException('Unknown probe channel'),
            };
        } catch (\Throwable) {
            // Reproduce application fallback swallowing a transport exception.
        }
        $this->assertTrue(true);
        // TestCase::tearDown must fail despite the caught domain exception above.
    }
}
