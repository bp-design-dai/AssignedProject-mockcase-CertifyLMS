<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ActivityNotificationType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ChatRoom;
use App\Models\Meeting;
use App\Models\QaThread;
use App\Models\User;
use App\Notifications\ActivityNotification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Gate;
use Ramsey\Uuid\Uuid;

/** 既存業務データを参照するデモ通知のみを追加する。メール送信や既読状態の上書きは行わない。 */
final class NotificationSeeder extends Seeder
{
    public function run(): void
    {
        foreach (User::query()->whereIn('email', ['student@certify-lms.test', 'coach@certify-lms.test', 'coach2@certify-lms.test'])
            ->where('status', UserStatus::InProgress)
            ->whereIn('role', [UserRole::Student, UserRole::Coach])->get() as $user) {
            $targets = [];
            if ($room = ChatRoom::query()->forUser($user)->first()) {
                $targets[] = new ActivityNotification(ActivityNotificationType::ChatMessageReceived, $room->id, '通知一覧の動作確認用デモ通知です。', route('chat.show', $room));
            }
            $thread = QaThread::query()->visibleTo($user)->first();
            if ($thread !== null && Gate::forUser($user)->allows('view', $thread)) {
                $targets[] = new ActivityNotification(ActivityNotificationType::QaReplyReceived, $thread->id, '質問への回答を確認するデモ通知です。', route('qa-board.show', $thread));
            }
            $meeting = Meeting::query()->where($user->role === UserRole::Coach ? 'coach_id' : 'student_id', $user->id)->first();
            if ($meeting !== null) {
                $targets[] = new ActivityNotification(ActivityNotificationType::MeetingReserved, $meeting->id, '面談一覧への遷移を確認するデモ通知です。', route($user->role === UserRole::Coach ? 'coach.meetings.index' : 'meetings.index'));
            }
            if ($targets === []) {
                continue;
            }

            for ($index = 0; $index < 25; $index++) {
                $notification = $targets[$index % count($targets)];
                $date = now()->subMinutes($index + 1);
                $user->notifications()->firstOrCreate([
                    'id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'certify-lms:demo-notification:'.$user->id.':'.$index)->toString(),
                ], [
                    'type' => ActivityNotification::class,
                    'data' => $notification->toDatabase($user),
                    'read_at' => $index % 3 === 0 ? $date : null,
                    'created_at' => $date,
                    'updated_at' => $date,
                ]);
            }
        }
    }
}
