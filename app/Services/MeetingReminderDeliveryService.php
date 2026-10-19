<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActivityNotificationType;
use App\Enums\MeetingReminderWindow;
use App\Enums\MeetingStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Meeting;
use App\Models\MeetingReminderDelivery;
use App\Models\User;
use App\Notifications\ActivityNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class MeetingReminderDeliveryService
{
    public function targets(MeetingReminderWindow $window, CarbonImmutable $now): Builder
    {
        return Meeting::query()->where('status', MeetingStatus::Reserved)
            ->whereBetween('scheduled_at', $window->range($now))
            ->whereHas('student', fn (Builder $users) => $users->where('role', UserRole::Student)->where('status', UserStatus::InProgress))
            ->whereHas('coach', fn (Builder $users) => $users->where('role', UserRole::Coach)->where('status', UserStatus::InProgress));
    }

    public function send(Meeting $meeting, string $userId, MeetingReminderWindow $window, string $channel, CarbonImmutable $now): bool
    {
        if (! in_array($channel, ['database', 'mail'], true)) {
            throw new \InvalidArgumentException('Unsupported reminder channel.');
        }
        // 未送信行は失敗しても残し、次の実行でその方法のみ再試行する。
        $delivery = MeetingReminderDelivery::firstOrCreate(['meeting_id' => $meeting->id, 'user_id' => $userId, 'window' => $window->value, 'channel' => $channel]);

        return DB::transaction(function () use ($delivery, $window, $channel, $now) {
            $delivery = MeetingReminderDelivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            if ($delivery->sent_at !== null) {
                return false;
            }

            // キャンセルや状態変更と競合した場合も、送信直前に両当事者を再確認する。
            $meeting = $this->targets($window, $now)->whereKey($delivery->meeting_id)->lockForUpdate()->first();
            if (! $meeting) {
                return false;
            }
            $users = User::whereIn('id', [$meeting->student_id, $meeting->coach_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $student = $users->get($meeting->student_id);
            $coach = $users->get($meeting->coach_id);
            if (! $student || ! $coach || $student->role !== UserRole::Student || $coach->role !== UserRole::Coach
                || $student->status !== UserStatus::InProgress || $coach->status !== UserStatus::InProgress) {
                return false;
            }
            $user = $users->get($delivery->user_id);
            if (! $user) {
                return false;
            }

            $url = route($user->role === UserRole::Coach ? 'coach.meetings.index' : 'meetings.index');
            $notification = new ActivityNotification(ActivityNotificationType::MeetingReminder, $meeting->id, $meeting->scheduled_at->format('Y/m/d H:i').'開始の面談の'.$window->label().'リマインダーです。', $url);
            $notification->id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'certify-lms:meeting-reminder:'.$meeting->id.':'.$window->value.':'.$user->id)->toString();

            if ($channel === 'database') {
                $user->notifications()->firstOrCreate(['id' => $notification->id], ['type' => ActivityNotification::class, 'data' => array_merge($notification->toDatabase($user), ['reminder_window' => $window->value]), 'read_at' => null]);
            } else {
                $user->notifyNow($notification, ['mail']);
            }
            // DB通知とその送信状態を同じTransactionで確定。メールは正常終了後のみ記録する。
            $delivery->update(['sent_at' => $now]);

            return true;
        });
    }
}
