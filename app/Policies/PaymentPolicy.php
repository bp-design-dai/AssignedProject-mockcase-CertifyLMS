<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function create(User $user): bool
    {
        return $user->role === UserRole::Student && $user->status === UserStatus::InProgress && ! $user->trashed();
    }

    public function view(User $user, Payment $payment): bool
    {
        return $this->create($user) && $payment->user_id === $user->id;
    }
}
