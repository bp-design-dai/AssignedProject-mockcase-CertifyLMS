<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\ActivityNotificationType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Events\ChatMessageSent;
use App\Events\MeetingCanceled;
use App\Events\MeetingReserved;
use App\Events\QaReplyCreated;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Services\ActivityNotificationService;
use Illuminate\Support\Facades\Gate;

final class SendActivityNotification
{
    public function __construct(private readonly ActivityNotificationService $notifications) {}

    public function handle(ChatMessageSent|QaReplyCreated|MeetingReserved|MeetingCanceled $event): void
    {
        if ($event instanceof ChatMessageSent) {
            $message = $event->message;
            $room = $message->chatRoom;
            $recipients = User::query()->whereHas('chatMembers', fn ($query) => $query->where('chat_room_id', $room->id))
                ->whereKeyNot($message->sender_user_id)->get();
            $type = ActivityNotificationType::ChatMessageReceived;
            $eventKey = $type->value.':'.$message->id;
            $resourceId = $room->id;
            $body = '参加しているチャットに新しいメッセージがあります。';
        } elseif ($event instanceof QaReplyCreated) {
            $thread = $event->reply->thread;
            $recipients = User::query()->whereKey($thread->user_id)->whereKeyNot($event->reply->user_id)->get();
            $type = ActivityNotificationType::QaReplyReceived;
            $eventKey = $type->value.':'.$event->reply->id;
            $resourceId = $thread->id;
            $body = '投稿した質問に新しい回答があります。';
        } else {
            $meeting = $event->meeting;
            $recipients = User::query()->whereIn('id', [$meeting->student_id, $meeting->coach_id])->get();
            $type = $event instanceof MeetingReserved ? ActivityNotificationType::MeetingReserved : ActivityNotificationType::MeetingCanceled;
            $eventKey = $type->value.':'.$meeting->id;
            $resourceId = $meeting->id;
            $body = $meeting->scheduled_at->format('Y/m/d H:i').'の'.$type->title().'。';
        }

        foreach ($recipients as $user) {
            if ($user->status !== UserStatus::InProgress || ! in_array($user->role, [UserRole::Student, UserRole::Coach], true)) {
                continue;
            }

            if ($event instanceof ChatMessageSent) {
                if (Gate::forUser($user)->denies('view', $room)) {
                    continue;
                }
                $url = route('chat.show', $room);
            } elseif ($event instanceof QaReplyCreated) {
                if (Gate::forUser($user)->denies('view', $thread)) {
                    continue;
                }
                $url = route('qa-board.show', $thread);
            } else {
                if (Gate::forUser($user)->denies('view', $meeting)) {
                    continue;
                }
                $url = route($user->role === UserRole::Coach ? 'coach.meetings.index' : 'meetings.index');
            }

            $this->notifications->send($user, $eventKey, new ActivityNotification($type, $resourceId, $body, $url));
        }
    }
}
