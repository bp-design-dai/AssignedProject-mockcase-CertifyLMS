<?php

declare(strict_types=1);

namespace App\Integrations\Stripe;

use Stripe\Webhook;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

final class StripeWebhookVerifier
{
    public function verify(string $payload, string $signature): array
    {
        $secret = (string) config('services.stripe.webhook_secret');
        if (! str_starts_with($secret, 'whsec_')) {
            throw new ServiceUnavailableHttpException(null, '決済通知の受信準備が完了していません。');
        }
        try {
            $event = Webhook::constructEvent($payload, $signature, $secret)->toArray();
            if (($event['livemode'] ?? null) !== false || ! is_string($event['id'] ?? null)
                || $event['id'] === '' || strlen($event['id']) > 255 || ! is_string($event['type'] ?? null)) {
                throw new \UnexpectedValueException;
            }

            return $event;
        } catch (Throwable) {
            throw new BadRequestHttpException('決済通知を検証できません。');
        }
    }
}
