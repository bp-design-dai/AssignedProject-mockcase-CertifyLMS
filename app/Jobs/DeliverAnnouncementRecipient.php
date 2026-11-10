<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AnnouncementDispatch;
use App\Models\AnnouncementRecipient;
use App\Models\User;
use App\Services\AnnouncementDeliveryService;
use App\Services\AnnouncementResultService;
use App\Support\NotificationQueueOptions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DeliverAnnouncementRecipient implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, NotificationQueueOptions, Queueable, SerializesModels;

    public function __construct(public string $recipientId)
    {
        $this->afterCommit();
        $this->onQueue('notifications');
    }

    public function handle(AnnouncementDeliveryService $delivery, AnnouncementResultService $results): void
    {
        $recipient = AnnouncementRecipient::findOrFail($this->recipientId);
        try {
            DB::transaction(function () use ($delivery) {
                $recipient = AnnouncementRecipient::whereKey($this->recipientId)->lockForUpdate()->firstOrFail();
                if ($recipient->status === 'sent') {
                    return;
                }
                $dispatch = AnnouncementDispatch::findOrFail($recipient->announcement_dispatch_id);
                $delivery->send(User::findOrFail($recipient->user_id), $dispatch->id, $dispatch->payload['title'], $dispatch->payload['body']);
                $recipient->update(['status' => 'sent', 'exception_class' => null]);
            });
        } catch (Throwable $exception) {
            AnnouncementRecipient::whereKey($recipient->id)->update(['exception_class' => $exception::class]);
            throw $exception;
        }
        $results->update($recipient->announcement_dispatch_id);
    }

    public function failed(Throwable $exception): void
    {
        $recipient = AnnouncementRecipient::find($this->recipientId);
        if (! $recipient) {
            return;
        }
        AnnouncementRecipient::whereKey($recipient->id)->where('status', '!=', 'sent')
            ->update(['status' => 'failed', 'exception_class' => $exception::class]);
        try {
            Log::warning('Announcement delivery failed', ['dispatch_id' => $recipient->announcement_dispatch_id, 'exception_class' => $exception::class]);
        } catch (Throwable) {
            // A logging failure must not interrupt the remaining recipients.
        }
        app(AnnouncementResultService::class)->update($recipient->announcement_dispatch_id);
    }
}
