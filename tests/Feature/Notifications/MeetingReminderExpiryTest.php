<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\MeetingReminderWindow;
use App\Jobs\DeliverMeetingReminder;
use App\Models\Meeting;
use App\Models\MeetingReminderDelivery;
use App\UseCases\Notification\SendMeetingRemindersAction;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MeetingReminderExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @dataProvider executionBoundaries */
    public function test_worker_checks_start_boundary_for_both_channels(string $window, int $secondsFromStart, bool $send): void
    {
        [$meeting, $selectedAt] = $this->enqueue($window);
        $this->clock($meeting->scheduled_at->toImmutable()->addSeconds($secondsFromStart));
        $this->workReadyJobs();
        $this->assertDeliveryCounts($send ? 4 : 0, $send ? 0 : 4, $send ? 2 : 0);
        $states = MeetingReminderDelivery::get()->mapWithKeys(fn ($d) => [$d->id => [$d->sent_at, $d->skipped_at]])->all();
        $this->duplicateJobs($selectedAt);
        $this->workReadyJobs();
        $this->assertDeliveryCounts($send ? 4 : 0, $send ? 0 : 4, $send ? 2 : 0);
        $this->assertEquals($states, MeetingReminderDelivery::get()->mapWithKeys(fn ($d) => [$d->id => [$d->sent_at, $d->skipped_at]])->all());
    }

    public static function executionBoundaries(): array
    {
        $cases = [];
        foreach (['eve', 'one_hour_before'] as $window) {
            foreach ([-1 => true, 0 => false, 60 => false, 86400 => false] as $offset => $send) {
                $cases[$window.' offset '.$offset] = [$window, $offset, $send];
            }
        }

        return $cases;
    }

    /** @dataProvider windows */
    public function test_ordinary_worker_execution_keeps_existing_reminder_delivery(string $window): void
    {
        $this->enqueue($window);
        $this->workReadyJobs();
        $this->assertDeliveryCounts(4, 0, 2);
    }

    public static function windows(): array
    {
        return [['eve'], ['one_hour_before']];
    }

    /** @dataProvider invalidations */
    public function test_worker_uses_latest_eligibility_and_skip_is_terminal(string $window, string $condition): void
    {
        [$meeting, $selectedAt] = $this->enqueue($window);
        match ($condition) {
            'canceled' => $meeting->update(['status' => 'canceled']),
            'completed' => $meeting->update(['status' => 'completed']),
            'student' => $meeting->student->update(['status' => 'graduated']),
            'coach' => $meeting->coach->update(['status' => 'withdrawn']),
        };
        $this->workReadyJobs();
        $this->assertDeliveryCounts(0, 4, 0);
        $skipped = MeetingReminderDelivery::pluck('skipped_at', 'id')->all();
        // A duplicate or manually retried job must not revive an already skipped delivery.
        $meeting->update(['status' => 'reserved']);
        $meeting->student->update(['status' => 'in_progress']);
        $meeting->coach->update(['status' => 'in_progress']);
        $this->duplicateJobs($selectedAt);
        $this->workReadyJobs();
        $this->assertDeliveryCounts(0, 4, 0);
        $this->assertEquals($skipped, MeetingReminderDelivery::pluck('skipped_at', 'id')->all());
        $this->assertSame(0, app(SendMeetingRemindersAction::class)(MeetingReminderWindow::from($window))['queued']);
        foreach (MeetingReminderDelivery::all() as $delivery) {
            (new DeliverMeetingReminder($delivery->id, $selectedAt->toIso8601String()))->failed(new \RuntimeException('Late duplicate failure'));
        }
        $this->assertSame(0, MeetingReminderDelivery::whereNotNull('failed_at')->count());
    }

    public static function invalidations(): array
    {
        $cases = [];
        foreach (['eve', 'one_hour_before'] as $window) {
            foreach (['canceled', 'completed', 'student', 'coach'] as $condition) {
                $cases[] = [$window, $condition];
            }
        }

        return $cases;
    }

    /** @dataProvider windows */
    public function test_worker_reads_changed_start_from_database_instead_of_queued_model(string $window): void
    {
        [$meeting] = $this->enqueue($window);
        // Both starts remain in the original selection window; only the fresh DB start is already past.
        DB::table('meetings')->where('id', $meeting->id)->update(['scheduled_at' => '2030-06-10 18:55:00']);
        $this->clock(CarbonImmutable::parse('2030-06-10 18:56:00', 'Asia/Tokyo'));
        $this->assertSame('19:00', $meeting->scheduled_at->format('H:i'));
        $this->workReadyJobs();
        $this->assertDeliveryCounts(0, 4, 0);
    }

    /** @dataProvider retryBoundaries */
    public function test_retry_crossing_start_skips_unsent_mail_and_keeps_successful_database_channel(int $offset): void
    {
        [$meeting, $selectedAt] = $this->enqueue('one_hour_before');
        $manager = Notification::getFacadeRoot();
        Notification::shouldReceive('sendNow')->twice()->andThrow(new \RuntimeException('Synthetic mail failure'));
        $this->workReadyJobs();
        $notification = $meeting->student->notifications()->sole();
        $notification->markAsRead();
        $readAt = $notification->fresh()->read_at;
        $this->assertSame(2, MeetingReminderDelivery::whereNotNull('sent_at')->count());
        $this->assertDatabaseCount('jobs', 2);
        Notification::swap($manager);
        $this->clock($meeting->scheduled_at->toImmutable()->addSeconds($offset));
        $this->workReadyJobs();
        $this->assertDeliveryCounts(2, 2, 0);
        $this->duplicateJobs($selectedAt);
        $this->workReadyJobs();
        $this->assertDeliveryCounts(2, 2, 0);
        $this->assertEquals($readAt, $notification->fresh()->read_at);
        $this->assertSame(0, MeetingReminderDelivery::whereNotNull('failed_at')->count());
    }

    public static function retryBoundaries(): array
    {
        return [[0], [60]];
    }

    public function test_valid_retry_before_start_survives_original_hour_selection_window(): void
    {
        $this->enqueue('one_hour_before');
        $manager = Notification::getFacadeRoot();
        Notification::shouldReceive('sendNow')->twice()->andThrow(new \RuntimeException('Synthetic mail failure'));
        $this->workReadyJobs();
        Notification::swap($manager);
        $this->clock(CarbonImmutable::parse('2030-06-10 18:30:00', 'Asia/Tokyo'));
        $this->workReadyJobs();
        $this->assertDeliveryCounts(4, 0, 2);
    }

    public function test_retry_of_terminal_mail_failure_after_start_clears_failure_and_skips(): void
    {
        $this->enqueue('one_hour_before');
        $manager = Notification::getFacadeRoot();
        Notification::shouldReceive('sendNow')->times(8)->andThrow(new \RuntimeException('Synthetic mail failure'));
        for ($attempt = 0; $attempt < 4; $attempt++) {
            DB::table('jobs')->update(['available_at' => now()->timestamp]);
            $this->workReadyJobs();
        }
        $this->assertDatabaseCount('failed_jobs', 2);
        $this->assertSame(2, MeetingReminderDelivery::whereNotNull('failed_at')->count());
        Notification::swap($manager);
        $this->clock(CarbonImmutable::parse('2030-06-10 19:00:00', 'Asia/Tokyo'));
        Artisan::call('queue:retry', ['id' => ['all']]);
        $this->workReadyJobs();
        $this->assertDeliveryCounts(2, 2, 0);
        $this->assertSame(0, MeetingReminderDelivery::whereNotNull('failed_at')->count());
        $this->assertSame(0, MeetingReminderDelivery::whereNotNull('exception_class')->count());
    }

    public function test_utc_execution_time_equal_to_jst_start_is_not_sent(): void
    {
        $this->enqueue('one_hour_before');
        $this->clock(CarbonImmutable::parse('2030-06-10T10:00:00Z'));
        $this->workReadyJobs();
        $this->assertDeliveryCounts(0, 4, 0);
    }

    private function enqueue(string $window): array
    {
        $selectedAt = CarbonImmutable::parse($window === 'eve' ? '2030-06-09 18:00:00' : '2030-06-10 18:00:00', 'Asia/Tokyo');
        $this->clock($selectedAt);
        $meeting = Meeting::factory()->create(['scheduled_at' => '2030-06-10 19:00:00']);
        $this->assertSame(['meetings' => 1, 'queued' => 4, 'failed' => 0], app(SendMeetingRemindersAction::class)(MeetingReminderWindow::from($window)));
        $this->assertDatabaseCount('jobs', 4);
        $this->assertDatabaseCount('notifications', 0);

        return [$meeting, $selectedAt];
    }

    private function clock(CarbonImmutable $time): void
    {
        Carbon::setTestNow($time);
        CarbonImmutable::setTestNow($time);
    }

    private function workReadyJobs(): void
    {
        for ($i = 0; $i < 16 && DB::table('jobs')->where('available_at', '<=', now()->timestamp)->exists(); $i++) {
            Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'notifications', '--once' => true, '--sleep' => 0]);
        }
    }

    private function duplicateJobs(CarbonImmutable $selectedAt): void
    {
        foreach (MeetingReminderDelivery::all() as $delivery) {
            DeliverMeetingReminder::dispatch($delivery->id, $selectedAt->toIso8601String());
        }
    }

    private function assertDeliveryCounts(int $sent, int $skipped, int $mails): void
    {
        $this->assertSame($sent, MeetingReminderDelivery::whereNotNull('sent_at')->count());
        $this->assertSame($skipped, MeetingReminderDelivery::whereNotNull('skipped_at')->count());
        $this->assertDatabaseCount('notifications', $sent > 0 ? 2 : 0);
        $this->assertCount($mails, Mail::mailer()->getSymfonyTransport()->messages());
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
    }
}
