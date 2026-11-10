<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\DeliverActivityNotification;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\ActivityNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Throwable;

final class ActivityNotificationService
{
    public function send(User $user, string $eventKey, ActivityNotification $notification): void
    {
        if ($notification->via($user) === []) {
            return;
        }
        DB::afterCommit(function () use ($user, $eventKey, $notification) {
            try {
                DeliverActivityNotification::dispatch($user->id, $eventKey, $notification);
            } catch (Throwable $exception) {
                Log::warning('Activity notification delivery failed', ['notification_type' => $notification->type->value, 'exception_class' => $exception::class]);
            }
        });
    }

    public function deliver(string $userId, string $eventKey, ActivityNotification $notification): void
    {
        $user = User::find($userId);
        if (! $user || $notification->via($user) === []) {
            NotificationDelivery::where('delivery_key', $eventKey)->where('user_id', $userId)->whereNull('sent_at')->update(['skipped_at' => now()]);

            return;
        }
        $notification->id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'certify-lms:'.$eventKey.':'.$userId)->toString();
        // Legacy notifications predate channel tracking and must retain their read state and delivery semantics.
        if ($user->notifications()->whereKey($notification->id)->exists() && ! NotificationDelivery::where('delivery_key', $eventKey)->where('user_id', $userId)->exists()) {
            return;
        }
        foreach (['database', 'mail'] as $channel) {
            $delivery = NotificationDelivery::firstOrCreate(['delivery_key' => $eventKey, 'user_id' => $userId, 'channel' => $channel]);
            try {
                DB::transaction(function () use ($user, $notification, $delivery, $channel) {
                    $delivery = NotificationDelivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
                    if ($delivery->sent_at !== null) {
                        return;
                    }
                    if ($channel === 'database') {
                        $user->notifications()->firstOrCreate(['id' => $notification->id], ['type' => ActivityNotification::class, 'data' => $notification->toDatabase($user), 'read_at' => null]);
                    } else {
                        $user->notifyNow($notification, ['mail']);
                    }
                    $delivery->update(['sent_at' => now(), 'failed_at' => null, 'exception_class' => null]);
                });
            } catch (Throwable $exception) {
                $delivery->update(['exception_class' => $exception::class]);
                throw $exception;
            }
        }
    }
}
