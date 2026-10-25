<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\EnrollmentGoal;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\UseCases\Enrollment\DestroyAction as DestroyEnrollmentAction;
use App\UseCases\EnrollmentGoal\DestroyAction;
use App\UseCases\EnrollmentGoal\MarkAchievedAction;
use App\UseCases\EnrollmentGoal\StoreAction;
use App\UseCases\EnrollmentGoal\UnmarkAchievedAction;
use App\UseCases\EnrollmentGoal\UpdateAction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class MutationTest extends TestCase
{
    use RefreshDatabase;

    /** @dataProvider operations */
    public function test_deleted_parent_is_rechecked_even_when_a_stale_model_is_passed(string $operation): void
    {
        $goal = EnrollmentGoal::factory()->create();
        $parent = $goal->enrollment;
        $parent->delete();
        try {
            $this->operate($operation, $goal, $parent);
            $this->fail('削除済みの親への操作は許可しない');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('enrollment_goals', 0);
        }
    }

    /** @dataProvider operations */
    public function test_all_writes_roll_back_on_model_event_failure(string $operation): void
    {
        $goal = EnrollmentGoal::factory()->achieved()->create();
        if ($operation === 'mark') {
            $goal->update(['achieved_at' => null]);
        }
        $before = $goal->fresh()->getAttributes();
        $dispatcher = EnrollmentGoal::getEventDispatcher();
        EnrollmentGoal::setEventDispatcher(clone $dispatcher);
        $event = match ($operation) {
            'store' => 'created', 'delete' => 'deleted', default => 'updated'
        };
        EnrollmentGoal::{$event}(fn () => throw new RuntimeException('検証用の書き込み失敗'));
        try {
            $this->operate($operation, $goal, $goal->enrollment);
            $this->fail('例外を無視しない');
        } catch (RuntimeException $e) {
            $this->assertSame('検証用の書き込み失敗', $e->getMessage());
        } finally {
            EnrollmentGoal::setEventDispatcher($dispatcher);
        }
        $this->assertDatabaseCount('enrollment_goals', 1);
        $this->assertSame($before, $goal->fresh()->getAttributes());
    }

    public function test_parent_cancellation_rolls_back_goal_deletion_when_cancellation_fails(): void
    {
        $goal = EnrollmentGoal::factory()->create();
        $parent = $goal->enrollment;
        $dispatcher = Enrollment::getEventDispatcher();
        Enrollment::setEventDispatcher(clone $dispatcher);
        Enrollment::deleted(fn () => throw new RuntimeException('検証用の受講解除失敗'));
        try {
            app(DestroyEnrollmentAction::class)($parent);
            $this->fail('例外を無視しない');
        } catch (RuntimeException $e) {
            $this->assertSame('検証用の受講解除失敗', $e->getMessage());
        } finally {
            Enrollment::setEventDispatcher($dispatcher);
        }
        $this->assertFalse($parent->fresh()->trashed());
        $this->assertDatabaseHas('enrollment_goals', ['id' => $goal->id]);
    }

    public static function operations(): array
    {
        return [['store'], ['update'], ['delete'], ['mark'], ['unmark']];
    }

    private function operate(string $operation, EnrollmentGoal $goal, Enrollment $parent): void
    {
        match ($operation) {
            'store' => app(StoreAction::class)($parent, ['title' => '新規']),
            'update' => app(UpdateAction::class)($goal, ['title' => '更新']),
            'delete' => app(DestroyAction::class)($goal),
            'mark' => app(MarkAchievedAction::class)($goal),
            'unmark' => app(UnmarkAchievedAction::class)($goal),
        };
    }
}
