<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Dashboard;

use App\Enums\EnrollmentStatus;
use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\LearningSession;
use App\Models\User;
use App\UseCases\Dashboard\FetchCoachDashboardAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CoachAssignedStudentsRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function assign(User $coach, ?Certification $certification = null): Certification
    {
        $certification ??= Certification::factory()->published()->create();
        CertificationCoachAssignment::factory()->create(['user_id' => $coach->id, 'certification_id' => $certification->id]);

        return $certification;
    }

    private function queryCount(callable $operation): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $operation();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_dashboard_query_growth_stays_within_existing_budget_with_thirty_rows(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $certification = $this->assign($coach);
        Enrollment::factory()->for($certification)->learning()->count(2)->create();
        $this->actingAs($coach);
        $baseline = $this->queryCount(fn () => $this->get(route('dashboard.index'))->assertOk());
        Enrollment::factory()->for($certification)->learning()->count(28)->create();
        $scaled = $this->queryCount(fn () => $this->get(route('dashboard.index'))->assertOk());

        // 既存DashboardQueryCountTestと同じ増加許容幅で、表示行をさらに増やして確認する。
        $this->assertLessThanOrEqual($baseline + 3, $scaled, "2 rows: {$baseline}; 30 rows: {$scaled}");
    }

    public function test_rendering_assigned_student_rows_does_not_issue_additional_queries(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $certification = $this->assign($coach);
        Enrollment::factory()->for($certification)->learning()->count(3)->create();
        $rows = app(FetchCoachDashboardAction::class)($coach)->assignedEnrollments;

        $queries = $this->queryCount(function () use ($rows): void {
            $html = view('dashboard._partials.coach.assigned-students-list', ['enrollments' => $rows])->render();
            $this->assertStringContainsString('3 名', $html);
        });

        $this->assertSame(0, $queries);
    }

    public function test_latest_activity_is_scoped_to_each_enrollment_and_uses_start_time(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 26)->setTime(12, 0));
        $coach = User::factory()->coach()->inProgress()->create();
        $student = User::factory()->student()->inProgress()->create();
        $first = Enrollment::factory()->for($student)->for($this->assign($coach))->learning()->create();
        $second = Enrollment::factory()->for($student)->for($this->assign($coach))->passed()->create();
        $empty = Enrollment::factory()->for($this->assign($coach))->learning()->create();
        LearningSession::factory()->forEnrollment($first)->create(['started_at' => now()->subDays(2), 'ended_at' => now()->subMinute()]);
        LearningSession::factory()->forEnrollment($first)->open()->startedOn(now()->subHour())->create();
        LearningSession::factory()->forEnrollment($second)->startedOn(now()->subDay())->create();
        LearningSession::factory()->forEnrollment(Enrollment::factory()->for($student)->create())->open()->startedOn(now())->create();

        $rows = app(FetchCoachDashboardAction::class)($coach)->assignedEnrollments->keyBy('id');

        $this->assertCount(3, $rows);
        $this->assertSame(now()->subHour()->toDateTimeString(), (string) $rows[$first->id]->last_activity_at);
        $this->assertSame(now()->subDay()->toDateTimeString(), (string) $rows[$second->id]->last_activity_at);
        $this->assertNull($rows[$empty->id]->last_activity_at);
    }

    public function test_multiple_coaches_share_only_active_assignments_and_keep_enrollment_rows(): void
    {
        $firstCoach = User::factory()->coach()->inProgress()->create();
        $secondCoach = User::factory()->coach()->inProgress()->create();
        $shared = $this->assign($firstCoach);
        $this->assign($secondCoach, $shared);
        $firstOnly = $this->assign($firstCoach);
        $secondOnly = $this->assign($secondCoach);
        $revoked = Certification::factory()->published()->create();
        CertificationCoachAssignment::factory()->unassigned()->create(['user_id' => $firstCoach->id, 'certification_id' => $revoked->id]);
        $student = User::factory()->student()->inProgress()->create();
        $common = Enrollment::factory()->for($shared)->for($student)->learning()->create();
        $first = Enrollment::factory()->for($firstOnly)->for($student)->passed()->create();
        $second = Enrollment::factory()->for($secondOnly)->learning()->create();
        Enrollment::factory()->for($revoked)->learning()->create();
        Enrollment::factory()->for($shared)->failed()->create();
        Enrollment::factory()->for($shared)->learning()->create()->delete();

        $this->assertSame([$common->id, $first->id], app(FetchCoachDashboardAction::class)($firstCoach)->assignedEnrollments->modelKeys());
        $this->assertSame([$common->id, $second->id], app(FetchCoachDashboardAction::class)($secondCoach)->assignedEnrollments->modelKeys());
    }

    public function test_display_values_and_unsorted_order_match_the_existing_query(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $certification = $this->assign($coach);
        $older = Enrollment::factory()->for($certification)->passed()->create(['created_at' => now()->subMonth()]);
        Enrollment::factory()->for($certification)->learning()->create();
        LearningSession::factory()->forEnrollment($older)->startedOn(now()->subDays(3))->create();
        $expected = Enrollment::query()->whereIn('certification_id', $coach->coachingCertificationIds())
            ->whereIn('status', [EnrollmentStatus::Learning, EnrollmentStatus::Passed])->get()
            ->map(fn (Enrollment $row) => [$row->id, $row->user->name, $row->certification->name, $row->current_term, $row->status, $row->learningSessions()->max('started_at')])->all();

        $response = $this->actingAs($coach)->get(route('dashboard.index'))->assertOk();
        $actual = $response->viewData('viewModel')->assignedEnrollments
            ->map(fn (Enrollment $row) => [$row->id, $row->user->name, $row->certification->name, $row->current_term, $row->status, $row->last_activity_at])->all();

        $this->assertSame($expected, $actual);
        $response->assertSee('記録なし')->assertSee('最終活動');
    }

    public function test_query_parameters_do_not_expand_or_change_the_existing_dashboard_scope(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $mine = Enrollment::factory()->for($this->assign($coach))->learning()->create();
        $outside = Enrollment::factory()->learning()->create();

        $response = $this->actingAs($coach)->get(route('dashboard.index', ['certification_id' => $outside->certification_id, 'q' => $outside->user->name, 'status' => 'failed', 'page' => 2]))->assertOk();

        $this->assertSame([$mine->id], $response->viewData('viewModel')->assignedEnrollments->modelKeys());
        $response->assertDontSee($outside->user->name);
    }

    public function test_no_assignments_keeps_the_existing_empty_state(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        Enrollment::factory()->learning()->create();

        $response = $this->actingAs($coach)->get(route('dashboard.index'))->assertOk();

        $this->assertCount(0, $response->viewData('viewModel')->assignedEnrollments);
        $response->assertSee('担当資格にまだ受講生がいません');
    }
}
