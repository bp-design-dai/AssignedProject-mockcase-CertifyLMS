<?php

declare(strict_types=1);

namespace App\UseCases\Api\Notification;

use App\Models\User;
use App\UseCases\Notification\MarkAllAsReadAction as MarkNotificationsAsRead;

final class MarkAllAsReadAction
{
    public function __construct(private readonly MarkNotificationsAsRead $markAllAsRead) {}

    public function __invoke(User $user): array
    {
        ($this->markAllAsRead)($user);

        return ['unread_count' => $user->unreadNotifications()->count()];
    }
}
