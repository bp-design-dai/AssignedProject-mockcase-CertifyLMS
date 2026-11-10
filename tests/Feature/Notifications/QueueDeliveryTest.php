<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\ActivityNotificationType;
use App\Enums\MeetingReminderWindow;
use App\Enums\UserRole;
use App\Jobs\DeliverAnnouncementRecipient;
use App\Jobs\PrepareAnnouncementDelivery;
use App\Models\Announcement;
use App\Models\AnnouncementDispatch;
use App\Models\AnnouncementRecipient;
use App\Models\Meeting;
use App\Models\MeetingReminderDelivery;
use App\Models\NotificationDelivery;
use App\Models\Plan;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Services\ActivityNotificationService;
use App\UseCases\Announcement\StoreAction;
use App\UseCases\Auth\IssueInvitationAction;
use App\UseCases\Notification\SendMeetingRemindersAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class QueueDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
    }

    public function test_database_job_waits_for_commit_and_worker_then_delivers_both_channels_once(): void
    {
        $user = User::factory()->student()->create();
        DB::beginTransaction();
        $this->send($user);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('notifications', 0);
        DB::commit();
        $this->assertDatabaseCount('jobs', 1);
        $this->assertCount(0, $this->mails());
        $this->work();
        $this->assertDatabaseCount('jobs', 0);
        $notification = $user->notifications()->sole();
        $notification->markAsRead();
        $readAt = $notification->fresh()->read_at;
        $this->send($user);
        $this->work();
        $this->assertDatabaseCount('notifications', 1);
        $this->assertCount(1, $this->mails());
        $this->assertEquals($readAt, $notification->fresh()->read_at);
        $this->assertSame(2, NotificationDelivery::whereNotNull('sent_at')->count());
    }

    public function test_rollback_discards_activity_and_invitation_jobs(): void
    {
        $user = User::factory()->student()->create();
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->published()->create();
        DB::beginTransaction();
        $this->send($user);
        app(IssueInvitationAction::class)('rollback@example.test', UserRole::Student, $plan, $admin);
        $this->assertDatabaseCount('jobs', 0);
        DB::rollBack();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertCount(0, $this->mails());
    }

    public function test_invitation_is_queued_and_actual_worker_sends_mail(): void
    {
        app(IssueInvitationAction::class)('invite@example.test', UserRole::Student, Plan::factory()->published()->create(), User::factory()->admin()->create());
        $this->assertDatabaseCount('jobs', 1);
        $this->assertCount(0, $this->mails());
        $this->work();
        $this->assertCount(1, $this->mails());
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_password_reset_mail_uses_database_queue(): void
    {
        User::factory()->student()->create()->sendPasswordResetNotification('synthetic-test-token');
        $this->assertDatabaseCount('jobs', 1);
        $this->assertCount(0, $this->mails());
        $this->work();
        $this->assertCount(1, $this->mails());
    }

    public function test_transient_mail_failure_retries_without_duplicate_database_notification(): void
    {
        $user = User::factory()->student()->create();
        $manager = Notification::getFacadeRoot();
        Notification::shouldReceive('sendNow')->once()->andThrow(new \RuntimeException('Synthetic failure'));
        $this->send($user);
        $this->work();
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(1, (int) DB::table('jobs')->sole()->attempts);
        $this->assertEqualsWithDelta(60, DB::table('jobs')->sole()->available_at - now()->timestamp, 2);
        Notification::swap($manager);
        $this->makeAvailable();
        $this->work();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertCount(1, $this->mails());
    }

    public function test_four_attempts_backoff_failed_jobs_and_native_retry_recover_once(): void
    {
        $user = User::factory()->student()->create();
        $manager = Notification::getFacadeRoot();
        Notification::shouldReceive('sendNow')->times(4)->andThrow(new \RuntimeException('Synthetic failure'));
        $this->send($user);
        foreach ([60, 300, 900] as $backoff) {
            $this->work();
            $this->assertEqualsWithDelta($backoff, DB::table('jobs')->sole()->available_at - now()->timestamp, 2);
            $this->makeAvailable();
        }
        $this->work();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertSame(1, NotificationDelivery::whereNotNull('failed_at')->count());
        Notification::swap($manager);
        Artisan::call('queue:retry', ['id' => [DB::table('failed_jobs')->sole()->uuid]]);
        $this->work();
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertCount(1, $this->mails());
        $this->assertSame(0, NotificationDelivery::whereNotNull('failed_at')->count());
    }

    public function test_activity_worker_rechecks_recipient_status(): void
    {
        $user = User::factory()->student()->create();
        $this->send($user);
        $user->update(['status' => 'withdrawn']);
        $this->work();
        $this->assertDatabaseCount('notifications', 0);
        $this->assertCount(0, $this->mails());
    }

    public function test_announcement_http_only_accepts_and_fanout_runs_in_worker(): void
    {
        User::factory()->student()->count(3)->create();
        $this->actingAs(User::factory()->admin()->create())->post(route('admin.announcements.store'), $this->payload())
            ->assertRedirect(route('admin.announcements.index'))->assertSessionHas('success', 'お知らせの配信を受け付けました。');
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('announcement_recipients', 0);
        $this->assertDatabaseCount('announcements', 0);
        $this->assertCount(0, $this->mails());
        $this->work();
        $this->assertDatabaseCount('jobs', 3);
        $this->assertDatabaseCount('announcements', 0);
        $this->drain();
        $this->assertSame(3, Announcement::sole()->dispatched_count);
        $this->assertCount(3, $this->mails());
    }

    public function test_announcement_beyond_one_chunk_and_duplicate_jobs_do_not_double_count(): void
    {
        User::factory()->student()->count(101)->create();
        $dispatch = $this->announcement();
        $this->assertDatabaseCount('jobs', 1);
        $this->work();
        $this->assertDatabaseCount('announcement_recipients', 101);
        $this->assertDatabaseCount('jobs', 101);
        PrepareAnnouncementDelivery::dispatch($dispatch->id);
        DeliverAnnouncementRecipient::dispatch(AnnouncementRecipient::first()->id);
        $this->drain();
        $this->assertSame(101, Announcement::sole()->dispatched_count);
        $this->assertSame(0, Announcement::sole()->failed_count);
        $this->assertCount(101, $this->mails());
        $this->assertDatabaseCount('notifications', 101);
    }

    public function test_announcement_partial_failure_continues_and_retry_recalculates_history(): void
    {
        $users = User::factory()->student()->count(2)->create();
        $manager = Notification::getFacadeRoot();
        Notification::shouldReceive('sendNow')->withArgs(fn ($user) => $user->id === $users[0]->id)->times(4)->andThrow(new \RuntimeException('Synthetic failure'));
        Notification::shouldReceive('sendNow')->withArgs(fn ($user) => $user->id === $users[1]->id)->once()->andReturnNull();
        $dispatch = $this->announcement();
        $this->drain();
        $this->assertSame(1, Announcement::sole()->dispatched_count);
        $this->assertSame(1, Announcement::sole()->failed_count);
        $this->assertDatabaseCount('failed_jobs', 1);
        Notification::swap($manager);
        Artisan::call('queue:retry', ['id' => [DB::table('failed_jobs')->sole()->uuid]]);
        $this->drain();
        $this->assertSame(2, Announcement::sole()->dispatched_count);
        $this->assertSame(0, Announcement::sole()->failed_count);
        $this->assertSame(2, $dispatch->fresh()->succeeded_count);
        $this->assertDatabaseCount('announcements', 1);
    }

    public function test_all_failed_has_processing_record_but_no_history(): void
    {
        User::factory()->student()->create();
        Notification::shouldReceive('sendNow')->times(4)->andThrow(new \RuntimeException('Synthetic failure'));
        $dispatch = $this->announcement();
        $this->drain();
        $this->assertDatabaseCount('announcements', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame('failed', $dispatch->fresh()->status);
        $this->assertSame(1, $dispatch->fresh()->failed_count);
    }

    public function test_no_recipients_does_not_enqueue_or_create_history(): void
    {
        $this->actingAs(User::factory()->admin()->create())->post(route('admin.announcements.store'), $this->payload())->assertSessionHasErrors('target_type');
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_announcement_rollbacks_do_not_enqueue(): void
    {
        User::factory()->student()->create();
        $admin = User::factory()->admin()->create();
        DB::beginTransaction();
        app(StoreAction::class)($admin, $this->payload());
        DB::rollBack();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('announcement_dispatches', 0);
    }

    /** @dataProvider reminderWindows */
    public function test_reminders_enqueue_channels_then_worker_delivers_and_rerun_skips(string $window, int $hours): void
    {
        Meeting::factory()->create(['scheduled_at' => now()->addHours($hours)]);
        $action = app(SendMeetingRemindersAction::class);
        $this->assertSame(['meetings' => 1, 'queued' => 4, 'failed' => 0], $action(MeetingReminderWindow::from($window)));
        $this->assertDatabaseCount('notifications', 0);
        $this->assertCount(0, $this->mails());
        $this->drain();
        $this->assertDatabaseCount('notifications', 2);
        $this->assertCount(2, $this->mails());
        $this->assertSame(4, MeetingReminderDelivery::whereNotNull('sent_at')->count());
        $this->assertSame(0, $action(MeetingReminderWindow::from($window))['queued']);
    }

    public static function reminderWindows(): array
    {
        return [['eve', 24], ['one_hour_before', 1]];
    }

    /** @dataProvider invalidatedReminders */
    public function test_queued_reminders_recheck_meeting_and_both_user_states(string $condition): void
    {
        $meeting = Meeting::factory()->create(['scheduled_at' => now()->addHour()]);
        app(SendMeetingRemindersAction::class)(MeetingReminderWindow::OneHourBefore);
        match ($condition) {
            'canceled' => $meeting->update(['status' => 'canceled']),
            'student' => $meeting->student->update(['status' => 'graduated']),
            'coach' => $meeting->coach->update(['status' => 'withdrawn']),
        };
        $this->drain();
        $this->assertDatabaseCount('notifications', 0);
        $this->assertCount(0, $this->mails());
        $this->assertSame(4, MeetingReminderDelivery::whereNotNull('skipped_at')->count());
    }

    public static function invalidatedReminders(): array
    {
        return [['canceled'], ['student'], ['coach']];
    }

    public function test_reminder_database_success_survives_mail_failure_and_manual_retry(): void
    {
        Meeting::factory()->create(['scheduled_at' => now()->addHour()]);
        $manager = Notification::getFacadeRoot();
        Notification::shouldReceive('sendNow')->times(8)->andThrow(new \RuntimeException('Synthetic failure'));
        app(SendMeetingRemindersAction::class)(MeetingReminderWindow::OneHourBefore);
        $this->drain();
        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame(2, MeetingReminderDelivery::whereNotNull('sent_at')->count());
        $this->assertDatabaseCount('failed_jobs', 2);
        $this->assertSame(0, app(SendMeetingRemindersAction::class)(MeetingReminderWindow::OneHourBefore)['queued']);
        Notification::swap($manager);
        Artisan::call('queue:retry', ['id' => ['all']]);
        $this->drain();
        $this->assertDatabaseCount('notifications', 2);
        $this->assertCount(2, $this->mails());
        $this->assertSame(4, MeetingReminderDelivery::whereNotNull('sent_at')->count());
    }

    public function test_terminal_failure_callback_cannot_overwrite_a_successful_duplicate_job(): void
    {
        User::factory()->student()->create();
        $dispatch = $this->announcement();
        $this->drain();
        $recipient = AnnouncementRecipient::sole();
        (new DeliverAnnouncementRecipient($recipient->id))->failed(new \RuntimeException('Duplicate worker failure'));
        $this->assertSame('sent', $recipient->fresh()->status);
        $this->assertSame(1, $dispatch->fresh()->succeeded_count);
        $this->assertSame(0, $dispatch->fresh()->failed_count);
        $this->assertCount(1, $this->mails());
    }

    public function test_recipient_disappearing_before_fanout_leaves_no_history(): void
    {
        $user = User::factory()->student()->create();
        $dispatch = $this->announcement();
        $user->update(['status' => 'withdrawn']);
        $this->drain();
        $this->assertDatabaseCount('announcements', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame(0, $dispatch->fresh()->target_count);
        $this->assertSame('failed', $dispatch->fresh()->status);
    }

    private function send(User $user): void
    {
        app(ActivityNotificationService::class)->send($user, 'test:stable-event', new ActivityNotification(ActivityNotificationType::MeetingReserved, (string) Str::ulid(), '検証用のお知らせ', route('meetings.index')));
    }

    private function announcement(): AnnouncementDispatch
    {
        return app(StoreAction::class)(User::factory()->admin()->create(), $this->payload());
    }

    private function payload(): array
    {
        return ['title' => 'キュー検証', 'body' => '架空の検証用本文', 'target_type' => 'all_students', 'idempotency_key' => (string) Str::uuid()];
    }

    private function work(): void
    {
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'notifications', '--once' => true, '--sleep' => 0]);
    }

    private function makeAvailable(): void
    {
        // Dedicated test DB only: inspect the real backoff above, then advance availability without waiting 26 minutes.
        DB::table('jobs')->update(['available_at' => now()->timestamp]);
    }

    private function drain(): void
    {
        for ($i = 0; DB::table('jobs')->exists() && $i < 500; $i++) {
            $this->makeAvailable();
            $this->work();
        }
        $this->assertDatabaseCount('jobs', 0);
    }

    private function mails()
    {
        return Mail::mailer()->getSymfonyTransport()->messages();
    }
}
