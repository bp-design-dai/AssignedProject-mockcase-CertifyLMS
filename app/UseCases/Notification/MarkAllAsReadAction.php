<?php

declare(strict_types=1);

namespace App\UseCases\Notification;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;

final class MarkAllAsReadAction
{
    public function __invoke(User $user): void
    {
        Gate::forUser($user)->authorize('viewAny', DatabaseNotification::class);
        $user->unreadNotifications()->update(['read_at' => now()]);
    }
}
