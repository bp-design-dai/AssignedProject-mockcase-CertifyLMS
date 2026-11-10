<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AnnouncementDispatch;
use App\Models\AnnouncementRecipient;
use App\Services\AnnouncementRecipientService;
use App\Services\AnnouncementResultService;
use App\Support\NotificationQueueOptions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class PrepareAnnouncementDelivery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, NotificationQueueOptions, Queueable, SerializesModels;

    public function __construct(public string $dispatchId)
    {
        $this->afterCommit();
        $this->onQueue('notifications');
    }

    public function handle(AnnouncementRecipientService $recipients, AnnouncementResultService $results): void
    {
        $dispatch = AnnouncementDispatch::findOrFail($this->dispatchId);
        if ($dispatch->prepared_at !== null) {
            $results->update($dispatch->id);

            return;
        }
        $dispatch->update(['status' => 'processing', 'exception_class' => null]);
        foreach ($recipients->query($dispatch->payload)->lazyById(100) as $user) {
            $recipient = AnnouncementRecipient::firstOrCreate(['announcement_dispatch_id' => $dispatch->id, 'user_id' => $user->id]);
            if ($recipient->status !== 'pending') {
                continue;
            }
            try {
                DeliverAnnouncementRecipient::dispatch($recipient->id);
            } catch (Throwable $exception) {
                // A synchronous execution can already have recorded a terminal recipient failure.
                // Queue-publication failures leave it pending and retry this preparation job.
                if ($recipient->fresh()->status !== 'failed') {
                    throw $exception;
                }
            }
        }
        $dispatch->update(['prepared_at' => now()]);
        $results->update($dispatch->id);
    }

    public function failed(Throwable $exception): void
    {
        AnnouncementDispatch::whereKey($this->dispatchId)->update(['status' => 'failed', 'exception_class' => $exception::class]);
    }
}
