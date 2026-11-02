<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Notification;

final class GoogleCalendarFailure extends Notification
{
    public function __construct(private readonly string $message) {}

    public function toDatabase(object $notifiable): array
    {
        return ['notification_type' => 'google_calendar_error', 'title' => 'Googleカレンダー連携の確認が必要です',
            'message' => $this->message, 'url' => route('settings.availability.index')];
    }
}
