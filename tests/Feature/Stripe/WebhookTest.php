<?php

declare(strict_types=1);

namespace Tests\Feature\Stripe;

use App\Enums\PaymentStatus;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use App\Services\MeetingQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\Support\StripeFixtures;
use Tests\Support\StripeWebhookSignature;
use Tests\TestCase;

#[Group('external-api')]
class WebhookTest extends TestCase
{
    use RefreshDatabase, StripeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeStripe();
    }

    public function test_confirmed_payment_credits_once_and_preserves_initial_quota(): void
    {
        $buyer = User::factory()->create(['max_meetings' => 2]);
        $payment = $this->pendingPayment(['user_id' => $buyer->id, 'quantity' => 5]);
        $this->stripePost($this->stripePayload($payment))->assertOk()->assertExactJson(['received' => true]);
        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->fulfilled_at);
        $this->assertSame(2, $buyer->fresh()->max_meetings);
        $this->assertSame(7, app(MeetingQuotaService::class)->remaining($buyer));
        $this->assertDatabaseHas('meeting_quota_transactions', ['user_id' => $buyer->id, 'type' => 'purchased', 'amount' => 5, 'related_payment_id' => $payment->id]);
        $this->assertDatabaseCount('stripe_webhook_events', 1);
    }

    #[DataProvider('badSignatures')]
    public function test_invalid_missing_stale_or_tampered_signatures_are_rejected(string $kind): void
    {
        $payment = $this->pendingPayment();
        $snapshot = $payment->fresh()->getRawOriginal();
        $remaining = app(MeetingQuotaService::class)->remaining($payment->user);
        $body = $this->stripePayload($payment);
        $timestamp = $kind === 'stale' ? time() - 600 : time();
        $signed = $kind === 'tampered' ? $body.' ' : $body;
        $signature = match ($kind) {
            'missing' => '', 'invalid' => 't='.$timestamp.',v1=invalid',
            default => StripeWebhookSignature::sign($signed, 'whsec_sa03_fake', $timestamp),
        };
        $this->stripePost($body, $signature)->assertStatus(400);
        $this->assertSame($snapshot, $payment->fresh()->getRawOriginal());
        $this->assertSame($remaining, app(MeetingQuotaService::class)->remaining($payment->user));
        $this->assertSame(0, $this->gateway->retrieves);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
        $this->assertDatabaseCount('stripe_webhook_events', 0);
    }

    public static function badSignatures(): array
    {
        return [['missing'], ['invalid'], ['stale'], ['tampered']];
    }

    public function test_malformed_json_and_live_mode_events_are_rejected(): void
    {
        $this->stripePost('{not-json')->assertStatus(400);
        $payment = $this->pendingPayment();
        $event = json_decode($this->stripePayload($payment), true);
        $event['livemode'] = true;
        $this->stripePost(json_encode($event))->assertStatus(400);
        $this->assertDatabaseCount('stripe_webhook_events', 0);
    }

    #[DataProvider('unpaidStatuses')]
    public function test_completed_event_does_not_credit_unpaid_or_free_session(string $status): void
    {
        $payment = $this->pendingPayment();
        $this->gateway->overrides['payment_status'] = $status;
        $this->stripePost($this->stripePayload($payment))->assertOk();
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertNull($payment->fresh()->fulfilled_at);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public static function unpaidStatuses(): array
    {
        return [['unpaid'], ['no_payment_required']];
    }

    public function test_async_success_after_unpaid_completion_is_fulfilled_once(): void
    {
        $payment = $this->pendingPayment();
        $this->gateway->overrides['payment_status'] = 'unpaid';
        $pending = $this->stripePayload($payment, 'evt_pending');
        $this->stripePost($pending)->assertOk();
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
        $this->gateway->overrides['payment_status'] = 'paid';
        $this->stripePost($this->stripePayload($payment, 'evt_paid', 'checkout.session.async_payment_succeeded'))->assertOk();
        $this->stripePost($pending)->assertOk();
        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
        $this->assertDatabaseCount('meeting_quota_transactions', 1);
        $this->assertDatabaseCount('stripe_webhook_events', 2);
    }

    public function test_same_event_and_distinct_success_events_never_credit_twice(): void
    {
        $payment = $this->pendingPayment(['quantity' => 3]);
        $body = $this->stripePayload($payment);
        $this->stripePost($body)->assertOk();
        $this->stripePost($body)->assertOk();
        $this->stripePost($this->stripePayload($payment, 'evt_other_completed'))->assertOk();
        $this->stripePost($this->stripePayload($payment, 'evt_async', 'checkout.session.async_payment_succeeded'))->assertOk();
        $this->assertDatabaseCount('meeting_quota_transactions', 1);
        $this->assertDatabaseCount('stripe_webhook_events', 3);
        $this->assertSame(3, $payment->quotaTransaction->amount);
    }

    #[DataProvider('invalidSessionFields')]
    public function test_provider_state_must_match_the_local_purchase(string $field, mixed $value): void
    {
        $payment = $this->pendingPayment();
        $this->gateway->overrides[$field] = $value;
        $this->stripePost($this->stripePayload($payment))->assertStatus(400);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
        $this->assertDatabaseCount('stripe_webhook_events', 0);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public static function invalidSessionFields(): array
    {
        return [['amount_total', 1], ['currency', 'usd'], ['mode', 'subscription'], ['livemode', true],
            ['payment_id', 'another-payment'], ['client_reference_id', 'another-payment'], ['id', 'cs_test_other'],
            ['payment_intent', null], ['status', 'invalid'], ['payment_status', 'invalid']];
    }

    public function test_paid_flag_without_complete_status_does_not_credit(): void
    {
        $payment = $this->pendingPayment();
        $this->gateway->overrides['status'] = 'open';
        $this->stripePost($this->stripePayload($payment))->assertOk();
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    #[DataProvider('terminalFailures')]
    public function test_failure_and_expiration_do_not_credit(string $eventType, string $sessionStatus, PaymentStatus $expected): void
    {
        $payment = $this->pendingPayment();
        $this->gateway->overrides = ['status' => $sessionStatus, 'payment_status' => 'unpaid'];
        $this->stripePost($this->stripePayload($payment, 'evt_failure', $eventType))->assertOk();
        $this->assertSame($expected, $payment->fresh()->status);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public static function terminalFailures(): array
    {
        return [['checkout.session.async_payment_failed', 'complete', PaymentStatus::Failed], ['checkout.session.expired', 'expired', PaymentStatus::Expired]];
    }

    public function test_old_failure_cannot_downgrade_a_fulfilled_purchase(): void
    {
        $payment = $this->pendingPayment();
        $this->stripePost($this->stripePayload($payment))->assertOk();
        $this->gateway->overrides['payment_status'] = 'unpaid';
        $this->stripePost($this->stripePayload($payment, 'evt_delayed_failure', 'checkout.session.async_payment_failed'))->assertOk();
        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
        $this->assertDatabaseCount('meeting_quota_transactions', 1);
    }

    public function test_delayed_unpaid_completion_cannot_replace_a_failure_with_pending(): void
    {
        $payment = $this->pendingPayment();
        $this->gateway->overrides['payment_status'] = 'unpaid';
        $this->stripePost($this->stripePayload($payment, 'evt_failure', 'checkout.session.async_payment_failed'))->assertOk();
        $this->stripePost($this->stripePayload($payment, 'evt_delayed_completion'))->assertOk();
        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public function test_unknown_events_and_unrelated_checkout_sessions_are_safely_ignored(): void
    {
        $payment = $this->pendingPayment();
        $this->stripePost($this->stripePayload($payment, 'evt_refund', 'charge.refunded'))->assertOk();
        $unknown = json_decode($this->stripePayload($payment, 'evt_unknown'), true);
        $unknown['data']['object']['id'] = 'cs_test_unrelated';
        $unknown['data']['object']['metadata'] = [];
        $this->stripePost(json_encode($unknown))->assertOk();
        $this->assertDatabaseCount('stripe_webhook_events', 0);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
        $this->assertSame(0, $this->gateway->retrieves);
    }

    #[DataProvider('changedBuyerStatuses')]
    public function test_payment_confirmed_after_buyer_status_change_is_still_credited(string $status): void
    {
        $payment = $this->pendingPayment(['quantity' => 2]);
        $buyer = $payment->user;
        if ($status === 'deleted') {
            $buyer->delete();
        } else {
            $buyer->update(['status' => $status]);
        }
        $this->stripePost($this->stripePayload($payment))->assertOk();
        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
        $this->assertSame(2, $payment->quotaTransaction->amount);
    }

    public static function changedBuyerStatuses(): array
    {
        return [['graduated'], ['withdrawn'], ['deleted']];
    }

    public function test_provider_failure_is_retryable_and_does_not_acknowledge_success(): void
    {
        $payment = $this->pendingPayment();
        $this->gateway->onRetrieve = fn () => throw new ServiceUnavailableHttpException(null, '安全な再試行用エラー');
        $body = $this->stripePayload($payment);
        $this->stripePost($body)->assertStatus(503);
        $this->assertDatabaseCount('stripe_webhook_events', 0);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
        $this->gateway->onRetrieve = null;
        $this->stripePost($body)->assertOk();
        $this->assertDatabaseCount('meeting_quota_transactions', 1);
    }

    #[DataProvider('failureModels')]
    public function test_db_failures_roll_back_all_writes_and_same_event_can_recover(string $model): void
    {
        $payment = $this->pendingPayment();
        $fail = true;
        $callback = function () use (&$fail) {
            if ($fail) {
                throw new \RuntimeException('Simulated database write failure.');
            }
        };
        if ($model === Payment::class) {
            $model::updating($callback);
        } else {
            $model::creating($callback);
        }
        try {
            $body = $this->stripePayload($payment);
            $this->stripePost($body)->assertStatus(500);
            $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
            $this->assertNull($payment->fresh()->fulfilled_at);
            $this->assertDatabaseCount('meeting_quota_transactions', 0);
            $this->assertDatabaseCount('stripe_webhook_events', 0);
            $fail = false;
            $this->stripePost($body)->assertOk();
            $this->assertDatabaseCount('meeting_quota_transactions', 1);
            $this->assertDatabaseCount('stripe_webhook_events', 1);
        } finally {
            $model::flushEventListeners();
        }
    }

    public static function failureModels(): array
    {
        return [[Payment::class], [MeetingQuotaTransaction::class], [StripeWebhookEvent::class]];
    }

    public function test_another_purchase_cannot_reuse_a_payment_intent(): void
    {
        $first = $this->pendingPayment();
        $second = $this->pendingPayment();
        $this->gateway->overrides['payment_intent'] = 'pi_shared';
        $this->stripePost($this->stripePayload($first, 'evt_first'))->assertOk();
        $this->stripePost($this->stripePayload($second, 'evt_second'))->assertStatus(500);
        $this->assertSame(PaymentStatus::Pending, $second->fresh()->status);
        $this->assertDatabaseCount('meeting_quota_transactions', 1);
        $this->assertDatabaseCount('stripe_webhook_events', 1);
    }

    public function test_only_webhook_is_exempt_from_csrf_and_signature_is_still_required(): void
    {
        $this->app->bind(VerifyCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends VerifyCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $payment = $this->pendingPayment();
        $body = $this->stripePayload($payment);
        $this->stripePost($body, '')->assertStatus(400);
        $this->stripePost($body)->assertOk();
        $this->actingAs($payment->user)->post('/meeting-quota/checkout', ['meeting_pack_id' => $payment->meeting_pack_id])->assertStatus(419);
    }
}
