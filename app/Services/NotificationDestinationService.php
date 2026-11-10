<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActivityNotificationType;
use App\Enums\UserRole;
use App\Models\ChatRoom;
use App\Models\Meeting;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;

final class NotificationDestinationService
{
    public function resolve(User $user, DatabaseNotification $notification, bool $absolute = true): string
    {
        if (($notification->data['notification_type'] ?? null) === 'admin_announcement') {
            Gate::forUser($user)->authorize('view', $notification);

            return route('notifications.show', $notification, $absolute);
        }

        if (($notification->data['notification_type'] ?? null) === 'google_calendar_error') {
            abort_unless($user->role === UserRole::Coach, 403);

            return route('settings.availability.index', [], $absolute);
        }

        $type = ActivityNotificationType::tryFrom($notification->data['notification_type'] ?? '');
        $id = $notification->data['resource_id'] ?? null;

        // Rebuild internal routes and recheck access instead of trusting stored URLs.
        return match ($type) {
            ActivityNotificationType::ChatMessageReceived => $this->resourceUrl($user, ChatRoom::findOrFail($id), 'chat.show', $absolute),
            ActivityNotificationType::QaReplyReceived => $this->resourceUrl($user, QaThread::findOrFail($id), 'qa-board.show', $absolute),
            ActivityNotificationType::MeetingReserved, ActivityNotificationType::MeetingCanceled, ActivityNotificationType::MeetingReminder => $this->meetingUrl($user, Meeting::findOrFail($id), $absolute),
            default => route('notifications.index', [], $absolute),
        };
    }

    private function resourceUrl(User $user, ChatRoom|QaThread $resource, string $route, bool $absolute): string
    {
        Gate::forUser($user)->authorize('view', $resource);

        return route($route, $resource, $absolute);
    }

    private function meetingUrl(User $user, Meeting $meeting, bool $absolute): string
    {
        Gate::forUser($user)->authorize('view', $meeting);

        return route($user->role === UserRole::Coach ? 'coach.meetings.index' : 'meetings.index', [], $absolute);
    }
}
