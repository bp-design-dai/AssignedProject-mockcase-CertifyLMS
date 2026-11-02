<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Models\MeetingMemo;
use App\UseCases\Meeting\UpsertMemoAction;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MeetingActionTestCase;

class UpsertMemoActionTest extends MeetingActionTestCase
{
    #[DataProvider('allowedStatuses')]
    public function test_memo_can_be_created_and_updated_without_other_changes(MeetingStatus $status): void
    {
        $meeting = $this->meeting(['status' => $status]);
        $original = $meeting->fresh()->getRawOriginal();
        $action = app(UpsertMemoAction::class);
        $action($meeting, 'First memo');
        $memo = MeetingMemo::query()->sole();
        $this->assertSame($meeting->id, $memo->meeting_id);
        $this->assertSame('First memo', $memo->body);
        $action($meeting, 'Updated memo');
        $this->assertDatabaseCount('meeting_memos', 1);
        $this->assertSame('Updated memo', $memo->fresh()->body);
        $this->assertSame($original, $meeting->fresh()->getRawOriginal());
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public static function allowedStatuses(): array
    {
        return [[MeetingStatus::Reserved], [MeetingStatus::Completed]];
    }

    public function test_canceled_meeting_cannot_receive_a_memo(): void
    {
        $meeting = $this->meeting(['status' => MeetingStatus::Canceled]);
        try {
            app(UpsertMemoAction::class)($meeting, 'Rejected memo');
            $this->fail('Expected memo refusal.');
        } catch (MeetingStatusTransitionException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('meeting_memos', 0);
    }

    public function test_failed_memo_write_is_rolled_back(): void
    {
        $meeting = $this->meeting();
        $original = $meeting->fresh()->getRawOriginal();
        MeetingMemo::created(function () {
            throw new \RuntimeException('memo-failure');
        });
        try {
            app(UpsertMemoAction::class)($meeting, 'Rollback memo');
            $this->fail('Expected memo failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('memo-failure', $exception->getMessage());
        } finally {
            MeetingMemo::flushEventListeners();
        }
        $this->assertDatabaseCount('meeting_memos', 0);
        $this->assertSame($original, $meeting->fresh()->getRawOriginal());
    }
}
