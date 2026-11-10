<?php

declare(strict_types=1);

namespace Tests\Support;

/** Signs exact bytes with an explicitly supplied synthetic test secret. Never contacts Stripe. */
final class StripeWebhookSignature
{
    public static function sign(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }
}
