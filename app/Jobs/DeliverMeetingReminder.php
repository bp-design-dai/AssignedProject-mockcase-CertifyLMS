<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MeetingReminderDelivery;
use App\Services\MeetingReminderDeliveryService;
use App\Support\NotificationQueueOptions;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class DeliverMeetingReminder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, NotificationQueueOptions, Queueable, SerializesModels;

    public function __construct(public string $deliveryId, public string $selectedAt)
    {
        $this->afterCommit();
        $this->onQueue('notifications');
    }

    public function handle(MeetingReminderDeliveryService $service): void
    {
        $delivery = MeetingReminderDelivery::find($this->deliveryId);
        if (! $delivery || $delivery->sent_at !== null || $delivery->skipped_at !== null) {
            return;
        }
        $meeting = $delivery->meeting;
        if (! $meeting || ! $service->send($meeting, $delivery->user_id, $delivery->window, $delivery->channel, CarbonImmutable::parse($this->selectedAt))) {
            MeetingReminderDelivery::whereKey($delivery->id)->whereNull('sent_at')->whereNull('skipped_at')
                ->update(['skipped_at' => now(), 'failed_at' => null, 'exception_class' => null]);
        } else {
            $delivery->update(['failed_at' => null, 'exception_class' => null]);
        }
    }

    public function failed(Throwable $exception): void
    {
        MeetingReminderDelivery::whereKey($this->deliveryId)->whereNull('sent_at')->whereNull('skipped_at')
            ->update(['failed_at' => now(), 'exception_class' => $exception::class]);
    }
}
