<?php

declare(strict_types=1);

namespace App\UseCases\MeetingQuota;

use App\Contracts\StripeCheckoutGateway;
use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class CreateCheckoutAction
{
    public function __construct(private readonly StripeCheckoutGateway $gateway) {}

    public function __invoke(User $user, string $packId): string
    {
        Gate::forUser($user)->authorize('create', Payment::class);
        if (! $this->gateway->isConfigured() || ! Schema::hasTable('payments')) {
            throw new ServiceUnavailableHttpException(null, '現在、追加面談の決済を利用できません。');
        }
        $payment = DB::transaction(function () use ($user, $packId) {
            $buyer = User::withTrashed()->lockForUpdate()->findOrFail($user->id);
            Gate::forUser($buyer)->authorize('create', Payment::class);
            $pack = MeetingPack::purchasableWithStripe()->lockForUpdate()->find($packId);
            if ($pack === null) {
                // Preserve 404 for a missing pack and 403 for an ineligible pack.
                MeetingPack::lockForUpdate()->findOrFail($packId);
                abort(403);
            }

            return Payment::create([
                'user_id' => $buyer->id, 'meeting_pack_id' => $pack->id,
                'pack_name' => $pack->name, 'amount' => $pack->price, 'quantity' => $pack->meeting_count,
                'currency' => 'jpy', 'status' => PaymentStatus::Pending,
            ]);
        });

        // Network I/O runs after committing the immutable price/count snapshot, without row locks.
        try {
            $session = $this->gateway->create($payment);
        } catch (ServiceUnavailableHttpException $e) {
            Payment::whereKey($payment->id)->where('status', PaymentStatus::Pending)->update([
                'status' => PaymentStatus::Failed, 'failure_code' => 'checkout_unavailable',
            ]);
            throw $e;
        }

        DB::transaction(function () use ($payment, $session) {
            $locked = Payment::lockForUpdate()->findOrFail($payment->id);
            // A fast webhook may have confirmed this same Session before this response arrived.
            if ($locked->stripe_session_id !== null && $locked->stripe_session_id !== $session['id']) {
                throw new \LogicException('Checkout Session mismatch.');
            }
            $locked->update(['stripe_session_id' => $session['id']]);
        });

        return $session['url'];
    }
}
