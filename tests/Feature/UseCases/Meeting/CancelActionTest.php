<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Events\MeetingCanceled;
use App\Exceptions\Mentoring\MeetingAlreadyStartedException;
use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Models\MeetingQuotaTransaction;
use App\Services\MeetingQuotaService;
use App\UseCases\Meeting\CancelAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MeetingActionTestCase;

class CancelActionTest extends MeetingActionTestCase
{
    #[DataProvider('actors')]
    public function test_cancel_records_actor_refunds_once_and_notifies_after_commit(string $role): void
    {
        $meeting = $this->consumedMeeting();
        $original = $meeting->quotaTransaction->fresh()->getRawOriginal();
        $actor = $meeting->$role;
        $entryLevel = DB::transactionLevel();
        $dispatchLevels = [];
        Event::listen(MeetingCanceled::class, function () use (&$dispatchLevels) {
            $dispatchLevels[] = DB::transactionLevel();
        });

        app(CancelAction::class)($meeting, $actor);

        $this->assertSame(MeetingStatus::Canceled, $meeting->fresh()->status);
        $this->assertSame($actor->id, $meeting->fresh()->canceled_by_user_id);
        $this->assertNotNull($meeting->fresh()->canceled_at);
        $this->assertSame($original, $meeting->quotaTransaction->fresh()->getRawOriginal());
        $this->assertSame(3, app(MeetingQuotaService::class)->remaining($meeting->student));
        $this->assertDatabaseCount('meeting_quota_transactions', 2);
        $this->assertDatabaseHas('meeting_quota_transactions', ['related_meeting_id' => $meeting->id, 'user_id' => $meeting->student_id, 'type' => 'refunded', 'amount' => 1]);
        $this->assertSame([$entryLevel], $dispatchLevels);
        $this->assertSame($entryLevel, DB::transactionLevel());
        $this->assertDatabaseCount('notifications', 2);
        $this->assertCount(2, Mail::mailer()->getSymfonyTransport()->messages());
    }

    public static function actors(): array
    {
        return [['student'], ['coach']];
    }

    #[DataProvider('ineligible')]
    public function test_ineligible_status_or_start_time_cannot_be_refunded(MeetingStatus $status, string $at, string $error): void
    {
        $meeting = $this->consumedMeeting(['status' => $status, 'scheduled_at' => $at]);
        $original = $meeting->fresh()->getRawOriginal();
        Event::fake([MeetingCanceled::class]);
        try {
            app(CancelAction::class)($meeting, $meeting->student);
            $this->fail('Expected cancellation refusal.');
        } catch (\Throwable $exception) {
            $this->assertInstanceOf($error, $exception);
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame($original, $meeting->fresh()->getRawOriginal());
        $this->assertDatabaseCount('meeting_quota_transactions', 1);
        $this->assertSame(2, app(MeetingQuotaService::class)->remaining($meeting->student));
        Event::assertNotDispatched(MeetingCanceled::class);
    }

    public static function ineligible(): array
    {
        return [
            [MeetingStatus::Canceled, '2030-06-17 10:00:00', MeetingStatusTransitionException::class],
            [MeetingStatus::Completed, '2030-06-17 10:00:00', MeetingStatusTransitionException::class],
            [MeetingStatus::Reserved, '2030-06-10 18:00:00', MeetingAlreadyStartedException::class],
            [MeetingStatus::Reserved, '2030-06-10 17:59:59', MeetingAlreadyStartedException::class],
        ];
    }

    public function test_stale_reserved_model_is_rechecked_under_lock(): void
    {
        $meeting = $this->consumedMeeting();
        $stale = $meeting->fresh();
        Event::fake([MeetingCanceled::class]);
        app(CancelAction::class)($meeting, $meeting->student);
        try {
            app(CancelAction::class)($stale, $stale->student);
            $this->fail('Expected duplicate cancellation refusal.');
        } catch (MeetingStatusTransitionException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame(3, app(MeetingQuotaService::class)->remaining($meeting->student));
        $this->assertDatabaseCount('meeting_quota_transactions', 2);
        Event::assertDispatched(MeetingCanceled::class, 1);
    }

    public function test_failed_refund_rolls_back_cancellation_and_notification(): void
    {
        $meeting = $this->consumedMeeting();
        $original = $meeting->fresh()->getRawOriginal();
        Event::fake([MeetingCanceled::class]);
        MeetingQuotaTransaction::created(function () {
            throw new \RuntimeException('refund-failure');
        });
        try {
            app(CancelAction::class)($meeting, $meeting->student);
            $this->fail('Expected refund failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('refund-failure', $exception->getMessage());
        } finally {
            MeetingQuotaTransaction::flushEventListeners();
        }
        $this->assertSame($original, $meeting->fresh()->getRawOriginal());
        $this->assertDatabaseCount('meeting_quota_transactions', 1);
        $this->assertSame(2, app(MeetingQuotaService::class)->remaining($meeting->student));
        $this->assertDatabaseCount('notifications', 0);
        Event::assertNotDispatched(MeetingCanceled::class);
    }
}
