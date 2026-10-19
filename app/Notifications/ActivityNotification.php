<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\ActivityNotificationType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** 受講中の業務通知。同期配信のみとし、本文・秘密情報はメールへ転載しない。 */
final class ActivityNotification extends Notification
{
    public function __construct(
        public readonly ActivityNotificationType $type,
        public readonly string $resourceId,
        public readonly string $message,
        public readonly string $url,
    ) {}

    public function via(User $notifiable): array
    {
        return $this->isEligible($notifiable) ? ['database', 'mail'] : [];
    }

    public function shouldSend(User $notifiable, string $channel): bool
    {
        return $this->isEligible($notifiable);
    }

    public function toDatabase(User $notifiable): array
    {
        return [
            'notification_type' => $this->type->value,
            'resource_id' => $this->resourceId,
            'title' => $this->type->title(),
            'message' => $this->message,
            'url' => $this->url,
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->type->title())
            ->greeting('Certify LMSからのお知らせ')
            ->line($this->message)
            ->action('内容を確認する', $this->url);
    }

    private function isEligible(User $user): bool
    {
        return ! $user->trashed() && $user->status === UserStatus::InProgress
            && in_array($user->role, [UserRole::Student, UserRole::Coach], true);
    }
}
