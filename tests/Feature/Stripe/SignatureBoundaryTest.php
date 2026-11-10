<?php

declare(strict_types=1);

namespace Tests\Feature\Stripe;

use App\Enums\PaymentStatus;
use App\Services\MeetingQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\StripeFixtures;
use Tests\Support\StripeWebhookSignature;
use Tests\TestCase;

#[Group('external-api')]
class SignatureBoundaryTest extends TestCase
{
    use RefreshDatabase, StripeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeStripe();
    }

    public function test_future_timestamp_outside_tolerance_does_not_mutate_purchase_or_quota(): void
    {
        $payment = $this->pendingPayment();
        $snapshot = $payment->fresh()->getRawOriginal();
        $remaining = app(MeetingQuotaService::class)->remaining($payment->user);
        $body = $this->stripePayload($payment);
        $this->stripePost($body, StripeWebhookSignature::sign($body, 'whsec_sa03_fake', time() + 600))->assertStatus(400);
        $this->assertSame($snapshot, $payment->fresh()->getRawOriginal());
        $this->assertSame($remaining, app(MeetingQuotaService::class)->remaining($payment->user));
        $this->assertSame(0, $this->gateway->retrieves);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
        $this->assertDatabaseCount('stripe_webhook_events', 0);
    }

    public function test_multiple_signatures_accept_one_valid_signature_over_exact_unicode_body(): void
    {
        $payment = $this->pendingPayment();
        $event = json_decode($this->stripePayload($payment), true);
        $event['synthetic_note'] = '架空の署名検証データ';
        $body = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $signature = StripeWebhookSignature::sign($body, 'whsec_sa03_fake').',v1=synthetic-invalid';
        $this->stripePost($body, $signature)->assertOk();
        $this->stripePost($body, $signature)->assertOk();
        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
        $this->assertDatabaseCount('meeting_quota_transactions', 1);
        $this->assertDatabaseCount('stripe_webhook_events', 1);
    }
}
