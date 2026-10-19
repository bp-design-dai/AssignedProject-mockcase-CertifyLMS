<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\MeetingReminderWindow;
use App\Models\Meeting;
use App\Models\MeetingReminderDelivery;
use App\Models\User;
use App\Services\MeetingReminderDeliveryService;
use App\UseCases\Notification\SendMeetingRemindersAction;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MeetingReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2030-06-10 18:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @dataProvider eveBoundaries */
    public function test_eve_targets_only_the_next_calendar_day(string $scheduledAt, bool $expected): void
    {
        Meeting::factory()->create(['scheduled_at' => $scheduledAt]);
        $this->assertResult($this->runReminder(MeetingReminderWindow::Eve), $expected ? 1 : 0, $expected ? 4 : 0);
        $this->assertDatabaseCount('notifications', $expected ? 2 : 0);
        $this->assertCount($expected ? 2 : 0, $this->mails());
    }

    public static function eveBoundaries(): array
    {
        return [
            ['2030-06-10 23:59:59', false], ['2030-06-11 00:00:00', true],
            ['2030-06-11 23:59:59', true], ['2030-06-12 00:00:00', false],
        ];
    }

    /** @dataProvider hourBoundaries */
    public function test_hour_range_includes_55_and_65_minutes_but_not_outside_seconds(int $seconds, bool $expected): void
    {
        Meeting::factory()->create(['scheduled_at' => now()->addSeconds($seconds)]);
        $this->assertResult($this->runReminder(), $expected ? 1 : 0, $expected ? 4 : 0);
        $this->assertDatabaseCount('meeting_reminder_deliveries', $expected ? 4 : 0);
        $this->assertCount($expected ? 2 : 0, $this->mails());
    }

    public static function hourBoundaries(): array
    {
        return [[3299, false], [3300, true], [3301, true], [3600, true], [3899, true], [3900, true], [3901, false]];
    }

    /** @dataProvider excludedParties */
    public function test_neither_party_is_notified_if_either_is_ineligible(string $party, string $condition): void
    {
        $meeting = Meeting::factory()->create(['scheduled_at' => now()->addHour()]);
        $user = $meeting->$party;
        if ($condition === 'deleted') {
            $user->delete();
        } elseif ($condition === 'wrong_role') {
            $user->update(['role' => 'admin']);
        } else {
            $user->update(['status' => $condition]);
        }
        $this->assertResult($this->runReminder(), 0, 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('meeting_reminder_deliveries', 0);
        $this->assertCount(0, $this->mails());
    }

    public static function excludedParties(): array
    {
        $cases = [];
        foreach (['student', 'coach'] as $party) {
            foreach (['invited', 'graduated', 'withdrawn', 'deleted', 'wrong_role'] as $condition) {
                $cases[] = [$party, $condition];
            }
        }

        return $cases;
    }

    public function test_canceled_and_completed_meetings_are_excluded_from_both_windows(): void
    {
        foreach (['canceled', 'completed'] as $status) {
            Meeting::factory()->create(['scheduled_at' => now()->addHour(), 'status' => $status]);
            Meeting::factory()->create(['scheduled_at' => now()->addDay(), 'status' => $status]);
        }
        $this->assertResult($this->runReminder(), 0, 0);
        $this->assertResult($this->runReminder(MeetingReminderWindow::Eve), 0, 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertCount(0, $this->mails());
    }

    public function test_both_channels_are_recorded_per_party_and_overlapping_runs_preserve_read_state(): void
    {
        $meeting = Meeting::factory()->create(['scheduled_at' => now()->addHour(), 'topic' => '通知に含めない秘密の相談']);
        $outsider = User::factory()->student()->create();
        $this->assertResult($this->runReminder(), 1, 4);
        $notification = $meeting->student->notifications()->sole();
        $notification->markAsRead();
        $before = $notification->fresh()->getAttributes();
        $this->clock('2030-06-10 18:05:00');
        $this->assertResult($this->runReminder(), 1, 0);
        $this->assertEquals($before, $notification->fresh()->getAttributes());
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseCount('meeting_reminder_deliveries', 4);
        $this->assertSame(4, MeetingReminderDelivery::whereNotNull('sent_at')->count());
        $this->assertCount(2, $this->mails());
        $this->assertSame(0, $outsider->notifications()->count());
        foreach ([$meeting->student, $meeting->coach] as $user) {
            $data = $user->notifications()->sole()->data;
            $this->assertSame('meeting_reminder', $data['notification_type']);
            $this->assertSame('one_hour_before', $data['reminder_window']);
            $this->assertSame($meeting->id, $data['resource_id']);
            $this->assertSame(route($user->role->value === 'coach' ? 'coach.meetings.index' : 'meetings.index'), $data['url']);
            $this->assertStringNotContainsString('秘密', json_encode($data, JSON_UNESCAPED_UNICODE));
        }
        $this->assertStringNotContainsString('秘密', $this->mails()->first()->getOriginalMessage()->getHtmlBody());
    }

    public function test_mail_failure_retries_only_mail_and_does_not_recreate_database_notification(): void
    {
        Meeting::factory()->create(['scheduled_at' => now()->addHour()]);
        $manager = Notification::getFacadeRoot();
        Notification::shouldReceive('sendNow')->twice()->andThrow(new \RuntimeException('秘密の宛先'));
        Log::shouldReceive('warning')->twice()->with('Meeting reminder delivery failed', \Mockery::on(fn ($context) => $context === ['window' => 'one_hour_before', 'channel' => 'mail', 'exception_class' => \RuntimeException::class]));
        $this->assertResult($this->runReminder(), 1, 2, 2);
        $ids = DatabaseNotification::pluck('id')->all();
        $this->assertSame(2, MeetingReminderDelivery::where('channel', 'database')->whereNotNull('sent_at')->count());
        $this->assertSame(2, MeetingReminderDelivery::where('channel', 'mail')->whereNull('sent_at')->count());
        Notification::swap($manager);
        $this->assertResult($this->runReminder(), 1, 2);
        $this->assertEqualsCanonicalizing($ids, DatabaseNotification::pluck('id')->all());
        $this->assertCount(2, $this->mails());
        $this->assertSame(4, MeetingReminderDelivery::whereNotNull('sent_at')->count());
    }

    public function test_database_failure_does_not_block_mail_or_other_recipient_and_retries_only_database(): void
    {
        $meeting = Meeting::factory()->create(['scheduled_at' => now()->addHour()]);
        DatabaseNotification::creating(function ($notification) use ($meeting) {
            if ($notification->notifiable_id === $meeting->student_id) {
                throw new \RuntimeException('DB通知の検証用障害');
            }
        });
        try {
            $this->assertResult($this->runReminder(), 1, 3, 1);
        } finally {
            DatabaseNotification::flushEventListeners();
        }
        $this->assertCount(2, $this->mails());
        $this->assertDatabaseCount('notifications', 1);
        $this->assertResult($this->runReminder(), 1, 1);
        $this->assertCount(2, $this->mails());
        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame(4, MeetingReminderDelivery::whereNotNull('sent_at')->count());
    }

    public function test_failure_does_not_stop_later_meetings_even_when_logging_fails(): void
    {
        Meeting::factory()->count(2)->create(['scheduled_at' => now()->addHour()]);
        Notification::shouldReceive('sendNow')->once()->andThrow(new \RuntimeException);
        Notification::shouldReceive('sendNow')->times(3)->andReturnNull();
        Log::shouldReceive('warning')->once()->andThrow(new \RuntimeException);
        $this->assertResult($this->runReminder(), 2, 7, 1);
        $this->assertDatabaseCount('notifications', 4);
        $this->assertSame(7, MeetingReminderDelivery::whereNotNull('sent_at')->count());
    }

    public function test_failed_channel_is_not_sent_after_time_window_has_passed(): void
    {
        Meeting::factory()->create(['scheduled_at' => now()->addHour()]);
        Notification::shouldReceive('sendNow')->twice()->andThrow(new \RuntimeException);
        $this->assertResult($this->runReminder(), 1, 2, 2);
        $this->clock('2030-06-10 18:05:01');
        $this->assertResult($this->runReminder(), 0, 0);
        $this->assertSame(2, MeetingReminderDelivery::whereNull('sent_at')->count());
    }

    public function test_pending_channel_rechecks_both_user_states_and_meeting_state(): void
    {
        $meeting = Meeting::factory()->create(['scheduled_at' => now()->addHour()]);
        $service = app(MeetingReminderDeliveryService::class);
        $meeting->coach->update(['status' => 'graduated']);
        $this->assertFalse($service->send($meeting, $meeting->student_id, MeetingReminderWindow::OneHourBefore, 'database', CarbonImmutable::now()));
        $meeting->coach->update(['status' => 'in_progress']);
        $meeting->update(['status' => 'canceled']);
        $this->assertFalse($service->send($meeting, $meeting->student_id, MeetingReminderWindow::OneHourBefore, 'mail', CarbonImmutable::now()));
        $this->assertDatabaseCount('notifications', 0);
        $this->assertCount(0, $this->mails());
    }

    public function test_windows_have_independent_notification_ids_and_channel_states(): void
    {
        Meeting::factory()->create(['scheduled_at' => '2030-06-11 10:00:00']);
        $this->assertResult($this->runReminder(MeetingReminderWindow::Eve), 1, 4);
        $this->assertResult($this->runReminder(MeetingReminderWindow::Eve), 1, 0);
        $this->clock('2030-06-11 09:00:00');
        $this->assertResult($this->runReminder(), 1, 4);
        $this->assertResult($this->runReminder(), 1, 0);
        $this->assertDatabaseCount('notifications', 4);
        $this->assertDatabaseCount('meeting_reminder_deliveries', 8);
        $this->assertCount(4, $this->mails());
    }

    public function test_reminder_click_rechecks_ownership_and_links_to_role_specific_list(): void
    {
        $meeting = Meeting::factory()->create(['scheduled_at' => now()->addHour()]);
        $this->runReminder();
        foreach ([$meeting->student, $meeting->coach] as $user) {
            $notification = $user->notifications()->sole();
            $notification->update(['data' => array_merge($notification->data, ['url' => 'https://evil.example'])]);
            $url = route($user->role->value === 'coach' ? 'coach.meetings.index' : 'meetings.index');
            $this->actingAs($user)->get(route('notifications.index'))->assertOk()->assertSee('面談のリマインダー');
            $this->post(route('notifications.markAsRead', $notification))->assertRedirect($url);
            $this->assertNotNull($notification->fresh()->read_at);
            $this->get($url)->assertOk();
        }
        $notification = $meeting->student->notifications()->sole();
        $this->actingAs(User::factory()->student()->create())->post(route('notifications.markAsRead', $notification))->assertForbidden();
    }

    public function test_missing_or_reassigned_meeting_does_not_mark_notification_as_read(): void
    {
        $meeting = Meeting::factory()->create(['scheduled_at' => now()->addHour()]);
        $student = $meeting->student;
        $this->runReminder();
        $notification = $student->notifications()->sole();
        $meeting->update(['student_id' => User::factory()->student()->create()->id]);
        $this->actingAs($student)->post(route('notifications.markAsRead', $notification))->assertForbidden();
        $this->assertNull($notification->fresh()->read_at);
        $meeting->delete();
        $this->post(route('notifications.markAsRead', $notification))->assertNotFound();
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_invalid_channel_and_non_participant_cannot_trigger_delivery(): void
    {
        $meeting = Meeting::factory()->create(['scheduled_at' => now()->addHour()]);
        $outsider = User::factory()->student()->create();
        $service = app(MeetingReminderDeliveryService::class);
        $this->assertFalse($service->send($meeting, $outsider->id, MeetingReminderWindow::OneHourBefore, 'database', CarbonImmutable::now()));
        $this->assertDatabaseCount('notifications', 0);
        $this->expectException(\InvalidArgumentException::class);
        $service->send($meeting, $meeting->student_id, MeetingReminderWindow::OneHourBefore, 'sms', CarbonImmutable::now());
    }

    public function test_more_than_one_chunk_is_processed_without_skipping_meetings(): void
    {
        Notification::fake();
        $base = Meeting::factory()->create(['scheduled_at' => '2030-06-11 10:00:00']);
        for ($i = 1; $i <= 100; $i++) {
            Meeting::factory()->forCoach($base->coach)->forEnrollment($base->enrollment)
                ->create(['scheduled_at' => Carbon::parse('2030-06-11 10:00:00')->addSeconds($i)]);
        }
        $this->assertResult($this->runReminder(MeetingReminderWindow::Eve), 101, 404);
        $this->assertDatabaseCount('notifications', 202);
        $this->assertSame(404, MeetingReminderDelivery::whereNotNull('sent_at')->count());
    }

    public function test_command_reports_counts_and_returns_failure_if_a_channel_fails(): void
    {
        Meeting::factory()->create(['scheduled_at' => now()->addHour()]);
        Notification::shouldReceive('sendNow')->twice()->andThrow(new \RuntimeException);
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'one_hour_before'])
            ->expectsOutput('対象面談1件、送信2件、失敗2件（通知方法ごと）')->assertExitCode(1);
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'invalid'])->assertExitCode(2);
        $this->artisan('notifications:send-meeting-reminders')->assertExitCode(2);
    }

    private function clock(string $time): void
    {
        Carbon::setTestNow(Carbon::parse($time, 'Asia/Tokyo'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse($time, 'Asia/Tokyo'));
    }

    private function runReminder(MeetingReminderWindow $window = MeetingReminderWindow::OneHourBefore): array
    {
        return app(SendMeetingRemindersAction::class)($window);
    }

    private function assertResult(array $actual, int $meetings, int $sent, int $failed = 0): void
    {
        $this->assertSame(compact('meetings', 'sent', 'failed'), $actual);
    }

    private function mails()
    {
        return Mail::mailer()->getSymfonyTransport()->messages();
    }
}
