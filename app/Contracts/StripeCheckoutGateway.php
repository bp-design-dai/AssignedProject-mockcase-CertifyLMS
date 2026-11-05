<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Payment;

interface StripeCheckoutGateway
{
    public function isConfigured(): bool;

    /** @return array{id: string, url: string} */
    public function create(Payment $payment): array;

    /** Normalized Checkout Session; no customer details or card data are returned. */
    public function retrieve(string $sessionId): array;
}
