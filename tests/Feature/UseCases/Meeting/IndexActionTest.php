<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Meeting;
use App\Models\MeetingQuotaTransaction;
use App\Models\User;
use App\UseCases\Meeting\IndexAction;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MeetingActionTestCase;

class IndexActionTest extends MeetingActionTestCase
{
    #[DataProvider('filters')]
    public function test_student_scope_filter_order_and_view_data_are_preserved(?string $filter, array $names): void
    {
        [$student, $coach, $enrollment] = $this->context();
        $meetings = [];
        foreach ([
            'early' => ['status' => 'reserved', 'scheduled_at' => '2030-06-17 10:00:00'],
            'late' => ['status' => 'reserved', 'scheduled_at' => '2030-06-18 10:00:00'],
            'completed' => ['status' => 'completed', 'scheduled_at' => '2030-06-09 10:00:00'],
            'canceled' => ['status' => 'canceled', 'scheduled_at' => '2030-06-19 10:00:00'],
        ] as $name => $attributes) {
            $meetings[$name] = Meeting::factory()->forEnrollment($enrollment)->forCoach($coach)->create($attributes);
        }
        Meeting::factory()->forCoach($coach)->forStudent(User::factory()->student()->create())->create();

        $data = app(IndexAction::class)($student, $filter);

        $this->assertSame(array_map(fn ($name) => $meetings[$name]->id, $names), $data['meetings']->pluck('id')->all());
        $this->assertSame($filter ?? 'upcoming', $data['filter']);
        $this->assertSame(3, $data['meetingsRemaining']);
        $this->assertSame(20, $data['meetings']->perPage());
        $first = $data['meetings']->first();
        $this->assertTrue($first->relationLoaded('coach'));
        $this->assertTrue($first->enrollment->relationLoaded('certification'));
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public static function filters(): array
    {
        return [[null, ['late', 'early']], ['upcoming', ['late', 'early']], ['past', ['canceled', 'completed']], ['all', ['canceled', 'late', 'early', 'completed']]];
    }

    public function test_twenty_item_pagination_does_not_mix_other_students(): void
    {
        [$student, $coach, $enrollment] = $this->context();
        $ownIds = [];
        for ($day = 1; $day <= 21; $day++) {
            $ownIds[] = Meeting::factory()->forEnrollment($enrollment)->forCoach($coach)->create(['scheduled_at' => now()->addDays($day)])->id;
        }
        Meeting::factory()->forCoach($coach)->create();
        $action = app(IndexAction::class);
        $first = $action($student, 'all')['meetings'];
        app('request')->query->set('page', 2);
        $second = $action($student, 'all')['meetings'];

        $this->assertSame(21, $first->total());
        $this->assertCount(20, $first);
        $this->assertCount(1, $second);
        $this->assertSame(array_reverse($ownIds), [...$first->pluck('id')->all(), ...$second->pluck('id')->all()]);
    }

    public function test_remaining_quota_is_included_without_changing_history(): void
    {
        [$student] = $this->context();
        $transaction = MeetingQuotaTransaction::factory()->for($student)->create(['type' => 'consumed', 'amount' => -1, 'related_meeting_id' => null]);
        $original = $transaction->fresh()->getRawOriginal();

        $data = app(IndexAction::class)($student);

        $this->assertSame(2, $data['meetingsRemaining']);
        $this->assertSame($original, $transaction->fresh()->getRawOriginal());
        $this->assertCount(0, $data['meetings']);
    }
}
