<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Events\MeetingCanceled;
use App\Exceptions\Mentoring\MeetingAlreadyStartedException;
use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Models\Meeting;
use App\Models\User;
use App\UseCases\MeetingQuota\RefundQuotaAction;
use Illuminate\Support\Facades\DB;

final class CancelAction
{
    public function __construct(private readonly RefundQuotaAction $refundAction) {}

    public function __invoke(Meeting $meeting, User $actor): void
    {
        DB::transaction(function () use ($meeting, $actor) {
            // 予約と同じコーチ→面談の順序でロックし、枠の開放と再予約を直列化する。
            User::withTrashed()->whereKey($meeting->coach_id)->lockForUpdate()->firstOrFail();
            $locked = Meeting::query()->whereKey($meeting->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== MeetingStatus::Reserved) {
                throw MeetingStatusTransitionException::forCancel();
            }

            if ($locked->scheduled_at->lessThanOrEqualTo(now())) {
                throw new MeetingAlreadyStartedException;
            }

            $locked->update([
                'status' => MeetingStatus::Canceled->value,
                'canceled_by_user_id' => $actor->id,
                'canceled_at' => now(),
            ]);
            ($this->refundAction)($locked->student, $locked->id);
            DB::afterCommit(fn () => MeetingCanceled::dispatch($locked));
        });
    }
}
