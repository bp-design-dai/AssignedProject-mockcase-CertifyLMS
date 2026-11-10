<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\StripeCheckoutGateway;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;

trait StripeFixtures
{
    protected FakeStripeGateway $gateway;

    protected function fakeStripe(): void
    {
        config(['services.stripe.secret' => 'sk_test_local_fake', 'services.stripe.webhook_secret' => 'whsec_sa03_fake']);
        $this->gateway = new FakeStripeGateway;
        $this->app->instance(StripeCheckoutGateway::class, $this->gateway);
        Http::preventStrayRequests();
        ExternalApiGuard::protectStripe();
    }

    protected function pendingPayment(array $attributes = []): Payment
    {
        $payment = Payment::factory()->create($attributes);
        $payment->update(['stripe_session_id' => 'cs_test_'.$payment->id]);

        return $payment;
    }

    protected function stripePayload(Payment $payment, string $id = 'evt_test_success', string $type = 'checkout.session.completed'): string
    {
        return json_encode(['id' => $id, 'object' => 'event', 'livemode' => false, 'type' => $type,
            'data' => ['object' => ['id' => $payment->stripe_session_id ?? 'cs_test_'.$payment->id,
                'object' => 'checkout.session', 'metadata' => ['payment_id' => $payment->id]]]], JSON_THROW_ON_ERROR);
    }

    protected function stripePost(string $payload, ?string $signature = null)
    {
        $signature ??= StripeWebhookSignature::sign($payload, 'whsec_sa03_fake');

        return $this->call('POST', '/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $payload);
    }
}
