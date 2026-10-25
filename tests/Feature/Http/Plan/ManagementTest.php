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

class ManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_the_provided_management_screen(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/plans')->assertOk()->assertViewIs('plan.management.index');
    }

    public function test_admin_can_create_show_and_edit_a_draft_using_the_provided_forms(): void
    {
        $creator = User::factory()->admin()->create();
        $editor = User::factory()->admin()->create();
        $this->actingAs($creator)->get('/admin/plans/create')->assertOk();
        $this->post('/admin/plans', $this->payload() + ['created_by_user_id' => $editor->id])->assertRedirect();
        $plan = Plan::query()->sole();
        $this->assertSame(PlanStatus::Draft, $plan->status);
        $this->assertSame($creator->id, $plan->created_by_user_id);
        $this->assertSame(0, $plan->sort_order);
        $this->get(route('admin.plans.show', $plan))->assertOk()->assertSee($plan->name)->assertSee($creator->name);
        $this->get(route('admin.plans.edit', $plan))->assertOk()->assertSee($plan->name);
        $this->actingAs($editor)->put(route('admin.plans.update', $plan), array_replace($this->payload(), [
            'name' => '改定プラン', 'duration_days' => 3650, 'default_meeting_quota' => 1000, 'sort_order' => 65535,
        ]))->assertRedirect(route('admin.plans.show', $plan))->assertSessionHas('success', 'プランを更新しました。');
        $plan->refresh();
        $this->assertSame('改定プラン', $plan->name);
        $this->assertSame(3650, $plan->duration_days);
        $this->assertSame(1000, $plan->default_meeting_quota);
        $this->assertSame(65535, $plan->sort_order);
        $this->assertSame($creator->id, $plan->created_by_user_id);
        $this->assertSame($editor->id, $plan->updated_by_user_id);
        $this->assertSame(PlanStatus::Draft, $plan->status);
    }

    public function test_optional_fields_and_lower_bound_values_are_accepted(): void
    {
        $this->actingAs(User::factory()->admin()->create())->post('/admin/plans', [
            'name' => str_repeat('あ', 100), 'description' => null,
            'duration_days' => 1, 'default_meeting_quota' => 0, 'sort_order' => null,
        ])->assertRedirect()->assertSessionHas('success', 'プランを作成しました。');
        $plan = Plan::query()->sole();
        $this->assertSame(1, $plan->duration_days);
        $this->assertSame(0, $plan->default_meeting_quota);
        $this->assertSame(0, $plan->sort_order);
    }

    /** @dataProvider invalidInputs */
    public function test_invalid_create_and_update_do_not_write_data(string $field, mixed $value): void
    {
        $plan = Plan::factory()->create();
        $before = $plan->fresh()->getAttributes();
        $data = array_replace($this->payload(), [$field => $value]);
        $this->actingAs(User::factory()->admin()->create())->postJson('/admin/plans', $data)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->putJson(route('admin.plans.update', $plan), $data)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('plans', 1);
        $this->assertSame($before, $plan->fresh()->getAttributes());
    }

    public static function invalidInputs(): array
    {
        return [
            ['name', ''], ['name', str_repeat('あ', 101)], ['name', []],
            ['description', str_repeat('あ', 2001)], ['description', []],
            ['duration_days', null], ['duration_days', 0], ['duration_days', 3651], ['duration_days', 1.5],
            ['default_meeting_quota', null], ['default_meeting_quota', -1], ['default_meeting_quota', 1001], ['default_meeting_quota', 1.5],
            ['sort_order', -1], ['sort_order', 65536], ['sort_order', 1.5],
            ['status', 'published'], ['status', 'archived'],
        ];
    }

    /** @dataProvider transitionMatrix */
    public function test_only_the_three_lifecycle_transitions_are_allowed(string $before, string $operation, string $after, bool $allowed): void
    {
        $plan = Plan::factory()->create(['status' => $before]);
        $actor = User::factory()->admin()->create();
        $original = $plan->fresh()->getAttributes();
        $response = $this->actingAs($actor)->postJson(route('admin.plans.'.$operation, $plan));
        if ($allowed) {
            $response->assertRedirect(route('admin.plans.show', $plan))->assertSessionHas('success');
            $this->assertSame($after, $plan->fresh()->status->value);
            $this->assertSame($actor->id, $plan->fresh()->updated_by_user_id);
        } else {
            $response->assertConflict();
            $this->assertSame($original, $plan->fresh()->getAttributes());
        }
    }

    public static function transitionMatrix(): array
    {
        return [
            ['draft', 'publish', 'published', true],
            ['published', 'archive', 'archived', true],
            ['archived', 'unarchive', 'draft', true],
            ['draft', 'archive', 'archived', false],
            ['draft', 'unarchive', 'draft', false],
            ['published', 'publish', 'published', false],
            ['published', 'unarchive', 'draft', false],
            ['archived', 'publish', 'published', false],
            ['archived', 'archive', 'archived', false],
        ];
    }

    public function test_repeated_publish_is_rejected_and_html_conflicts_return_with_an_error(): void
    {
        $plan = Plan::factory()->draft()->create();
        $url = route('admin.plans.show', $plan);
        $this->actingAs(User::factory()->admin()->create())->post(route('admin.plans.publish', $plan))->assertRedirect($url);
        $this->from($url)->post(route('admin.plans.publish', $plan))->assertRedirect($url)->assertSessionHas('error');
        $this->assertSame(PlanStatus::Published, $plan->fresh()->status);
    }

    public function test_only_unreferenced_drafts_can_be_physically_deleted(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $draft = Plan::factory()->draft()->create();
        $this->delete(route('admin.plans.destroy', $draft))->assertRedirect(route('admin.plans.index'))
            ->assertSessionHas('success', 'プランを削除しました。');
        $this->assertDatabaseMissing('plans', ['id' => $draft->id]);
        $this->deleteJson(route('admin.plans.destroy', $draft))->assertNotFound();
        foreach ([PlanStatus::Published, PlanStatus::Archived] as $status) {
            $plan = Plan::factory()->create(['status' => $status]);
            $this->deleteJson(route('admin.plans.destroy', $plan))->assertConflict();
            $this->assertDatabaseHas('plans', ['id' => $plan->id]);
            $show = route('admin.plans.show', $plan);
            $this->from($show)->delete(route('admin.plans.destroy', $plan))->assertRedirect($show)->assertSessionHas('error');
        }
    }

    /** @dataProvider userStatuses */
    public function test_any_linked_user_prevents_deletion_regardless_of_the_contract_count(string $status): void
    {
        $plan = Plan::factory()->draft()->create();
        $user = User::factory()->student()->withPlan($plan)->create(['status' => $status]);
        $before = $user->fresh()->getAttributes();
        $this->actingAs(User::factory()->admin()->create())->deleteJson(route('admin.plans.destroy', $plan))->assertConflict();
        $this->assertDatabaseHas('plans', ['id' => $plan->id]);
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public static function userStatuses(): array
    {
        return array_map(fn ($status) => [$status->value], UserStatus::cases());
    }

    public function test_soft_deleted_users_and_historical_assignments_are_not_orphaned(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $plan = Plan::factory()->draft()->create();
        $user = User::factory()->withPlan($plan)->create();
        $user->delete();
        $this->deleteJson(route('admin.plans.destroy', $plan))->assertConflict();
        $this->assertSoftDeleted('users', ['id' => $user->id, 'plan_id' => $plan->id]);
        $oldPlan = Plan::factory()->draft()->create();
        $log = UserPlanLog::factory()->for($oldPlan, 'plan')->for($user, 'user')->create();
        $before = $log->fresh()->getAttributes();
        $this->deleteJson(route('admin.plans.destroy', $oldPlan))->assertConflict();
        $this->assertSame($before, $log->fresh()->getAttributes());
        $this->assertDatabaseHas('plans', ['id' => $oldPlan->id]);
    }

    public function test_edit_and_archive_preserve_existing_contract_snapshots_and_history(): void
    {
        $plan = Plan::factory()->published()->create();
        $user = User::factory()->inProgress()->withPlan($plan)->create();
        $log = UserPlanLog::factory()->for($plan)->for($user)->create();
        $userBefore = $user->fresh()->getAttributes();
        $logBefore = $log->fresh()->getAttributes();
        $this->actingAs(User::factory()->admin()->create());
        $this->get(route('admin.users.index'))->assertViewHas('inviteFormPlans', fn ($p) => $p->contains('id', $plan->id));
        $this->put(route('admin.plans.update', $plan), array_replace($this->payload(), [
            'duration_days' => 99, 'default_meeting_quota' => 33,
        ]))->assertRedirect();
        $this->assertSame(PlanStatus::Published, $plan->fresh()->status);
        $this->post(route('admin.plans.archive', $plan))->assertRedirect();
        $this->get(route('admin.users.index'))->assertViewHas('inviteFormPlans', fn ($p) => ! $p->contains('id', $plan->id));
        $this->get(route('admin.users.show', $user))->assertViewHas('plans', fn ($p) => ! $p->contains('id', $plan->id));
        $this->assertSame($userBefore, $user->fresh()->getAttributes());
        $this->assertSame($logBefore, $log->fresh()->getAttributes());
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public function test_index_filters_names_and_status_and_paginates_twenty_in_existing_order(): void
    {
        for ($i = 0; $i < 23; $i++) {
            Plan::factory()->draft()->create(['name' => sprintf('対象プラン%02d', $i), 'sort_order' => $i]);
        }
        Plan::factory()->published()->create(['name' => '対象公開プラン']);
        Plan::factory()->draft()->create(['name' => '無関係', 'description' => '対象プラン']);
        $this->actingAs(User::factory()->admin()->create());
        $url = route('admin.plans.index', ['keyword' => '対象', 'status' => 'draft']);
        $response = $this->get($url)->assertOk()->assertDontSee('対象公開プラン')->assertDontSee('無関係');
        $plans = $response->viewData('plans');
        $this->assertSame(20, $plans->perPage());
        $this->assertSame(23, $plans->total());
        $this->assertSame(range(0, 19), $plans->pluck('sort_order')->all());
        $this->assertStringContainsString('keyword=', $plans->nextPageUrl());
        $this->assertStringContainsString('status=draft', $plans->nextPageUrl());
        $page2 = $this->get($url.'&page=2')->assertOk()->viewData('plans');
        $this->assertSame([20, 21, 22], $page2->pluck('sort_order')->all());
        $this->get('/admin/plans')->assertViewHas('plans', fn ($p) => $p->total() === 25);
        foreach (['draft' => 24, 'published' => 1, 'archived' => 0] as $status => $count) {
            $this->get(route('admin.plans.index', ['status' => $status]))->assertViewHas('plans', fn ($p) => $p->total() === $count);
        }
        foreach ([['status' => 'invalid'], ['keyword' => str_repeat('a', 101)], ['page' => 0], ['page' => 'bad']] as $filter) {
            $this->getJson(route('admin.plans.index', $filter))->assertUnprocessable();
        }
    }

    /** @dataProvider otherRoles */
    public function test_other_roles_and_guests_cannot_use_any_management_endpoint(?string $role): void
    {
        $plan = Plan::factory()->draft()->create();
        if ($role !== null) {
            $this->actingAs(User::factory()->create(['role' => $role]));
        }
        $base = '/admin/plans';
        foreach ([['GET', $base], ['GET', $base.'/create'], ['POST', $base], ['GET', $base.'/'.$plan->id],
            ['GET', $base.'/'.$plan->id.'/edit'], ['PUT', $base.'/'.$plan->id], ['PATCH', $base.'/'.$plan->id], ['DELETE', $base.'/'.$plan->id],
            ['POST', $base.'/'.$plan->id.'/publish'], ['POST', $base.'/'.$plan->id.'/archive'], ['POST', $base.'/'.$plan->id.'/unarchive']] as [$method, $url]) {
            $response = $this->call($method, $url, $this->payload());
            if ($role === null) {
                $response->assertRedirect('/login');
            } else {
                $response->assertForbidden();
            }
        }
        $this->assertDatabaseCount('plans', 1);
        $this->assertSame(PlanStatus::Draft, $plan->fresh()->status);
    }

    public static function otherRoles(): array
    {
        return [['student'], ['coach'], [null]];
    }

    private function payload(): array
    {
        return ['name' => '検証プラン', 'description' => '検証用', 'duration_days' => 30, 'default_meeting_quota' => 4];
    }
}
