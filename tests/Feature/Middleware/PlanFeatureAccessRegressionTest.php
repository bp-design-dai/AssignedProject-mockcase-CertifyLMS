<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\LearningSession;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanFeatureAccessRegressionTest extends TestCase
{
    use RefreshDatabase;

    /** @dataProvider planIndexes */
    public function test_graduated_students_cannot_access_plan_indexes_directly(string $route): void
    {
        $student = User::factory()->student()->graduated()->create();

        $this->actingAs($student)->get(route($route))->assertForbidden()
            ->assertSee('プラン期間が満了しました。');
    }

    /** @dataProvider planIndexes */
    public function test_in_progress_students_can_access_plan_indexes(string $route): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $this->actingAs($student)->get(route($route))->assertOk();
    }

    public static function planIndexes(): array
    {
        return [
            ['certifications.index'],
            ['learning.index'],
            ['mock-exam.fallback.index'],
            ['mock-exam-sessions.index'],
            ['meetings.index'],
            ['meetings.fallback.create'],
            ['chat.index'],
            ['qa-board.index'],
            ['meeting-quota.history'],
        ];
    }

    public function test_content_search_remains_available_only_while_in_progress(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        Enrollment::factory()->for($student)->for($certification)->learning()->create();
        $url = route('contents.search', ['certification_id' => $certification->id]);

        $this->actingAs($student)->get($url)->assertOk();
        $student->update(['status' => UserStatus::Graduated]);
        $this->actingAs($student->fresh())->get($url)->assertForbidden();
    }

    public function test_graduated_students_cannot_view_learning_levels_or_change_sessions(): void
    {
        $student = User::factory()->student()->graduated()->create();
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();
        $part = Part::factory()->forCertification($certification)->published()->create();
        $chapter = Chapter::factory()->forPart($part)->published()->create();
        $section = Section::factory()->forChapter($chapter)->published()->create();
        $session = LearningSession::factory()->forEnrollment($enrollment)->forSection($section)->open()->create();
        $before = $session->fresh()->getAttributes();

        $this->actingAs($student);
        foreach ([
            route('learning.enrollments.show', $enrollment),
            route('learning.parts.show', $part),
            route('learning.chapters.show', $chapter),
            route('learning.sections.show', $section),
            route('quiz.sections.show', $section),
            route('mock-exam.catalog.index', $enrollment),
        ] as $url) {
            $this->get($url)->assertForbidden();
        }

        $this->assertDatabaseCount('learning_sessions', 1);
        $this->assertSame($before, $session->fresh()->getAttributes());
    }

    public function test_graduated_students_cannot_modify_enrollment_or_plan_data(): void
    {
        $student = User::factory()->student()->graduated()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $newCertification = Certification::factory()->published()->create();
        $beforeStudent = $student->fresh()->getAttributes();
        $beforeEnrollment = $enrollment->fresh()->getAttributes();

        $this->actingAs($student)
            ->post(route('enrollments.store'), ['certification_id' => $newCertification->id])->assertForbidden();
        $this->patch(route('enrollments.updateExamDate', $enrollment), [
            'exam_date' => now()->addDays(10)->toDateString(),
        ])->assertForbidden();
        $this->delete(route('enrollments.destroy', $enrollment))->assertForbidden();
        $this->post(route('enrollments.receiveCertificate', $enrollment))->assertForbidden();

        $this->assertDatabaseCount('enrollments', 1);
        $this->assertDatabaseCount('user_plan_logs', 0);
        $this->assertDatabaseCount('user_status_logs', 0);
        $this->assertSame($beforeStudent, $student->fresh()->getAttributes());
        $this->assertSame($beforeEnrollment, $enrollment->fresh()->getAttributes());
    }

    public function test_graduated_students_keep_access_to_common_read_only_pages(): void
    {
        $student = User::factory()->student()->graduated()->create();
        $enrollment = Enrollment::factory()->for($student)->passed()->create();

        $this->actingAs($student)->get(route('dashboard.index'))->assertOk()->assertViewIs('dashboard.graduated');
        $this->get(route('enrollments.index'))->assertOk();
        $this->get(route('enrollments.show', $enrollment))->assertOk();
        $this->get(route('notifications.index'))->assertOk();
    }

    public function test_admin_management_permissions_are_not_affected_by_the_plan_guard(): void
    {
        $admin = User::factory()->admin()->graduated()->create();

        $this->actingAs($admin)->get(route('admin.certifications.index'))->assertOk();
    }

    public function test_assigned_coach_management_permissions_are_not_affected_by_the_plan_guard(): void
    {
        $coach = User::factory()->coach()->graduated()->create();
        $certification = Certification::factory()->published()->create();
        CertificationCoachAssignment::create([
            'user_id' => $coach->id,
            'certification_id' => $certification->id,
            'assigned_by_user_id' => $certification->created_by_user_id,
            'assigned_at' => now(),
        ]);

        $this->actingAs($coach)->get(route('admin.certifications.parts.index', $certification))->assertOk();
    }
}
