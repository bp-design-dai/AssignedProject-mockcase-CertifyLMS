<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Notifications\AnnouncementNotification;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class AnnouncementDeliveryService
{
    public function send(User $user, string $announcementId, string $title, string $body): void
    {
        $user->refresh();
        $notification = new AnnouncementNotification($announcementId, $title, $body);
        if ($notification->via($user) === []) {
            throw new \RuntimeException('Recipient is no longer eligible.');
        }
        $notification->id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'certify-lms:announcement:'.$announcementId.':'.$user->id)->toString();

        // 受信者単位で両チャネルを処理。メール失敗時はその受信者のDB通知だけを戻す。
        DB::transaction(function () use ($user, $notification) {
            $stored = $user->notifications()->firstOrCreate(['id' => $notification->id], ['type' => AnnouncementNotification::class, 'data' => $notification->toDatabase($user), 'read_at' => null]);
            if ($stored->wasRecentlyCreated) {
                $user->notifyNow($notification, ['mail']);
            }
        });
    }
}
