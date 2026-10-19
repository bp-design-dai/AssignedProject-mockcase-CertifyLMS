<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Notifications\ActivityNotification;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

/** 同じ業務イベント・受信者の組をDB主キーで一意にし、再発火による二重送信を防ぐ。 */
final class ActivityNotificationService
{
    public function send(User $user, string $eventKey, ActivityNotification $notification): void
    {
        if ($notification->via($user) === []) {
            return;
        }

        try {
            $stored = $user->notifications()->firstOrCreate([
                'id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'certify-lms:'.$eventKey.':'.$user->id)->toString(),
            ], [
                'type' => ActivityNotification::class,
                'data' => $notification->toDatabase($user),
                'read_at' => null,
            ]);

            if ($stored->wasRecentlyCreated) {
                $user->notifyNow($notification, ['mail']);
            }
        } catch (\Throwable $exception) {
            // コミット済みの業務処理を失敗に戻さず、他の受信者への配信を続ける。
            // メール本文・宛先・例外メッセージはログに含めない。
            Log::warning('Activity notification delivery failed', ['notification_type' => $notification->type->value, 'exception_class' => $exception::class]);
        }
    }
}
