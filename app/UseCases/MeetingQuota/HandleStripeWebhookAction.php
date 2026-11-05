<?php

declare(strict_types=1);

namespace App\UseCases\MeetingQuota;

use App\Contracts\StripeCheckoutGateway;
use App\Enums\MeetingQuotaTransactionType;
use App\Enums\PaymentStatus;
use App\Integrations\Stripe\StripeWebhookVerifier;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class HandleStripeWebhookAction
{
    private const TYPES = ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed', 'checkout.session.expired'];

    public function __construct(private readonly StripeWebhookVerifier $verifier, private readonly StripeCheckoutGateway $gateway) {}

    public function __invoke(string $payload, string $signature): void
    {
        $event = $this->verifier->verify($payload, $signature);
        if (! in_array($event['type'], self::TYPES, true)) {
            return;
        }
        $object = $event['data']['object'] ?? [];
        $sessionId = $object['id'] ?? null;
        if (($object['object'] ?? null) !== 'checkout.session' || ! is_string($sessionId) || strlen($sessionId) > 255 || ! preg_match('/\Acs_test_[A-Za-z0-9_]+\z/', $sessionId)) {
            throw new BadRequestHttpException('決済通知の形式が正しくありません。');
        }
        if (StripeWebhookEvent::whereKey($event['id'])->exists()) {
            return;
        }
        $payment = Payment::where('stripe_session_id', $sessionId)->first();
        if (! $payment && is_string($object['metadata']['payment_id'] ?? null)) {
            // Covers a webhook arriving before the Checkout-create response was stored locally.
            $payment = Payment::whereKey($object['metadata']['payment_id'])->whereNull('stripe_session_id')->first();
        }
        if (! $payment) {
            return; // Another application may share the account; do not create local purchases from events.
        }

        // Verify current Stripe state, not merely a successful-sounding event name or a browser redirect.
        $session = $this->gateway->retrieve($sessionId);
        $this->assertMatches($payment, $session, $sessionId);
        DB::transaction(function () use ($payment, $session, $event, $sessionId) {
            // Same student row used by booking: no Payment -> User lock inversion.
            User::withTrashed()->lockForUpdate()->findOrFail($payment->user_id);
            $locked = Payment::lockForUpdate()->findOrFail($payment->id);
            if (StripeWebhookEvent::whereKey($event['id'])->exists()) {
                return;
            }
            $this->assertMatches($locked, $session, $sessionId);
            if ($locked->fulfilled_at === null) {
                if ($session['status'] === 'complete' && $session['payment_status'] === 'paid') {
                    if (! is_string($session['payment_intent'] ?? null) || ! str_starts_with($session['payment_intent'], 'pi_')) {
                        throw new BadRequestHttpException('決済情報が一致しません。');
                    }
                    $locked->update([
                        'status' => PaymentStatus::Succeeded, 'stripe_session_id' => $sessionId,
                        'stripe_payment_intent_id' => $session['payment_intent'], 'failure_code' => null, 'fulfilled_at' => now(),
                    ]);
                    MeetingQuotaTransaction::create([
                        'user_id' => $locked->user_id, 'type' => MeetingQuotaTransactionType::Purchased,
                        'amount' => $locked->quantity, 'related_payment_id' => $locked->id,
                        'note' => '追加面談購入: '.$locked->pack_name, 'occurred_at' => now(),
                    ]);
                } else {
                    $status = match (true) {
                        $session['status'] === 'expired' => PaymentStatus::Expired,
                        $locked->status === PaymentStatus::Pending && $event['type'] === 'checkout.session.async_payment_failed' => PaymentStatus::Failed,
                        default => $locked->status,
                    };
                    $locked->update(['stripe_session_id' => $sessionId, 'status' => $status,
                        'failure_code' => $status === PaymentStatus::Failed ? ($locked->failure_code ?? 'payment_failed') : null]);
                }
            }
            // Written in the same transaction; failures remain retryable and never return false success.
            StripeWebhookEvent::create(['id' => $event['id'], 'payment_id' => $locked->id, 'type' => $event['type'], 'processed_at' => now()]);
        });
    }

    private function assertMatches(Payment $payment, array $session, string $sessionId): void
    {
        if ($payment->amount <= 0 || $payment->quantity <= 0 || $payment->currency !== 'jpy'
            || ($session['id'] ?? null) !== $sessionId || ($session['livemode'] ?? null) !== false
            || ($session['mode'] ?? null) !== 'payment' || ($session['currency'] ?? null) !== 'jpy'
            || ($session['amount_total'] ?? null) !== $payment->amount
            || ($session['payment_id'] ?? null) !== $payment->id || ($session['client_reference_id'] ?? null) !== $payment->id
            || ($payment->stripe_session_id !== null && $payment->stripe_session_id !== $sessionId)
            || ! in_array($session['status'] ?? null, ['open', 'complete', 'expired'], true)
            || ! in_array($session['payment_status'] ?? null, ['paid', 'unpaid', 'no_payment_required'], true)
            || ($payment->stripe_payment_intent_id !== null && ($session['payment_intent'] ?? null) !== $payment->stripe_payment_intent_id)) {
            throw new BadRequestHttpException('決済情報が一致しません。');
        }
    }
}
