<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScheduleCommandChunkRegressionTest extends TestCase
{
    use RefreshDatabase;

    private const DEFINITIONS = [
        'users' => [User::class, 'users:graduate-expired', 'graduated'],
        'enrollments' => [Enrollment::class, 'enrollments:fail-expired', 'failed'],
        'meetings' => [Meeting::class, 'meetings:auto-complete', 'completed'],
    ];

    private function targets(string $type, int $count)
    {
        $this->travelTo(now()->setDate(2026, 10, 26)->setTime(12, 0, 0));

        return match ($type) {
            'users' => User::factory()->inProgress()->withPlan(Plan::factory()->published()->create())
                ->count($count)->create(['plan_expires_at' => now()->subDay()]),
            'enrollments' => Enrollment::factory()->learning()->count($count)->create(['exam_date' => now()->subDay()->toDateString()]),
            'meetings' => $this->meetings($count),
        };
    }

    private function meetings(int $count)
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $parent = Enrollment::factory()->for($student)->create(['exam_date' => null]);

        return Meeting::factory()->forCoach($coach)->forEnrollment($parent)->reserved()->count($count)
            ->sequence(fn ($sequence) => ['scheduled_at' => now()->subHours($sequence->index + 2)])->create();
    }

    private function summary(string $type, int $count): string
    {
        return match ($type) {
            'users' => "Graduated {$count} expired users.",
            'enrollments' => "Failed {$count} expired enrollments.",
            'meetings' => "自動完了した面談を {$count} 件処理しました。",
        };
    }

    private function assertHistories(string $type, array $ids, int $count): void
    {
        if ($type === 'meetings') {
            $this->assertSame($count, Meeting::query()->whereKey($ids)->whereNotNull('completed_at')->count());

            return;
        }
        $table = $type === 'users' ? 'user_status_logs' : 'enrollment_status_logs';
        $key = $type === 'users' ? 'user_id' : 'enrollment_id';
        $query = DB::table($table)->whereIn($key, $ids)->where('to_status', self::DEFINITIONS[$type][2]);
        $this->assertSame($count, (clone $query)->count());
        $this->assertSame($count, (clone $query)->distinct()->count($key));
        if ($type === 'users') {
            $this->assertSame($count, DB::table('user_plan_logs')->whereIn('user_id', $ids)->where('event_type', 'expired')->count());
        }
    }

    public static function sizes(): array
    {
        $cases = [];
        foreach (array_keys(self::DEFINITIONS) as $type) {
            foreach ([0, 1, 100, 101, 201] as $count) {
                $cases[$type.'-'.$count] = [$type, $count];
            }
        }

        return $cases;
    }

    /** @dataProvider sizes */
    public function test_all_targets_are_processed_once_in_id_order_and_repeat_is_a_noop(string $type, int $count): void
    {
        $rows = $this->targets($type, $count);
        [$model, $command, $status] = self::DEFINITIONS[$type];
        $ids = $rows->modelKeys();
        sort($ids);
        $seen = [];
        $dispatcher = $model::getEventDispatcher();
        $model::setEventDispatcher(clone $dispatcher);
        $model::updated(function ($row) use (&$seen, $status): void {
            if ($row->wasChanged('status') && $row->status->value === $status) {
                $seen[] = $row->id;
            }
        });
        try {
            $this->artisan($command)->expectsOutput($this->summary($type, $count))->assertSuccessful();
            $this->assertSame($ids, $seen);
            $this->assertSame($count, $model::query()->whereKey($ids)->where('status', $status)->count());
            $this->assertHistories($type, $ids, $count);
            $this->artisan($command)->expectsOutput($this->summary($type, 0))->assertSuccessful();
            $this->assertSame($ids, $seen);
            $this->assertHistories($type, $ids, $count);
        } finally {
            $model::setEventDispatcher($dispatcher);
        }
    }

    public static function types(): array
    {
        return [['users'], ['enrollments'], ['meetings']];
    }

    /** @dataProvider types */
    public function test_boundary_and_other_states_are_not_processed_across_chunks(string $type): void
    {
        $rows = $this->targets($type, 101);
        $excluded = match ($type) {
            'users' => collect([
                User::factory()->inProgress()->create(['plan_expires_at' => now()]),
                User::factory()->inProgress()->create(['plan_expires_at' => now()->addDay()]),
                User::factory()->inProgress()->create(['plan_expires_at' => null]),
                User::factory()->invited()->create(['plan_expires_at' => now()->subDay()]),
                User::factory()->withdrawn()->create(['plan_expires_at' => now()->subDay()]),
            ]),
            'enrollments' => collect([
                Enrollment::factory()->learning()->create(['exam_date' => now()->toDateString()]),
                Enrollment::factory()->learning()->create(['exam_date' => now()->addDay()->toDateString()]),
                Enrollment::factory()->learning()->create(['exam_date' => null]),
                Enrollment::factory()->passed()->create(['exam_date' => now()->subDay()->toDateString()]),
                Enrollment::factory()->failed()->create(['exam_date' => now()->subDay()->toDateString()]),
            ]),
            'meetings' => collect([
                Meeting::factory()->reserved()->create(['scheduled_at' => now()->subHour()]),
                Meeting::factory()->reserved()->create(['scheduled_at' => now()->addDay()]),
                Meeting::factory()->canceled()->create(['scheduled_at' => now()->subDays(2)]),
                Meeting::factory()->completed()->create(['scheduled_at' => now()->subDays(3)]),
            ]),
        };
        [$model, $command] = self::DEFINITIONS[$type];
        $snapshots = $excluded->mapWithKeys(fn ($row) => [$row->id => $row->fresh()->getRawOriginal()])->all();
        $this->artisan($command)->expectsOutput($this->summary($type, 101))->assertSuccessful();
        $this->assertHistories($type, $rows->modelKeys(), 101);
        foreach ($excluded as $row) {
            $this->assertSame($snapshots[$row->id], $row->fresh()->getRawOriginal());
        }
    }

    /** @dataProvider types */
    public function test_failure_after_first_chunk_rolls_back_current_record_and_can_resume(string $type): void
    {
        $rows = $this->targets($type, 201);
        [$model, $command, $status] = self::DEFINITIONS[$type];
        $ids = $rows->modelKeys();
        sort($ids);
        $blockedId = $ids[100];
        $dispatcher = $model::getEventDispatcher();
        $model::setEventDispatcher(clone $dispatcher);
        $model::updated(function ($row) use ($blockedId): void {
            if ($row->id === $blockedId) {
                throw new \RuntimeException('TB02 simulated failure after update.');
            }
        });
        $level = DB::transactionLevel();
        try {
            Artisan::call($command);
            $this->fail('The existing exception must propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('TB02 simulated failure after update.', $e->getMessage());
        } finally {
            $model::setEventDispatcher($dispatcher);
        }
        $this->assertSame($level, DB::transactionLevel());
        $this->assertSame(100, $model::query()->whereKey($ids)->where('status', $status)->count());
        $this->assertNotSame($status, $model::query()->findOrFail($blockedId)->status->value);
        $this->assertHistories($type, $ids, 100);
        $this->artisan($command)->expectsOutput($this->summary($type, 101))->assertSuccessful();
        $this->assertHistories($type, $ids, 201);
    }

    public function test_scheduler_keeps_existing_times_and_overlap_protection(): void
    {
        $this->app->make(Kernel::class)->bootstrap();
        $events = collect($this->app->make(Schedule::class)->events());
        foreach (['users:graduate-expired' => '45 0 * * *', 'enrollments:fail-expired' => '0 0 * * *', 'meetings:auto-complete' => '*/15 * * * *'] as $command => $expression) {
            $matches = $events->filter(fn ($event) => str_contains($event->command ?? '', $command));
            $this->assertCount(1, $matches);
            $event = $matches->first();
            $this->assertSame($expression, $event->expression);
            $this->assertTrue($event->withoutOverlapping);
            $this->assertSame(5, $event->expiresAt);
        }
    }
}
