<?php

declare(strict_types=1);

namespace App\Integrations\Stripe;

use App\Contracts\StripeCheckoutGateway;
use App\Models\Payment;
use Stripe\StripeClient;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

final class StripeApiCheckoutGateway implements StripeCheckoutGateway
{
    public function isConfigured(): bool
    {
        return str_starts_with((string) config('services.stripe.secret'), 'sk_test_')
            && str_starts_with((string) config('services.stripe.webhook_secret'), 'whsec_');
    }

    public function create(Payment $payment): array
    {
        $client = $this->client();
        $baseUrl = rtrim((string) config('app.url'), '/');
        try {
            $session = $client->checkout->sessions->create([
                'mode' => 'payment',
                'payment_method_types' => ['card'],
                'adaptive_pricing' => ['enabled' => false],
                'automatic_tax' => ['enabled' => false],
                'allow_promotion_codes' => false,
                'client_reference_id' => $payment->id,
                'metadata' => ['payment_id' => $payment->id],
                'line_items' => [[
                    'price_data' => [
                        'currency' => 'jpy',
                        'unit_amount' => $payment->amount,
                        'product_data' => ['name' => $payment->pack_name],
                    ],
                    'quantity' => 1,
                ]],
                'success_url' => $baseUrl.route('meeting-quota.checkout.success', [], false).'?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $baseUrl.route('meeting-quota.checkout.select', ['canceled' => 1], false),
            ], ['idempotency_key' => 'meeting-pack:'.$payment->id]);
            $url = (string) $session->url;
            if ($session->livemode !== false || ! str_starts_with((string) $session->id, 'cs_test_')
                || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== 'checkout.stripe.com') {
                throw new \UnexpectedValueException;
            }

            return ['id' => $session->id, 'url' => $url];
        } catch (Throwable) {
            // Never retain provider exceptions: they may contain request bodies/credentials.
            throw new ServiceUnavailableHttpException(null, '決済画面を準備できませんでした。時間をおいて再度お試しください。');
        }
    }

    public function retrieve(string $sessionId): array
    {
        $client = $this->client();
        try {
            $s = $client->checkout->sessions->retrieve($sessionId, []);

            return [
                'id' => $s->id, 'livemode' => $s->livemode, 'mode' => $s->mode,
                'status' => $s->status, 'payment_status' => $s->payment_status,
                'amount_total' => $s->amount_total, 'currency' => $s->currency,
                'payment_intent' => is_string($s->payment_intent) ? $s->payment_intent : null,
                'client_reference_id' => $s->client_reference_id,
                'payment_id' => $s->metadata?->payment_id,
            ];
        } catch (Throwable) {
            throw new ServiceUnavailableHttpException(null, '決済状態を確認できませんでした。');
        }
    }

    private function client(): StripeClient
    {
        if (! $this->isConfigured()) {
            throw new ServiceUnavailableHttpException(null, 'Stripeテスト決済の設定が完了していません。');
        }

        return new StripeClient(['api_key' => config('services.stripe.secret'), 'max_network_retries' => 0]);
    }
}
