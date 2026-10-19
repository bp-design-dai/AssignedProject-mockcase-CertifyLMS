<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\ActivityNotificationType;
use App\Enums\UserRole;
use App\Events\ChatMessageSent;
use App\Events\MeetingCanceled;
use App\Events\MeetingReserved;
use App\Events\QaReplyCreated;
use App\Models\Certification;
use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use App\UseCases\Chat\StoreMessageAction;
use App\UseCases\QaReply\StoreAction;
use Carbon\Carbon;
use Database\Seeders\NotificationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ActivityNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_notifies_only_other_active_members_and_deduplicates_the_event(): void
    {
        $sender = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $otherStudent = User::factory()->student()->create();
        $excluded = collect([
            User::factory()->student()->invited()->create(),
            User::factory()->student()->graduated()->create(),
            User::factory()->coach()->withdrawn()->create(),
            User::factory()->admin()->create(),
        ]);
        $outsider = User::factory()->coach()->create();
        $room = ChatRoom::factory()->for(Enrollment::factory()->for($sender))->create();
        foreach ($excluded->merge([$sender, $coach, $otherStudent]) as $member) {
            ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $member->id]);
        }

        $message = app(StoreMessageAction::class)($sender, $room, ['body' => 'メールに転載しない秘密の本文']);
        ChatMessageSent::dispatch($message);

        foreach ([$coach, $otherStudent] as $recipient) {
            $notification = $recipient->notifications()->sole();
            $this->assertSame('chat_message_received', $notification->data['notification_type']);
            $this->assertSame(route('chat.show', $room), $notification->data['url']);
            $this->assertStringNotContainsString('秘密の本文', json_encode($notification->data, JSON_UNESCAPED_UNICODE));
        }
        foreach ($excluded->merge([$sender, $outsider]) as $recipient) {
            $this->assertSame(0, $recipient->notifications()->count());
        }
        $this->assertDatabaseCount('notifications', 2);
        $this->assertCount(2, $this->mails());
        $this->assertEqualsCanonicalizing([$coach->email, $otherStudent->email], $this->mailRecipients());
        $this->assertStringNotContainsString('秘密の本文', $this->mails()->first()->getOriginalMessage()->getHtmlBody());
        $this->assertNotNull($sender->chatMembers()->first()->last_read_at);
    }

    public function test_reply_creation_notifies_question_owner_but_not_self_or_on_edit(): void
    {
        $owner = User::factory()->student()->create();
        $responder = User::factory()->student()->create();
        $thread = QaThread::factory()->for($owner, 'user')->create();
        $reply = app(StoreAction::class)($thread, $responder, ['body' => '新しい回答']);
        QaReplyCreated::dispatch($reply);
        $reply->update(['body' => '編集済み']);
        app(StoreAction::class)($thread, $owner, ['body' => '自分の補足']);

        $notification = $owner->notifications()->sole();
        $this->assertSame('qa_reply_received', $notification->data['notification_type']);
        $this->assertSame(route('qa-board.show', $thread), $notification->data['url']);
        $this->assertSame(0, $responder->notifications()->count());
        $this->assertCount(1, $this->mails());
        $this->assertSame([$owner->email], $this->mailRecipients());
    }

    /** @dataProvider excludedStatuses */
    public function test_qa_does_not_notify_inactive_question_owner(string $status): void
    {
        $owner = User::factory()->student()->create(['status' => $status]);
        $reply = QaReply::factory()->for(QaThread::factory()->for($owner, 'user'), 'thread')->create();
        QaReplyCreated::dispatch($reply);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertCount(0, $this->mails());
    }

    public static function excludedStatuses(): array
    {
        return [['invited'], ['graduated'], ['withdrawn']];
    }

    /** @dataProvider meetingEventTypes */
    public function test_meeting_events_notify_only_active_parties_and_link_to_role_specific_list(string $type): void
    {
        $meeting = Meeting::factory()->create();
        User::factory()->coach()->create();
        User::factory()->admin()->create();
        $event = $type === 'meeting_reserved' ? new MeetingReserved($meeting) : new MeetingCanceled($meeting);
        event($event);
        event($event);

        foreach ([$meeting->student, $meeting->coach] as $user) {
            $notification = $user->notifications()->sole();
            $this->assertSame($type, $notification->data['notification_type']);
            $this->assertSame(route($user->role === UserRole::Coach ? 'coach.meetings.index' : 'meetings.index'), $notification->data['url']);
        }
        $this->assertDatabaseCount('notifications', 2);
        $this->assertCount(2, $this->mails());
        $this->assertEqualsCanonicalizing([$meeting->student->email, $meeting->coach->email], $this->mailRecipients());
    }

    public static function meetingEventTypes(): array
    {
        return [['meeting_reserved'], ['meeting_canceled']];
    }

    /** @dataProvider excludedStatuses */
    public function test_meeting_excludes_inactive_party_without_suppressing_other_active_party(string $status): void
    {
        $meeting = Meeting::factory()->create();
        $meeting->coach->update(['status' => $status]);
        MeetingReserved::dispatch($meeting);
        MeetingCanceled::dispatch($meeting);
        $this->assertSame(0, $meeting->coach->notifications()->count());
        $this->assertSame(2, $meeting->student->notifications()->count());
        $this->assertSame([$meeting->student->email, $meeting->student->email], $this->mailRecipients());
    }

    public function test_no_completion_approved_notification_type_or_listener_is_registered(): void
    {
        $this->assertNull(ActivityNotificationType::tryFrom('completion_approved'));
        event('completion_approved', [User::factory()->student()->create()]);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertCount(0, $this->mails());
    }

    public function test_mail_failure_preserves_business_result_database_notifications_and_other_recipients(): void
    {
        $meeting = Meeting::factory()->create();
        Notification::shouldReceive('sendNow')->twice()->andThrow(new \RuntimeException('公開してはいけない宛先情報'));
        Log::shouldReceive('warning')->twice()->with('Activity notification delivery failed', \Mockery::on(fn (array $context) => $context === ['notification_type' => 'meeting_reserved', 'exception_class' => \RuntimeException::class]));
        MeetingReserved::dispatch($meeting);
        $this->assertDatabaseHas('meetings', ['id' => $meeting->id]);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame(1, $meeting->student->notifications()->count());
        $this->assertSame(1, $meeting->coach->notifications()->count());
    }

    public function test_soft_deleted_chat_member_is_not_notified(): void
    {
        $room = ChatRoom::factory()->create();
        $deleted = User::factory()->coach()->create();
        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $deleted->id]);
        $deleted->delete();
        ChatMessageSent::dispatch(ChatMessage::factory()->create(['chat_room_id' => $room->id]));
        $this->assertDatabaseCount('notifications', 0);
        $this->assertCount(0, $this->mails());
    }

    public function test_qa_notification_rechecks_question_visibility(): void
    {
        $reply = QaReply::factory()->create();
        $reply->thread->certification->update(['status' => 'archived']);
        QaReplyCreated::dispatch($reply);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertCount(0, $this->mails());
    }

    public function test_demo_seeder_is_idempotent_preserves_read_state_and_does_not_send_mail(): void
    {
        $student = User::factory()->student()->create(['email' => 'student@certify-lms.test']);
        $coach = User::factory()->coach()->create(['email' => 'coach@certify-lms.test']);
        $inactiveCoach = User::factory()->coach()->graduated()->create(['email' => 'coach2@certify-lms.test']);
        $room = ChatRoom::factory()->create();
        foreach ([$student, $coach, $inactiveCoach] as $user) {
            ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $user->id]);
        }
        $this->seed(NotificationSeeder::class);
        $this->assertSame(25, $student->notifications()->count());
        $this->assertSame(25, $coach->notifications()->count());
        $this->assertSame(0, $inactiveCoach->notifications()->count());
        $this->assertGreaterThan(0, $student->unreadNotifications()->count());
        $this->assertGreaterThan(0, $student->readNotifications()->count());
        $read = $student->unreadNotifications()->first();
        $read->markAsRead();
        $original = $read->fresh()->getAttributes();
        $this->travel(1)->day();
        $this->seed(NotificationSeeder::class);
        $this->assertDatabaseCount('notifications', 50);
        $this->assertEquals($original, $read->fresh()->getAttributes());
        $this->assertCount(0, $this->mails());
    }

    public function test_rolled_back_reply_does_not_send_or_save_notification(): void
    {
        $thread = QaThread::factory()->create();
        $responder = User::factory()->student()->create();
        DB::beginTransaction();
        app(StoreAction::class)($thread, $responder, ['body' => '取り消す回答']);
        DB::rollBack();
        $this->assertDatabaseCount('qa_replies', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertCount(0, $this->mails());
    }

    public function test_booking_and_cancel_http_endpoints_emit_once_only_after_success(): void
    {
        $student = User::factory()->student()->create(['max_meetings' => 3]);
        $coach = User::factory()->coach()->create();
        $certification = Certification::factory()->published()->create();
        $certification->coaches()->attach($coach, ['assigned_by_user_id' => User::factory()->admin()->create()->id, 'assigned_at' => now()]);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();
        $date = now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0);
        $this->actingAs($student)->post(route('meetings.store', $enrollment), ['scheduled_at' => $date->format('Y-m-d\TH:i:s'), 'topic' => '通知動作確認'])->assertSessionHasNoErrors()->assertRedirect();
        $meeting = Meeting::query()->sole();
        $this->assertDatabaseCount('notifications', 2);
        $this->post(route('meetings.cancel', $meeting))->assertRedirect();
        $this->assertDatabaseCount('notifications', 4);
        $this->post(route('meetings.cancel', $meeting))->assertForbidden();
        $this->assertDatabaseCount('notifications', 4);
        $this->assertCount(4, $this->mails());
    }

    private function mails()
    {
        return Mail::mailer()->getSymfonyTransport()->messages();
    }

    private function mailRecipients(): array
    {
        return $this->mails()->map(fn ($mail) => $mail->getOriginalMessage()->getTo()[0]->getAddress())->all();
    }
}
