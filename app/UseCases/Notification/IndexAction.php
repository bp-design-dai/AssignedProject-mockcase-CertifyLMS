<?php

declare(strict_types=1);

namespace App\UseCases\Notification;

use App\Models\User;

final class IndexAction
{
    public function __invoke(User $user, string $tab): array
    {
        return [
            'notifications' => $user->notifications()->when($tab === 'unread', fn ($query) => $query->whereNull('read_at'))
                ->orderByDesc('id')->paginate(20)->withQueryString(),
            'unreadCount' => $user->unreadNotifications()->count(),
            'tab' => $tab,
        ];
    }
}
