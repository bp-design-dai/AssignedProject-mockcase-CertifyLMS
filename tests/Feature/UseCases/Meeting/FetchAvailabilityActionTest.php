<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\CoachAvailability;
use App\Models\Meeting;
use App\Models\User;
use App\UseCases\Meeting\FetchAvailabilityAction;
use Tests\Support\MeetingActionTestCase;

class FetchAvailabilityActionTest extends MeetingActionTestCase
{
    public function test_date_slots_counts_and_certification_scope_are_preserved(): void
    {
        [, $coach, $enrollment] = $this->context();
        CoachAvailability::where('coach_id', $coach->id)->update(['end_time' => '12:00:00']);
        $second = User::factory()->coach()->inProgress()->create();
        $enrollment->certification->coaches()->attach($second, ['assigned_by_user_id' => User::factory()->admin()->create()->id, 'assigned_at' => now()]);
        CoachAvailability::factory()->forCoach($second)->onDay(1)->timeRange('09:00:00', '12:00:00')->create();
        $unassigned = User::factory()->coach()->create();
        CoachAvailability::factory()->forCoach($unassigned)->onDay(1)->timeRange('09:00:00', '12:00:00')->create();
        Meeting::factory()->forEnrollment($enrollment)->forCoach($coach)->create(['scheduled_at' => '2030-06-17 10:00:00']);
        Meeting::factory()->forEnrollment($enrollment)->forCoach($coach)->create(['status' => 'completed', 'scheduled_at' => '2030-06-17 11:00:00']);
        Meeting::factory()->forEnrollment($enrollment)->forCoach($coach)->canceled()->create(['scheduled_at' => '2030-06-17 09:00:00']);

        $data = app(FetchAvailabilityAction::class)($enrollment, '2030-06-17');

        $this->assertSame('2030-06-17', $data['date']->toDateString());
        $this->assertSame(['09:00', '10:00', '11:00'], $data['slots']->map(fn ($slot) => $slot['slot_start']->format('H:i'))->all());
        $this->assertSame([2, 1, 1], $data['slots']->pluck('available_coach_count')->all());
        $this->assertSame(['10:00', '11:00', '12:00'], $data['slots']->map(fn ($slot) => $slot['slot_end']->format('H:i'))->all());
        $this->assertDatabaseCount('meetings', 3);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public function test_unassigned_coach_does_not_supply_slots(): void
    {
        [, $coach, $enrollment] = $this->context();
        $enrollment->certification->coaches()->updateExistingPivot($coach->id, ['unassigned_at' => now()]);

        $data = app(FetchAvailabilityAction::class)($enrollment, '2030-06-17');

        $this->assertTrue($data['slots']->isEmpty());
        $this->assertDatabaseCount('meetings', 0);
    }

    public function test_date_without_availability_returns_an_empty_collection(): void
    {
        [, , $enrollment] = $this->context();
        $data = app(FetchAvailabilityAction::class)($enrollment, '2030-06-18');

        $this->assertSame('2030-06-18', $data['date']->toDateString());
        $this->assertTrue($data['slots']->isEmpty());
    }
}
