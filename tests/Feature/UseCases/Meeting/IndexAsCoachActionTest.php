<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Enrollment;
use App\Models\Meeting;
use App\UseCases\Meeting\IndexAsCoachAction;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MeetingActionTestCase;

class IndexAsCoachActionTest extends MeetingActionTestCase
{
    #[DataProvider('filters')]
    public function test_coach_scope_filter_and_direction_are_preserved(?string $filter, array $names): void
    {
        [, $coach, $enrollment] = $this->context();
        $meetings = [];
        foreach ([
            'early' => ['status' => 'reserved', 'scheduled_at' => '2030-06-17 10:00:00'],
            'late' => ['status' => 'reserved', 'scheduled_at' => '2030-06-18 10:00:00'],
            'completed' => ['status' => 'completed', 'scheduled_at' => '2030-06-09 10:00:00'],
            'canceled' => ['status' => 'canceled', 'scheduled_at' => '2030-06-19 10:00:00'],
        ] as $name => $attributes) {
            $meetings[$name] = Meeting::factory()->forEnrollment($enrollment)->forCoach($coach)->create($attributes);
        }
        Meeting::factory()->forEnrollment($enrollment)->create();

        $data = app(IndexAsCoachAction::class)($coach, ['filter' => $filter]);

        $this->assertSame(array_map(fn ($name) => $meetings[$name]->id, $names), $data['meetings']->pluck('id')->all());
        $this->assertSame($filter ?? 'upcoming', $data['filter']);
        $this->assertNull($data['studentFilter']);
        $this->assertNull($data['enrollmentFilter']);
        $this->assertSame(20, $data['meetings']->perPage());
        $this->assertTrue($data['meetings']->first()->relationLoaded('student'));
        $this->assertTrue($data['meetings']->first()->enrollment->relationLoaded('certification'));
    }

    public static function filters(): array
    {
        return [[null, ['early', 'late']], ['upcoming', ['early', 'late']], ['past', ['canceled', 'completed']], ['all', ['canceled', 'late', 'early', 'completed']]];
    }

    public function test_student_and_enrollment_filters_cannot_include_another_coachs_meeting(): void
    {
        [$student, $coach, $enrollment] = $this->context();
        $target = Meeting::factory()->forEnrollment($enrollment)->forCoach($coach)->create();
        $otherEnrollment = Enrollment::factory()->for($student)->learning()->create();
        Meeting::factory()->forEnrollment($otherEnrollment)->forCoach($coach)->create();
        Meeting::factory()->forCoach($coach)->create();
        $foreign = Meeting::factory()->forEnrollment($enrollment)->create();
        $action = app(IndexAsCoachAction::class);

        $byStudent = $action($coach, ['filter' => 'all', 'student' => $student->id]);
        $combined = $action($coach, ['filter' => 'all', 'student' => $student->id, 'enrollment' => $enrollment->id]);

        $this->assertCount(2, $byStudent['meetings']);
        $this->assertSame([$target->id], $combined['meetings']->pluck('id')->all());
        $this->assertSame($student->id, $combined['studentFilter']);
        $this->assertSame($enrollment->id, $combined['enrollmentFilter']);
        $this->assertNotContains($foreign->id, $combined['meetings']->pluck('id')->all());
    }

    public function test_upcoming_pagination_is_ascending_and_coach_scoped(): void
    {
        [, $coach, $enrollment] = $this->context();
        $ids = [];
        for ($day = 1; $day <= 21; $day++) {
            $ids[] = Meeting::factory()->forEnrollment($enrollment)->forCoach($coach)->create(['scheduled_at' => now()->addDays($day)])->id;
        }
        Meeting::factory()->forEnrollment($enrollment)->create();
        $action = app(IndexAsCoachAction::class);
        $first = $action($coach, [])['meetings'];
        app('request')->query->set('page', 2);
        $second = $action($coach, [])['meetings'];

        $this->assertSame(21, $first->total());
        $this->assertCount(20, $first);
        $this->assertCount(1, $second);
        $this->assertSame($ids, [...$first->pluck('id')->all(), ...$second->pluck('id')->all()]);
    }
}
