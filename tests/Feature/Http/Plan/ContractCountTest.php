<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Enums\PlanStatus;
use App\Enums\UserStatus;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserPlanLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractCountTest extends TestCase
{
    use RefreshDatabase;

    /** @dataProvider userStatuses */
    public function test_index_and_detail_only_include_current_in_progress_users(string $status, int $expected): void
    {
        $plan = Plan::factory()->published()->create();
        $user = User::factory()->student()->withPlan($plan)->create(['status' => $status]);
        $this->actingAs(User::factory()->admin()->create());
        $plans = $this->get('/admin/plans')->assertOk()->viewData('plans');
        $this->assertSame($expected, $plans->firstWhere('id', $plan->id)->users_count);
        $detail = $this->get(route('admin.plans.show', $plan))->assertOk();
        $users = $detail->viewData('plan')->users;
        $this->assertCount($expected, $users);
        $this->assertSame($expected === 1, $users->contains('id', $user->id));
    }

    public static function userStatuses(): array
    {
        return array_map(fn ($status) => [$status->value, $status === UserStatus::InProgress ? 1 : 0], UserStatus::cases());
    }

    public function test_history_is_not_current_assignment_and_repeated_logs_do_not_duplicate_users(): void
    {
        $old = Plan::factory()->published()->create();
        $current = Plan::factory()->published()->create();
        $user = User::factory()->student()->inProgress()->withPlan($current)->create();
        UserPlanLog::factory()->for($old, 'plan')->for($user)->count(2)->create();
        UserPlanLog::factory()->for($current, 'plan')->for($user)->count(3)->create();
        $deleted = User::factory()->student()->inProgress()->withPlan($current)->create();
        $deleted->delete();
        $before = UserPlanLog::query()->orderBy('id')->get()->toArray();
        $this->actingAs(User::factory()->admin()->create());
        $plans = $this->get('/admin/plans')->assertOk()->viewData('plans');
        $this->assertSame(0, $plans->firstWhere('id', $old->id)->users_count);
        $this->assertSame(1, $plans->firstWhere('id', $current->id)->users_count);
        $this->get(route('admin.plans.show', $old))->assertViewHas('plan', fn ($p) => $p->users->isEmpty());
        $this->get(route('admin.plans.show', $current))->assertViewHas('plan', fn ($p) => $p->users->count() === 1 && $p->users->first()->is($user));
        $this->assertSame($before, UserPlanLog::query()->orderBy('id')->get()->toArray());
    }

    public function test_contract_count_follows_status_not_expiry_and_does_not_change_shared_users_relation(): void
    {
        $plan = Plan::factory()->archived()->create();
        $active = User::factory()->withPlan($plan)->inProgress()->create(['plan_expires_at' => now()->subDay()]);
        User::factory()->withPlan($plan)->graduated()->create(['plan_expires_at' => now()->addYear()]);
        User::factory()->inProgress()->create(['plan_id' => null]);
        $this->actingAs(User::factory()->admin()->create());
        $this->get(route('admin.plans.index', ['status' => PlanStatus::Archived->value]))
            ->assertViewHas('plans', fn ($p) => $p->firstWhere('id', $plan->id)->users_count === 1);
        $this->get(route('admin.plans.show', $plan))->assertViewHas('plan', fn ($p) => $p->users->count() === 1 && $p->users->first()->is($active));
        $this->assertCount(2, $plan->fresh()->users);
    }
}
