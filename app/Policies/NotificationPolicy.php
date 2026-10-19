<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

final class NotificationPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::Student, UserRole::Coach], true);
    }

    public function update(User $user, DatabaseNotification $notification): bool
    {
        return $this->viewAny($user) && $notification->notifiable_type === $user->getMorphClass()
            && $notification->notifiable_id === $user->id;
    }
}
