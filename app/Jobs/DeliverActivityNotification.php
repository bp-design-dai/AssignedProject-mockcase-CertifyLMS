<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NotificationDelivery;
use App\Notifications\ActivityNotification;
use App\Services\ActivityNotificationService;
use App\Support\NotificationQueueOptions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class DeliverActivityNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, NotificationQueueOptions, Queueable, SerializesModels;

    public function __construct(public string $userId, public string $eventKey, public ActivityNotification $notification)
    {
        $this->afterCommit();
        $this->onQueue('notifications');
    }

    public function handle(ActivityNotificationService $service): void
    {
        $service->deliver($this->userId, $this->eventKey, $this->notification);
    }

    public function failed(Throwable $exception): void
    {
        NotificationDelivery::where('delivery_key', $this->eventKey)->where('user_id', $this->userId)->whereNull('sent_at')
            ->update(['failed_at' => now(), 'exception_class' => $exception::class]);
    }
}
