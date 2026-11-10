<?php

declare(strict_types=1);

namespace App\UseCases\Notification;

use App\Models\User;
use App\Services\NotificationDestinationService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;

final class MarkAsReadAction
{
    public function __construct(private readonly NotificationDestinationService $destinations) {}

    public function __invoke(User $user, DatabaseNotification $notification, bool $absolute = true): string
    {
        Gate::forUser($user)->authorize('update', $notification);
        $url = $this->destinations->resolve($user, $notification, $absolute);
        $notification->markAsRead();

        return $url;
    }
}
