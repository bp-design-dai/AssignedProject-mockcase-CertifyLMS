<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\User;
use App\UseCases\Plan\ChangeStatusAction;
use App\UseCases\Plan\DestroyAction;
use App\UseCases\Plan\StoreAction;
use App\UseCases\Plan\UpdateAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class MutationTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_models_are_rechecked_before_transition_update_and_delete(): void
    {
        $actor = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create(['sort_order' => 44]);
        app(ChangeStatusAction::class)($plan, PlanStatus::Published, $actor);
        try {
            app(ChangeStatusAction::class)($plan, PlanStatus::Published, $actor);
            $this->fail('重複公開を許可してはいけない');
        } catch (ConflictHttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        app(UpdateAction::class)($plan, ['name' => '基本情報のみ更新', 'status' => 'draft', 'created_by_user_id' => 'invalid'], $actor);
        $this->assertSame(PlanStatus::Published, $plan->fresh()->status);
        $this->assertSame(44, $plan->fresh()->sort_order);
        try {
            app(DestroyAction::class)($plan);
            $this->fail('古いDraftのインスタンスでPublishedを削除してはいけない');
        } catch (ConflictHttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseHas('plans', ['id' => $plan->id, 'status' => 'published']);
    }

    public function test_new_assignments_after_loading_a_draft_are_not_missed_by_delete(): void
    {
        $plan = Plan::factory()->draft()->create();
        $plan->load('users');
        $user = User::factory()->invited()->withPlan($plan)->create();
        try {
            app(DestroyAction::class)($plan);
            $this->fail('読み込み後に追加された参照を見逃してはいけない');
        } catch (ConflictHttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseHas('plans', ['id' => $plan->id]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'plan_id' => $plan->id]);
    }

    public function test_store_never_accepts_status_or_audit_fields_from_the_input(): void
    {
        $actor = User::factory()->admin()->create();
        $plan = app(StoreAction::class)(['name' => '下書き固定', 'duration_days' => 1, 'default_meeting_quota' => 0,
            'status' => 'published', 'created_by_user_id' => 'invalid', 'updated_by_user_id' => 'invalid'], $actor);
        $this->assertSame(PlanStatus::Draft, $plan->status);
        $this->assertSame($actor->id, $plan->created_by_user_id);
        $this->assertSame($actor->id, $plan->updated_by_user_id);
    }

    /** @dataProvider failingOperations */
    public function test_writes_roll_back_if_a_model_event_throws(string $operation): void
    {
        $actor = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create();
        $before = $plan->fresh()->getAttributes();
        $dispatcher = Plan::getEventDispatcher();
        Plan::setEventDispatcher(clone $dispatcher);
        if ($operation === 'delete') {
            Plan::deleted(fn () => throw new RuntimeException('検証用の保存失敗'));
        } else {
            Plan::updated(fn () => throw new RuntimeException('検証用の保存失敗'));
        }
        try {
            match ($operation) {
                'delete' => app(DestroyAction::class)($plan),
                'update' => app(UpdateAction::class)($plan, ['name' => '保存失敗'], $actor),
                default => app(ChangeStatusAction::class)($plan, PlanStatus::Published, $actor),
            };
            $this->fail('保存失敗を無視してはいけない');
        } catch (RuntimeException $e) {
            $this->assertSame('検証用の保存失敗', $e->getMessage());
        } finally {
            Plan::setEventDispatcher($dispatcher);
        }
        $this->assertSame($before, Plan::findOrFail($plan->id)->getAttributes());
    }

    public static function failingOperations(): array
    {
        return [['transition'], ['update'], ['delete']];
    }
}
