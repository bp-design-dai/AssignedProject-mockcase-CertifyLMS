<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Announcement;
use App\Models\AnnouncementDispatch;
use App\Models\AnnouncementRecipient;
use Illuminate\Support\Facades\DB;

final class AnnouncementResultService
{
    public function update(string $dispatchId): void
    {
        DB::transaction(function () use ($dispatchId) {
            $dispatch = AnnouncementDispatch::whereKey($dispatchId)->lockForUpdate()->firstOrFail();
            $counts = AnnouncementRecipient::where('announcement_dispatch_id', $dispatchId)
                ->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
            $succeeded = (int) ($counts['sent'] ?? 0);
            $failed = (int) ($counts['failed'] ?? 0);
            $dispatch->fill(['target_count' => $counts->sum(), 'succeeded_count' => $succeeded, 'failed_count' => $failed]);
            if ($dispatch->prepared_at === null || ($counts['pending'] ?? 0) > 0) {
                $dispatch->save();

                return;
            }
            $dispatch->fill(['completed_at' => now(), 'status' => $succeeded > 0 ? 'completed' : 'failed'])->save();
            if ($succeeded > 0) {
                $payload = $dispatch->payload;
                Announcement::updateOrCreate(['id' => $dispatchId], $payload + [
                    'created_by_user_id' => $dispatch->created_by_user_id,
                    'dispatched_count' => $succeeded, 'failed_count' => $failed,
                    'dispatched_at' => Announcement::find($dispatchId)?->dispatched_at ?? now(),
                ]);
            }
        });
    }
}
