<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\UseCases\MeetingQuota\ConsumeQuotaAction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class MeetingActionTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2030-06-10 18:00:00', 'Asia/Tokyo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function context(int $quota = 3): array
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => $quota]);
        $coach = User::factory()->coach()->inProgress()->create(['meeting_url' => 'https://meet.example.com/ta02-coach-room']);
        $certification = Certification::factory()->published()->create();
        $certification->coaches()->attach($coach, ['assigned_by_user_id' => User::factory()->admin()->create()->id, 'assigned_at' => now()]);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();

        return [$student, $coach, Enrollment::factory()->for($student)->for($certification)->learning()->create()];
    }

    protected function meeting(array $attributes = []): Meeting
    {
        [, $coach, $enrollment] = $this->context();

        return Meeting::factory()->forEnrollment($enrollment)->forCoach($coach)
            ->create(array_merge(['scheduled_at' => '2030-06-17 10:00:00'], $attributes));
    }

    protected function consumedMeeting(array $attributes = []): Meeting
    {
        $meeting = $this->meeting($attributes);
        $consumed = app(ConsumeQuotaAction::class)($meeting->student, $meeting->id);
        $meeting->update(['meeting_quota_transaction_id' => $consumed->id]);

        return $meeting->fresh();
    }

    protected function payload(string $scheduledAt = '2030-06-17T10:00:00'): array
    {
        return ['scheduled_at' => $scheduledAt, 'topic' => 'T-A-02 Action test'];
    }
}
