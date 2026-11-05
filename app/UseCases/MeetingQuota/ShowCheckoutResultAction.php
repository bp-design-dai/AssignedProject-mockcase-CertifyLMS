<?php

declare(strict_types=1);

namespace App\UseCases\MeetingQuota;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

final class ShowCheckoutResultAction
{
    public function __invoke(User $user, ?string $sessionId): ?Payment
    {
        Gate::forUser($user)->authorize('create', Payment::class);
        if (! $sessionId) {
            return null;
        }
        abort_unless(Schema::hasTable('payments'), 503, '現在、決済情報を確認できません。');
        $payment = Payment::where('user_id', $user->id)->where('stripe_session_id', $sessionId)->firstOrFail();
        Gate::forUser($user)->authorize('view', $payment);

        return $payment;
    }
}
