<?php

declare(strict_types=1);

namespace Tests\Feature\Http\EnrollmentGoal;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use App\UseCases\Dashboard\FetchStudentDashboardAction;
use Database\Seeders\EnrollmentGoalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_detail_and_dashboard_share_due_date_then_undated_then_achievement_order(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->create();
        // 作成順・期日・達成順をあえて一致させない。
        $achievedOld = EnrollmentGoal::factory()->for($enrollment)->create(['title' => '達成 古', 'achieved_at' => now()->subDays(2), 'created_at' => now()]);
        $undated = EnrollmentGoal::factory()->for($enrollment)->withoutTargetDate()->create(['title' => '期日なし']);
        $far = EnrollmentGoal::factory()->for($enrollment)->create(['title' => '未来', 'target_date' => now()->addDays(4)]);
        $achievedNew = EnrollmentGoal::factory()->for($enrollment)->create(['title' => '達成 新', 'achieved_at' => now()->subDay(), 'created_at' => now()->subMonth()]);
        $near = EnrollmentGoal::factory()->for($enrollment)->create(['title' => '今日', 'target_date' => today()]);
        $expected = [$near->id, $far->id, $undated->id, $achievedNew->id, $achievedOld->id];
        $response = $this->actingAs($student)->get(route('enrollments.show', $enrollment))->assertOk();
        $this->assertSame($expected, $response->viewData('enrollment')->goals->modelKeys());
        $response->assertSeeInOrder(['今日', '未来', '期日なし', '達成 新', '達成 古']);
        $this->assertSame($expected, app(FetchStudentDashboardAction::class)($student)->goalTimeline->modelKeys());
        $this->get(route('dashboard.index'))->assertOk()->assertSee('個人目標');
    }

    public function test_equal_dates_are_stable_and_marking_and_unmarking_reorders_the_list(): void
    {
        $enrollment = Enrollment::factory()->for(User::factory()->student()->inProgress())->create();
        $old = EnrollmentGoal::factory()->for($enrollment)->create(['target_date' => today(), 'created_at' => now()->subDay()]);
        $new = EnrollmentGoal::factory()->for($enrollment)->create(['target_date' => today()]);
        $undated = EnrollmentGoal::factory()->for($enrollment)->withoutTargetDate()->create();
        $this->assertSame([$new->id, $old->id, $undated->id], $enrollment->goals()->pluck('id')->all());
        $this->actingAs($enrollment->user)->post(route('enrollment-goals.markAchieved', $new))->assertRedirect();
        $this->assertSame([$old->id, $undated->id, $new->id], $enrollment->goals()->pluck('id')->all());
        $this->delete(route('enrollment-goals.unmarkAchieved', $new))->assertRedirect();
        $this->assertSame([$new->id, $old->id, $undated->id], $enrollment->goals()->pluck('id')->all());
        $new->update(['created_at' => $old->created_at]);
        $this->assertSame(collect([$old->id, $new->id])->sortDesc()->values()->all(), $enrollment->goals()->limit(2)->pluck('id')->all());
    }

    public function test_each_enrollment_is_isolated_and_dashboard_excludes_other_owners_and_deleted_parents(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $a = Enrollment::factory()->for($student)->create();
        $b = Enrollment::factory()->for($student)->create();
        $own = EnrollmentGoal::factory()->for($a)->create(['title' => 'A目標']);
        $second = EnrollmentGoal::factory()->for($b)->create(['title' => 'B目標']);
        $other = EnrollmentGoal::factory()->create(['title' => '他人の秘密']);
        $response = $this->actingAs($student)->get(route('enrollments.show', $a))->assertOk()->assertSee($own->title)->assertDontSee($second->title)->assertDontSee($other->title);
        $this->assertSame([$own->id], $response->viewData('enrollment')->goals->modelKeys());
        $this->get(route('enrollments.index'))->assertViewHas('enrollments', fn ($rows) => $rows->firstWhere('id', $a->id)->goals_count === 1 && $rows->firstWhere('id', $b->id)->goals_count === 1);
        $ids = app(FetchStudentDashboardAction::class)($student)->goalTimeline->modelKeys();
        $this->assertEqualsCanonicalizing([$own->id, $second->id], $ids);
        $b->delete();
        $this->assertSame([$own->id], app(FetchStudentDashboardAction::class)($student)->goalTimeline->modelKeys());
    }

    public function test_foreign_key_and_soft_delete_cascade_only_remove_the_target_enrollments_goals(): void
    {
        $enrollment = Enrollment::factory()->for(User::factory()->student()->inProgress())->create();
        $goal = EnrollmentGoal::factory()->for($enrollment)->create();
        $other = EnrollmentGoal::factory()->create();
        $this->actingAs($enrollment->user)->delete(route('enrollments.destroy', $enrollment))->assertRedirect();
        $this->assertSoftDeleted('enrollments', ['id' => $enrollment->id]);
        $this->assertDatabaseMissing('enrollment_goals', ['id' => $goal->id]);
        $this->assertDatabaseHas('enrollment_goals', ['id' => $other->id]);
        // Query Builderの物理削除でもFKが保証する。
        DB::table('enrollments')->where('id', $other->enrollment_id)->delete();
        $this->assertDatabaseMissing('enrollment_goals', ['id' => $other->id]);
    }

    public function test_seed_is_repeatable_and_does_not_overwrite_existing_goals_or_achievement(): void
    {
        $enrollment = Enrollment::factory()->for(User::factory()->student()->inProgress())->create();
        $this->seed(EnrollmentGoalSeeder::class);
        $this->assertDatabaseCount('enrollment_goals', 3);
        $goal = $enrollment->goals()->where('title', 'デモ目標: 基礎を復習する')->sole();
        $goal->update(['description' => '手動変更を維持', 'achieved_at' => now()]);
        $before = EnrollmentGoal::query()->orderBy('id')->get()->toArray();
        $this->seed(EnrollmentGoalSeeder::class);
        $this->assertSame($before, EnrollmentGoal::query()->orderBy('id')->get()->toArray());
    }

    public function test_user_content_is_escaped_by_the_provided_views(): void
    {
        $enrollment = Enrollment::factory()->for(User::factory()->student()->inProgress())->create();
        EnrollmentGoal::factory()->for($enrollment)->create(['title' => '<script>alert(1)</script>', 'description' => '<img src=x onerror=alert(1)>']);
        $this->actingAs($enrollment->user)->get(route('enrollments.show', $enrollment))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }
}
