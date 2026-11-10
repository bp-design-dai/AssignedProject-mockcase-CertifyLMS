<?php

declare(strict_types=1);

namespace App\UseCases\Api\Notification;

use App\Models\User;
use App\Services\NotificationDestinationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class IndexAction
{
    public function __construct(private readonly NotificationDestinationService $destinations) {}

    public function __invoke(User $user, string $tab): array
    {
        $notifications = $user->notifications()
            ->when($tab === 'unread', fn ($query) => $query->whereNull('read_at'))
            ->reorder()->orderByDesc('created_at')->orderByDesc('id')->limit(10)->get();

        return [
            'notifications' => $notifications->map(fn ($notification) => $this->serialize($user, $notification))->all(),
            'unread_count' => $user->unreadNotifications()->count(),
        ];
    }

    private function serialize(User $user, DatabaseNotification $notification): array
    {
        try {
            $url = $this->destinations->resolve($user, $notification, absolute: false);
        } catch (AuthorizationException|ModelNotFoundException) {
            $url = null;
        } catch (HttpException $exception) {
            if (! in_array($exception->getStatusCode(), [403, 404], true)) {
                throw $exception;
            }
            $url = null;
        }

        $data = $notification->data;

        return [
            'id' => $notification->id,
            'title' => is_string($data['title'] ?? null) ? $data['title'] : '通知',
            'preview' => Str::limit(is_string($data['message'] ?? null) ? $data['message'] : '', 120),
            'created_at' => $notification->created_at->toIso8601String(),
            'created_at_human' => $notification->created_at->diffForHumans(),
            'is_read' => $notification->read_at !== null,
            'url' => $url,
        ];
    }
}
