<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

final class AnnouncementNotification extends Notification
{
    public function __construct(public readonly string $announcementId, public readonly string $title, public readonly string $body) {}

    public function via(User $notifiable): array
    {
        return ! $notifiable->trashed() && $notifiable->role === UserRole::Student && $notifiable->status === UserStatus::InProgress ? ['database', 'mail'] : [];
    }

    public function toDatabase(User $notifiable): array
    {
        return ['notification_type' => 'admin_announcement', 'resource_id' => $this->announcementId, 'title' => $this->title, 'body' => $this->body, 'message' => mb_substr($this->body, 0, 100), 'url' => route('notifications.show', $this->id)];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->title)->greeting('Certify LMSからのお知らせ')->line(new HtmlString(nl2br(e($this->body))))->action('お知らせを読む', route('notifications.show', $this->id));
    }
}
