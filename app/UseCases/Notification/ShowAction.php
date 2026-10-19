<?php

declare(strict_types=1);

namespace App\UseCases\Notification;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;

final class ShowAction
{
    public function __invoke(User $user, DatabaseNotification $notification): array
    {
        Gate::forUser($user)->authorize('view', $notification);
        $notification->markAsRead();

        return ['notification' => $notification];
    }
}
