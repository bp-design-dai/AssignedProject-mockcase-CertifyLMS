<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\MeetingPackStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function create(User $user): bool
    {
        return $user->role === UserRole::Student && $user->status === UserStatus::InProgress && ! $user->trashed();
    }

    public function purchase(User $user, MeetingPack $pack): bool
    {
        return $this->create($user) && $pack->status === MeetingPackStatus::Published && $pack->price > 0 && $pack->meeting_count > 0;
    }

    public function view(User $user, Payment $payment): bool
    {
        return $this->create($user) && $payment->user_id === $user->id;
    }
}
