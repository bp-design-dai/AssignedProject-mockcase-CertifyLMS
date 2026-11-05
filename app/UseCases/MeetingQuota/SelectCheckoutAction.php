<?php

declare(strict_types=1);

namespace App\UseCases\MeetingQuota;

use App\Contracts\StripeCheckoutGateway;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

final class SelectCheckoutAction
{
    public function __construct(private readonly StripeCheckoutGateway $gateway) {}

    public function __invoke(User $user): array
    {
        Gate::forUser($user)->authorize('create', Payment::class);

        return [
            'plans' => MeetingPack::published()->where('price', '>', 0)->where('meeting_count', '>', 0)->ordered()->get(),
            'available' => $this->gateway->isConfigured() && Schema::hasTable('payments'),
        ];
    }
}
