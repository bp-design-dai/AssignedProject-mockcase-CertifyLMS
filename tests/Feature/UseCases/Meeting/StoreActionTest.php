<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Events\MeetingReserved;
use App\Exceptions\MeetingQuota\InsufficientMeetingQuotaException;
use App\Exceptions\Mentoring\MeetingNoAvailableCoachException;
use App\Exceptions\Mentoring\MeetingOutOfAvailabilityException;
use App\Models\Meeting;
use App\Models\MeetingQuotaTransaction;
use App\Services\MeetingQuotaService;
use App\UseCases\Meeting\StoreAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\Support\MeetingActionTestCase;

class StoreActionTest extends MeetingActionTestCase
{
    public function test_reservation_consumption_snapshot_and_notifications_commit_together(): void
    {
        [$student, $coach, $enrollment] = $this->context();
        $entryLevel = DB::transactionLevel();
        $dispatchLevels = [];
        Event::listen(MeetingReserved::class, function () use (&$dispatchLevels) {
            $dispatchLevels[] = DB::transactionLevel();
        });

        $meeting = app(StoreAction::class)($enrollment, $this->payload());

        $this->assertSame(MeetingStatus::Reserved, $meeting->status);
        $this->assertSame($enrollment->id, $meeting->enrollment_id);
        $this->assertSame($student->id, $meeting->student_id);
        $this->assertSame($coach->id, $meeting->coach_id);
        $this->assertSame($coach->meeting_url, $meeting->meeting_url_snapshot);
        $this->assertSame('T-A-02 Action test', $meeting->topic);
        $this->assertSame('2030-06-17 10:00:00', $meeting->scheduled_at->format('Y-m-d H:i:s'));
        $consumption = MeetingQuotaTransaction::query()->sole();
        $this->assertSame($consumption->id, $meeting->meeting_quota_transaction_id);
        $this->assertSame($meeting->id, $consumption->related_meeting_id);
        $this->assertSame(-1, $consumption->amount);
        $this->assertSame(2, app(MeetingQuotaService::class)->remaining($student));
        $this->assertSame([$entryLevel], $dispatchLevels);
        $this->assertSame($entryLevel, DB::transactionLevel());
        $this->assertDatabaseCount('notifications', 2);
        $this->assertCount(2, Mail::mailer()->getSymfonyTransport()->messages());
    }

    public function test_occupied_slot_is_a_conflict_without_consumption_or_notification(): void
    {
        [$student, $coach, $enrollment] = $this->context();
        Meeting::factory()->forCoach($coach)->create(['scheduled_at' => '2030-06-17 10:00:00']);
        Event::fake([MeetingReserved::class]);

        try {
            app(StoreAction::class)($enrollment, $this->payload());
            $this->fail('Expected an occupied-slot conflict.');
        } catch (MeetingNoAvailableCoachException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertDatabaseCount('meetings', 1);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
        $this->assertSame(3, app(MeetingQuotaService::class)->remaining($student));
        Event::assertNotDispatched(MeetingReserved::class);
    }

    public function test_canceled_slot_can_be_reused_without_modifying_history(): void
    {
        [$student, $coach, $enrollment] = $this->context();
        $canceled = Meeting::factory()->canceled()->forCoach($coach)->create(['scheduled_at' => '2030-06-17 10:00:00']);
        $original = $canceled->fresh()->getRawOriginal();
        Event::fake([MeetingReserved::class]);

        $new = app(StoreAction::class)($enrollment, $this->payload());

        $this->assertNotSame($canceled->id, $new->id);
        $this->assertSame($original, $canceled->fresh()->getRawOriginal());
        $this->assertSame(2, app(MeetingQuotaService::class)->remaining($student));
        $this->assertDatabaseCount('meetings', 2);
        $this->assertDatabaseCount('meeting_quota_transactions', 1);
    }

    public function test_insufficient_quota_creates_nothing(): void
    {
        [$student, , $enrollment] = $this->context(0);
        Event::fake([MeetingReserved::class]);
        try {
            app(StoreAction::class)($enrollment, $this->payload());
            $this->fail('Expected insufficient quota.');
        } catch (InsufficientMeetingQuotaException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('meetings', 0);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
        $this->assertSame(0, app(MeetingQuotaService::class)->remaining($student));
        Event::assertNotDispatched(MeetingReserved::class);
    }

    public function test_failed_consumption_rolls_back_the_meeting_and_defers_notifications(): void
    {
        [$student, , $enrollment] = $this->context();
        Event::fake([MeetingReserved::class]);
        MeetingQuotaTransaction::created(function () {
            throw new \RuntimeException('consumption-failure');
        });
        try {
            app(StoreAction::class)($enrollment, $this->payload());
            $this->fail('Expected consumption failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('consumption-failure', $exception->getMessage());
        } finally {
            MeetingQuotaTransaction::flushEventListeners();
        }
        $this->assertDatabaseCount('meetings', 0);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
        $this->assertSame(3, app(MeetingQuotaService::class)->remaining($student));
        $this->assertDatabaseCount('notifications', 0);
        Event::assertNotDispatched(MeetingReserved::class);
    }

    public function test_outside_availability_keeps_the_existing_422_domain_error(): void
    {
        [, , $enrollment] = $this->context();
        try {
            app(StoreAction::class)($enrollment, $this->payload('2030-06-17T19:00:00'));
            $this->fail('Expected out-of-availability error.');
        } catch (MeetingOutOfAvailabilityException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('meetings', 0);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }
}
