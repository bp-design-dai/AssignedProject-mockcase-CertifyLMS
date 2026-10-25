<?php

declare(strict_types=1);

namespace Tests\Feature\Http\EnrollmentGoal;

use App\Enums\UserStatus;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_edit_complete_uncomplete_and_physically_delete(): void
    {
        $this->travelTo(Carbon::parse('2026-10-25 10:00:00', config('app.timezone')));
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->create();
        $this->actingAs($student);
        $this->get(route('enrollments.show', $enrollment))->assertOk()->assertSee('目標を追加');
        $this->post(route('enrollments.goals.store', $enrollment), [
            'title' => '今日の目標', 'description' => '詳細', 'target_date' => '2026-10-25',
            'enrollment_id' => 'untrusted', 'achieved_at' => now()->toDateTimeString(),
        ])->assertRedirect(route('enrollments.show', $enrollment))->assertSessionHas('success', '目標を追加しました。');
        $goal = $enrollment->goals()->sole();
        $this->assertNull($goal->achieved_at);
        $this->assertSame('2026-10-25', $goal->target_date->toDateString());
        $this->get(route('enrollment-goals.edit', $goal))->assertOk()->assertSee('今日の目標');
        $this->patch(route('enrollment-goals.update', $goal), [
            'title' => '更新目標', 'description' => null, 'target_date' => null,
        ])->assertRedirect(route('enrollments.show', $enrollment))->assertSessionHas('success', '目標を更新しました。');
        $this->assertDatabaseHas('enrollment_goals', ['id' => $goal->id, 'title' => '更新目標', 'description' => null, 'target_date' => null]);
        $this->post(route('enrollment-goals.markAchieved', $goal))->assertRedirect(route('enrollments.show', $enrollment))->assertSessionHas('success');
        $this->assertTrue($goal->fresh()->isAchieved());
        $achieved = $goal->fresh()->achieved_at->toDateTimeString();
        $this->travel(2)->hours();
        $this->post(route('enrollment-goals.markAchieved', $goal))->assertRedirect();
        $this->assertSame($achieved, $goal->fresh()->achieved_at->toDateTimeString());
        $this->delete(route('enrollment-goals.unmarkAchieved', $goal))->assertRedirect()->assertSessionHas('success');
        $this->assertFalse($goal->fresh()->isAchieved());
        $this->delete(route('enrollment-goals.unmarkAchieved', $goal))->assertRedirect();
        $this->delete(route('enrollment-goals.destroy', $goal))->assertRedirect(route('enrollments.show', $enrollment))->assertSessionHas('success', '目標を削除しました。');
        $this->assertDatabaseMissing('enrollment_goals', ['id' => $goal->id]);
        $this->delete(route('enrollment-goals.destroy', $goal))->assertNotFound();
    }

    /** @dataProvider validDates */
    public function test_today_future_and_absent_deadlines_are_accepted(?string $date): void
    {
        $this->travelTo(Carbon::parse('2026-10-25 00:05:00', 'Asia/Tokyo'));
        $enrollment = Enrollment::factory()->for(User::factory()->student()->inProgress())->create();
        $this->actingAs($enrollment->user);
        $data = ['title' => str_repeat('あ', 100), 'description' => str_repeat('い', 1000), 'target_date' => $date];
        $this->post(route('enrollments.goals.store', $enrollment), $data)->assertRedirect()->assertSessionHasNoErrors();
        $goal = $enrollment->goals()->sole();
        $this->assertSame($date, $goal->target_date?->toDateString());
        $this->patch(route('enrollment-goals.update', $goal), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($date, $goal->fresh()->target_date?->toDateString());
    }

    public static function validDates(): array
    {
        return [['2026-10-25'], ['2026-10-26'], [null]];
    }

    /** @dataProvider invalidInputs */
    public function test_invalid_input_is_rejected_without_writes(array $invalid, string $field): void
    {
        $this->travelTo(Carbon::parse('2026-10-25 00:05:00', 'Asia/Tokyo'));
        $enrollment = Enrollment::factory()->for(User::factory()->student()->inProgress())->create();
        $goal = EnrollmentGoal::factory()->for($enrollment)->achieved()->create();
        $before = $goal->fresh()->getAttributes();
        $this->actingAs($enrollment->user);
        $data = array_replace(['title' => '変更', 'target_date' => null], $invalid);
        $this->postJson(route('enrollments.goals.store', $enrollment), $data)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->patchJson(route('enrollment-goals.update', $goal), $data)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('enrollment_goals', 1);
        $this->assertSame($before, $goal->fresh()->getAttributes());
    }

    public static function invalidInputs(): array
    {
        return [
            [['title' => ''], 'title'], [['title' => null], 'title'], [['title' => []], 'title'],
            [['title' => str_repeat('あ', 101)], 'title'],
            [['description' => str_repeat('あ', 1001)], 'description'], [['description' => []], 'description'],
            [['target_date' => '2026-10-24'], 'target_date'], [['target_date' => '2026-02-30'], 'target_date'],
            [['target_date' => 'not-a-date'], 'target_date'], [['target_date' => []], 'target_date'],
        ];
    }

    public function test_optional_fields_can_be_omitted_and_basic_edits_preserve_achievement_and_parent(): void
    {
        $enrollment = Enrollment::factory()->for(User::factory()->student()->inProgress())->create();
        $other = Enrollment::factory()->create();
        $this->actingAs($enrollment->user);
        $this->post(route('enrollments.goals.store', $enrollment), ['title' => '最小入力'])->assertRedirect();
        $goal = $enrollment->goals()->sole();
        $this->assertNull($goal->target_date);
        $this->assertNull($goal->description);
        $goal->update(['achieved_at' => now()]);
        $before = $goal->fresh()->achieved_at;
        $this->patch(route('enrollment-goals.update', $goal), [
            'title' => '編集', 'enrollment_id' => $other->id, 'achieved_at' => null,
        ])->assertRedirect();
        $this->assertSame($enrollment->id, $goal->fresh()->enrollment_id);
        $this->assertTrue($before->equalTo($goal->fresh()->achieved_at));
    }

    /** @dataProvider forbiddenActors */
    public function test_only_active_owner_can_use_all_six_endpoints(string $actor): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($owner)->create();
        $goal = EnrollmentGoal::factory()->for($enrollment)->create();
        $user = match ($actor) {
            'admin' => User::factory()->admin()->create(),
            'coach' => User::factory()->coach()->create(),
            'other' => User::factory()->student()->inProgress()->create(),
            default => $owner,
        };
        if (in_array($actor, ['invited', 'graduated', 'withdrawn'], true)) {
            $user->update(['status' => UserStatus::from($actor)]);
        }
        $before = $goal->fresh()->getAttributes();
        $this->actingAs($user);
        $this->postJson(route('enrollments.goals.store', $enrollment), ['title' => '不正'])->assertForbidden();
        $this->get(route('enrollment-goals.edit', $goal))->assertForbidden();
        $this->patchJson(route('enrollment-goals.update', $goal), ['title' => '不正'])->assertForbidden();
        $this->deleteJson(route('enrollment-goals.destroy', $goal))->assertForbidden();
        $this->postJson(route('enrollment-goals.markAchieved', $goal))->assertForbidden();
        $this->deleteJson(route('enrollment-goals.unmarkAchieved', $goal))->assertForbidden();
        $this->assertSame($before, $goal->fresh()->getAttributes());
        $this->assertDatabaseCount('enrollment_goals', 1);
    }

    public static function forbiddenActors(): array
    {
        return array_map(fn ($actor) => [$actor], ['admin', 'coach', 'other', 'invited', 'graduated', 'withdrawn']);
    }

    public function test_guests_are_redirected_from_every_endpoint(): void
    {
        $goal = EnrollmentGoal::factory()->create();
        foreach ([
            ['post', route('enrollments.goals.store', $goal->enrollment)], ['get', route('enrollment-goals.edit', $goal)],
            ['patch', route('enrollment-goals.update', $goal)], ['delete', route('enrollment-goals.destroy', $goal)],
            ['post', route('enrollment-goals.markAchieved', $goal)], ['delete', route('enrollment-goals.unmarkAchieved', $goal)],
        ] as [$method, $url]) {
            $this->{$method}($url)->assertRedirect(route('login'));
        }
    }

    public function test_staff_can_only_view_authorized_goals_without_controls(): void
    {
        $enrollment = Enrollment::factory()->for(User::factory()->student()->inProgress())->create();
        $goal = EnrollmentGoal::factory()->for($enrollment)->create(['title' => '本人の秘密目標']);
        $coach = User::factory()->coach()->create();
        $assignment = CertificationCoachAssignment::factory()->create(['certification_id' => $enrollment->certification_id, 'user_id' => $coach->id]);
        foreach ([$coach, User::factory()->admin()->create()] as $viewer) {
            $this->actingAs($viewer)->get(route('enrollments.show', $enrollment))->assertOk()
                ->assertSee($goal->title)->assertDontSee('目標を追加')->assertDontSee(route('enrollment-goals.edit', $goal), false);
        }
        $assignment->update(['unassigned_at' => now()]);
        $this->actingAs($coach->fresh())->get(route('enrollments.show', $enrollment))->assertForbidden();
        $this->actingAs(User::factory()->coach()->create())->get(route('enrollments.show', $enrollment))->assertForbidden();
        $this->actingAs(User::factory()->student()->inProgress()->create())->get(route('enrollments.show', $enrollment))->assertForbidden();
    }

    public function test_inactive_owner_and_deleted_enrollment_never_expose_goal_data(): void
    {
        $enrollment = Enrollment::factory()->for(User::factory()->student()->inProgress())->create();
        $goal = EnrollmentGoal::factory()->for($enrollment)->create(['title' => '非公開目標']);
        $enrollment->user->update(['status' => UserStatus::Graduated]);
        $this->actingAs($enrollment->user->fresh())->get(route('enrollments.show', $enrollment))->assertOk()->assertDontSee($goal->title);
        $enrollment->delete();
        $this->assertDatabaseMissing('enrollment_goals', ['id' => $goal->id]);
        $this->actingAs(User::factory()->admin()->create())->get(route('enrollments.show', $enrollment))->assertOk()->assertDontSee($goal->title);
    }

    public function test_overdue_goals_remain_visible_and_can_be_marked_without_resubmitting_date(): void
    {
        $enrollment = Enrollment::factory()->for(User::factory()->student()->inProgress())->create();
        $goal = EnrollmentGoal::factory()->for($enrollment)->create(['target_date' => now()->subDay()]);
        $this->actingAs($enrollment->user)->get(route('enrollments.show', $enrollment))->assertOk()->assertSee($goal->title);
        $this->post(route('enrollment-goals.markAchieved', $goal))->assertRedirect();
        $this->assertTrue($goal->fresh()->isAchieved());
    }
}
