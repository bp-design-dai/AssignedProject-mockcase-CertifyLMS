<?php

declare(strict_types=1);

namespace App\UseCases\Notification;

use App\Enums\ActivityNotificationType;
use App\Enums\UserRole;
use App\Models\ChatRoom;
use App\Models\Meeting;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;

final class MarkAsReadAction
{
    public function __invoke(User $user, DatabaseNotification $notification): string
    {
        Gate::forUser($user)->authorize('update', $notification);
        $type = ActivityNotificationType::tryFrom($notification->data['notification_type'] ?? '');
        $id = $notification->data['resource_id'] ?? null;

        // 保存された任意URLを信用せず、通知種別から同一アプリ内の正規ルートを再構成する。
        $url = match ($type) {
            ActivityNotificationType::ChatMessageReceived => $this->resourceUrl($user, ChatRoom::findOrFail($id), 'chat.show'),
            ActivityNotificationType::QaReplyReceived => $this->resourceUrl($user, QaThread::findOrFail($id), 'qa-board.show'),
            ActivityNotificationType::MeetingReserved, ActivityNotificationType::MeetingCanceled => $this->meetingUrl($user, Meeting::findOrFail($id)),
            default => route('notifications.index'),
        };

        $notification->markAsRead();

        return $url;
    }

    private function resourceUrl(User $user, ChatRoom|QaThread $resource, string $route): string
    {
        Gate::forUser($user)->authorize('view', $resource);

        return route($route, $resource);
    }

    private function meetingUrl(User $user, Meeting $meeting): string
    {
        Gate::forUser($user)->authorize('view', $meeting);

        return route($user->role === UserRole::Coach ? 'coach.meetings.index' : 'meetings.index');
    }
}
