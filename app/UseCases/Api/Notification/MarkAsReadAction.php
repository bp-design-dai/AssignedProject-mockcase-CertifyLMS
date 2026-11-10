<?php

declare(strict_types=1);

namespace App\UseCases\Api\Notification;

use App\Models\User;
use App\UseCases\Notification\MarkAsReadAction as MarkNotificationAsRead;

final class MarkAsReadAction
{
    public function __construct(private readonly MarkNotificationAsRead $markAsRead) {}

    public function __invoke(User $user, string $id): array
    {
        $notification = $user->notifications()->findOrFail($id);
        $url = ($this->markAsRead)($user, $notification, absolute: false);

        return ['url' => $url, 'unread_count' => $user->unreadNotifications()->count()];
    }
}
