<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CoachScopeRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_coaches_see_only_enrollments_in_their_assigned_certifications(): void
    {
        $firstCoach = User::factory()->coach()->inProgress()->create();
        $secondCoach = User::factory()->coach()->inProgress()->create();
        $first = $this->assignCertification($firstCoach);
        $second = $this->assignCertification($secondCoach);
        $firstEnrollment = Enrollment::factory()->for($first)->create();
        $secondEnrollment = Enrollment::factory()->for($second)->create();
        Enrollment::factory()->create();

        $this->assertListedIds($this->indexAs($firstCoach), [$firstEnrollment->id]);
        $this->assertListedIds($this->indexAs($secondCoach), [$secondEnrollment->id]);
    }

    public function test_coach_without_assignments_receives_an_empty_list(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        Enrollment::factory()->count(2)->create();

        $response = $this->indexAs($coach);

        $this->assertListedIds($response, []);
        $this->assertCount(0, $response->viewData('certifications'));
        $response->assertSee('該当する受講登録がありません');
    }

    public function test_multiple_assignments_preserve_enrollment_rows_without_exposing_other_certifications(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $student = User::factory()->student()->inProgress()->create();
        $first = $this->assignCertification($coach);
        $second = $this->assignCertification($coach);
        $ownFirst = Enrollment::factory()->for($first)->for($student)->create();
        $ownSecond = Enrollment::factory()->for($second)->for($student)->create();
        Enrollment::factory()->for($student)->create();

        $this->assertListedIds($this->indexAs($coach), [$ownFirst->id, $ownSecond->id]);
    }

    public function test_shared_certification_is_visible_to_both_coaches_without_duplicate_rows(): void
    {
        $firstCoach = User::factory()->coach()->inProgress()->create();
        $secondCoach = User::factory()->coach()->inProgress()->create();
        $shared = $this->assignCertification($firstCoach);
        $this->assignCertification($secondCoach, $shared);
        $enrollment = Enrollment::factory()->for($shared)->create();

        $this->assertListedIds($this->indexAs($firstCoach), [$enrollment->id]);
        $this->assertListedIds($this->indexAs($secondCoach), [$enrollment->id]);
    }

    public function test_revoked_assignment_is_not_treated_as_an_active_assignment(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $active = $this->assignCertification($coach);
        $revoked = Certification::factory()->published()->create();
        CertificationCoachAssignment::factory()->unassigned()->create([
            'user_id' => $coach->id,
            'certification_id' => $revoked->id,
        ]);
        $visible = Enrollment::factory()->for($active)->create();
        Enrollment::factory()->for($revoked)->create();

        $response = $this->indexAs($coach);

        $this->assertListedIds($response, [$visible->id]);
        $this->assertSame([$active->id], $response->viewData('certifications')->modelKeys());
    }

    public function test_forged_certification_filter_cannot_expand_the_coach_scope(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $assigned = $this->assignCertification($coach);
        Enrollment::factory()->for($assigned)->create();
        $outside = Enrollment::factory()->create();

        $response = $this->indexAs($coach, ['certification_id' => $outside->certification_id]);

        $this->assertListedIds($response, []);
        $response->assertDontSee($outside->user->email);
    }

    public function test_keyword_status_and_certification_filters_remain_within_assigned_scope(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $first = $this->assignCertification($coach);
        $second = $this->assignCertification($coach);
        $nameMatch = User::factory()->student()->create(['name' => 'scope-match learner']);
        $emailMatch = User::factory()->student()->create(['email' => 'scope-match@example.test']);
        $visible = Enrollment::factory()->for($first)->for($nameMatch)->learning()->create();
        Enrollment::factory()->for($first)->for($emailMatch)->passed()->create();
        Enrollment::factory()->for($second)->for($nameMatch)->learning()->create();
        $outside = Enrollment::factory()->for($emailMatch)->learning()->create();

        $response = $this->indexAs($coach, [
            'keyword' => 'scope-match',
            'status' => EnrollmentStatus::Learning->value,
            'certification_id' => $first->id,
        ]);

        $this->assertListedIds($response, [$visible->id]);
        $response->assertDontSee(route('enrollments.show', $outside));

        $outsideSearch = $this->indexAs($coach, ['keyword' => $emailMatch->email]);
        $this->assertSame(1, $outsideSearch->viewData('enrollments')->total());
        $this->assertSame($first->id, $outsideSearch->viewData('enrollments')->first()->certification_id);
    }

    public function test_pagination_totals_sort_order_and_query_strings_exclude_outside_enrollments(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $assigned = $this->assignCertification($coach);
        $visible = collect(range(1, 21))->map(fn (int $day) => Enrollment::factory()->for($assigned)->learning()->create([
            'created_at' => now()->subDays($day),
        ]));
        Enrollment::factory()->learning()->count(4)->create(['created_at' => now()]);
        $filters = ['status' => EnrollmentStatus::Learning->value, 'certification_id' => $assigned->id];

        // 資格指定なしの一覧でも、件数・ページ境界に担当外行が混入しない。
        $unfiltered = $this->indexAs($coach);
        $this->assertSame(21, $unfiltered->viewData('enrollments')->total());
        $this->assertSame($visible->take(20)->pluck('id')->all(), $unfiltered->viewData('enrollments')->getCollection()->modelKeys());

        $firstPage = $this->indexAs($coach, $filters)->viewData('enrollments');
        $secondPage = $this->indexAs($coach, $filters + ['page' => 2])->viewData('enrollments');

        $this->assertSame(21, $firstPage->total());
        $this->assertSame(20, $firstPage->perPage());
        $this->assertSame(2, $firstPage->lastPage());
        $this->assertSame($visible->take(20)->pluck('id')->all(), $firstPage->getCollection()->modelKeys());
        $this->assertSame([$visible->last()->id], $secondPage->getCollection()->modelKeys());
        $this->assertStringContainsString('status=learning', $firstPage->nextPageUrl());
        $this->assertStringContainsString('certification_id='.$assigned->id, $firstPage->nextPageUrl());
    }

    public function test_coach_cannot_include_soft_deleted_enrollments_via_admin_filter(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $assigned = $this->assignCertification($coach);
        $visible = Enrollment::factory()->for($assigned)->create();
        Enrollment::factory()->for($assigned)->create()->delete();
        Enrollment::factory()->create();

        $this->assertListedIds($this->indexAs($coach, ['with_trashed' => 1]), [$visible->id]);
    }

    public function test_admin_still_sees_all_certifications_and_can_include_deleted_enrollments(): void
    {
        $admin = User::factory()->admin()->create();
        $first = Enrollment::factory()->create();
        $second = Enrollment::factory()->create();
        $deleted = Enrollment::factory()->create();
        $deleted->delete();

        $this->assertListedIds($this->indexAs($admin), [$first->id, $second->id]);
        $this->assertListedIds($this->indexAs($admin, ['with_trashed' => 1]), [$first->id, $second->id, $deleted->id]);
    }

    public function test_student_still_sees_only_own_enrollments_and_guest_cannot_access_list(): void
    {
        $this->get(route('enrollments.index'))->assertRedirect(route('login'));
        $student = User::factory()->student()->inProgress()->create();
        $own = Enrollment::factory()->for($student)->create();
        Enrollment::factory()->create();

        $response = $this->indexAs($student);

        $this->assertSame([$own->id], $response->viewData('enrollments')->modelKeys());
    }

    public function test_existing_detail_authorization_still_allows_assigned_and_rejects_outside_enrollments(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $assigned = $this->assignCertification($coach);
        $visible = Enrollment::factory()->for($assigned)->create();
        $outside = Enrollment::factory()->create();

        $this->actingAs($coach)->get(route('enrollments.show', $visible))->assertOk();
        $this->actingAs($coach)->get(route('enrollments.show', $outside))->assertForbidden();
    }

    private function assignCertification(User $coach, ?Certification $certification = null): Certification
    {
        $certification ??= Certification::factory()->published()->create();
        CertificationCoachAssignment::factory()->create([
            'user_id' => $coach->id,
            'certification_id' => $certification->id,
        ]);

        return $certification;
    }

    private function indexAs(User $user, array $filters = []): TestResponse
    {
        return $this->actingAs($user)->get(route('enrollments.index', $filters))->assertOk();
    }

    private function assertListedIds(TestResponse $response, array $expectedIds): void
    {
        $enrollments = $response->viewData('enrollments');

        $this->assertSame(count($expectedIds), $enrollments->total());
        $this->assertEqualsCanonicalizing($expectedIds, $enrollments->getCollection()->modelKeys());
    }
}
