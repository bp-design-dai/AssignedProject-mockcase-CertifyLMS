<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\StripeCheckoutGateway;
use App\Models\Payment;
use Closure;

class FakeStripeGateway implements StripeCheckoutGateway
{
    public bool $configured = true;

    public int $creates = 0;

    public int $retrieves = 0;

    public array $overrides = [];

    public ?Closure $onCreate = null;

    public ?Closure $onRetrieve = null;

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function create(Payment $payment): array
    {
        $this->creates++;
        $this->onCreate?->__invoke($payment);

        return ['id' => 'cs_test_'.$payment->id, 'url' => 'https://checkout.stripe.com/c/pay/cs_test_'.$payment->id];
    }

    public function retrieve(string $sessionId): array
    {
        $this->retrieves++;
        $payment = Payment::where('stripe_session_id', $sessionId)->first()
            ?? Payment::findOrFail(substr($sessionId, strlen('cs_test_')));
        $this->onRetrieve?->__invoke($payment);

        return array_replace([
            'id' => $sessionId, 'livemode' => false, 'mode' => 'payment', 'status' => 'complete',
            'payment_status' => 'paid', 'amount_total' => $payment->amount, 'currency' => 'jpy',
            'payment_intent' => 'pi_'.$payment->id,
            'client_reference_id' => $payment->id, 'payment_id' => $payment->id,
        ], $this->overrides);
    }
}
