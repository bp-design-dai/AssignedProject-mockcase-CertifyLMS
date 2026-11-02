<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\MeetingMemo;
use App\UseCases\Meeting\ShowAction;
use Tests\Support\MeetingActionTestCase;

class ShowActionTest extends MeetingActionTestCase
{
    public function test_detail_loads_the_same_related_records_without_writes(): void
    {
        $meeting = $this->meeting(['status' => 'canceled']);
        $meeting->update(['canceled_by_user_id' => $meeting->coach_id, 'canceled_at' => now()]);
        MeetingMemo::factory()->for($meeting)->create(['body' => 'Memo for this meeting']);
        $original = $meeting->fresh()->getRawOriginal();

        $result = app(ShowAction::class)($meeting->fresh());

        $this->assertSame($meeting->id, $result->id);
        $this->assertSame($original, $result->getRawOriginal());
        foreach (['enrollment', 'coach', 'student', 'canceledBy', 'meetingMemo'] as $relation) {
            $this->assertTrue($result->relationLoaded($relation));
        }
        $this->assertTrue($result->enrollment->relationLoaded('certification'));
        $this->assertSame($meeting->coach_id, $result->canceledBy->id);
        $this->assertSame('Memo for this meeting', $result->meetingMemo->body);
        $this->assertDatabaseCount('meetings', 1);
        $this->assertDatabaseCount('meeting_memos', 1);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_preloaded_relations_are_not_reloaded(): void
    {
        $meeting = $this->meeting();
        $memo = MeetingMemo::factory()->for($meeting)->create(['body' => 'Already loaded']);
        $meeting->setRelation('meetingMemo', $memo);
        MeetingMemo::whereKey($memo->id)->update(['body' => 'New DB value']);

        $result = app(ShowAction::class)($meeting);

        $this->assertSame($meeting, $result);
        $this->assertSame($memo, $result->meetingMemo);
        $this->assertSame('Already loaded', $result->meetingMemo->body);
    }

    public function test_missing_optional_relations_stay_null(): void
    {
        $meeting = $this->meeting();
        $result = app(ShowAction::class)($meeting);

        $this->assertNull($result->meetingMemo);
        $this->assertNull($result->canceledBy);
        $this->assertDatabaseCount('meeting_memos', 0);
    }
}
