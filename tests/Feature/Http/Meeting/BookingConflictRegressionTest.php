<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Meeting;

use App\Enums\MeetingStatus;
use App\Events\MeetingReserved;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\MeetingQuotaTransaction;
use App\Models\User;
use App\Services\MeetingQuotaService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingConflictRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2030-06-10 18:00:00', 'Asia/Tokyo'));
        Event::fake([MeetingReserved::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[DataProvider('occupiedStatuses')]
    public function test_occupied_slot_is_409_without_quota_or_notification_changes(MeetingStatus $status): void
    {
        [$student, $coach, $enrollment] = $this->context();
        $existing = Meeting::factory()->forCoach($coach)->create(['status' => $status, 'scheduled_at' => '2030-06-17 10:00:00']);
        $attributes = $existing->fresh()->getRawOriginal();

        $this->actingAs($student)->postJson(route('meetings.store', $enrollment), $this->payload())
            ->assertStatus(409)->assertJsonPath('message', '指定された時刻には空きコーチがいません。別の時刻をお選びください。');

        $this->assertSame($attributes, $existing->fresh()->getRawOriginal());
        $this->assertDatabaseCount('meetings', 1);
        $this->assertUnspent($student);
    }

    public static function occupiedStatuses(): array
    {
        return [[MeetingStatus::Reserved], [MeetingStatus::Completed]];
    }

    public function test_canceled_slot_can_be_reserved_without_overwriting_the_old_meeting(): void
    {
        [$student, $coach, $enrollment] = $this->context();
        $canceled = Meeting::factory()->canceled()->forCoach($coach)->create(['scheduled_at' => '2030-06-17 10:00:00']);
        $attributes = $canceled->fresh()->getRawOriginal();

        $this->actingAs($student)->postJson(route('meetings.store', $enrollment), $this->payload())->assertRedirect()->assertSessionHas('success');

        $this->assertSame($attributes, $canceled->fresh()->getRawOriginal());
        $this->assertDatabaseCount('meetings', 2);
        $this->assertDatabaseHas('meetings', ['student_id' => $student->id, 'coach_id' => $coach->id, 'status' => 'reserved']);
        $this->assertDatabaseHas('meeting_quota_transactions', ['user_id' => $student->id, 'type' => 'consumed', 'amount' => -1]);
        $this->assertSame(2, app(MeetingQuotaService::class)->remaining($student));
        Event::assertDispatched(MeetingReserved::class, 1);
    }

    public function test_outside_configured_availability_still_returns_422(): void
    {
        [$student, , $enrollment] = $this->context();
        $this->actingAs($student)->postJson(route('meetings.store', $enrollment), $this->payload('2030-06-17T19:00:00'))->assertStatus(422);
        $this->assertDatabaseCount('meetings', 0);
        $this->assertUnspent($student);
    }

    public function test_primary_key_collision_is_not_hidden_as_no_available_coach(): void
    {
        [$student, $coach, $enrollment] = $this->context();
        $existing = Meeting::factory()->canceled()->forCoach($coach)->create(['scheduled_at' => '2030-06-17 10:00:00']);
        Meeting::creating(function (Meeting $meeting) use ($existing) {
            $meeting->id = $existing->id;
        });
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($student)->postJson(route('meetings.store', $enrollment), $this->payload());
            $this->fail('The unexpected primary-key collision must propagate.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertSame('23000', $exception->errorInfo[0]);
        } finally {
            Meeting::flushEventListeners();
        }

        $this->assertDatabaseCount('meetings', 1);
        $this->assertUnspent($student);
    }

    public function test_foreign_key_failure_is_not_hidden_as_a_booking_conflict(): void
    {
        [$student, , $enrollment] = $this->context();
        Meeting::creating(function (Meeting $meeting) {
            $meeting->coach_id = (string) Str::ulid();
        });
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($student)->postJson(route('meetings.store', $enrollment), $this->payload());
            $this->fail('The unexpected foreign-key failure must propagate.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', $exception->errorInfo[0]);
        } finally {
            Meeting::flushEventListeners();
        }

        $this->assertDatabaseCount('meetings', 0);
        $this->assertUnspent($student);
    }

    public function test_quota_insert_failure_rolls_back_the_booking(): void
    {
        [$student, , $enrollment] = $this->context();
        MeetingQuotaTransaction::creating(function () {
            throw new \RuntimeException('quota-write-failure');
        });
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($student)->postJson(route('meetings.store', $enrollment), $this->payload());
            $this->fail('The quota write failure must propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('quota-write-failure', $exception->getMessage());
        } finally {
            MeetingQuotaTransaction::flushEventListeners();
        }

        $this->assertDatabaseCount('meetings', 0);
        $this->assertUnspent($student);
    }

    public function test_insufficient_quota_does_not_reserve_a_slot(): void
    {
        [$student, , $enrollment] = $this->context();
        $student->update(['max_meetings' => 0]);
        $this->actingAs($student)->postJson(route('meetings.store', $enrollment), $this->payload())->assertStatus(409);
        $this->assertDatabaseCount('meetings', 0);
        $this->assertUnspent($student, 0);
    }

    public function test_another_students_enrollment_cannot_be_booked(): void
    {
        [$owner, , $enrollment] = $this->context();
        $this->actingAs(User::factory()->student()->inProgress()->create())->postJson(route('meetings.store', $enrollment), $this->payload())->assertForbidden();
        $this->assertDatabaseCount('meetings', 0);
        $this->assertUnspent($owner);
    }

    private function context(): array
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $coach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $certification->coaches()->attach($coach, ['assigned_by_user_id' => User::factory()->admin()->create()->id, 'assigned_at' => now()]);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();

        return [$student, $coach, Enrollment::factory()->for($student)->for($certification)->learning()->create()];
    }

    private function payload(string $at = '2030-06-17T10:00:00'): array
    {
        return ['scheduled_at' => $at, 'topic' => 'B-A-01 regression'];
    }

    private function assertUnspent(User $student, int $remaining = 3): void
    {
        $this->assertSame($remaining, app(MeetingQuotaService::class)->remaining($student->fresh()));
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
        Event::assertNotDispatched(MeetingReserved::class);
    }
}
