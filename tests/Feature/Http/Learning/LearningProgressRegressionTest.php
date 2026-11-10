<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Learning;

use App\Models\User;
use App\Services\LearningProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\LearningProgressFixture;
use Tests\TestCase;

class LearningProgressRegressionTest extends TestCase
{
    use RefreshDatabase;

    /** @dataProvider scenarios */
    public function test_existing_progress_values_and_views_are_preserved(string $scenario): void
    {
        $f = LearningProgressFixture::create($scenario);
        $expected = LearningProgressFixture::expected($scenario);
        $staff = $this->actingAs(User::factory()->admin()->create())->get(route('enrollments.show', $f['enrollment']))->assertOk();
        $staffSummary = get_object_vars($staff->viewData('progress'));
        $this->assertSame($expected, $staffSummary);
        $learning = $this->actingAs($f['student'])->get(route('learning.enrollments.show', $f['enrollment']))->assertOk();
        $learningSummary = get_object_vars($learning->viewData('progress'));
        $this->assertSame($expected, $learningSummary);
        $dashboard = $this->get(route('dashboard.index'))->assertOk();
        $ratio = $dashboard->viewData('viewModel')->enrollmentCards->firstWhere('enrollmentId', $f['enrollment']->id)->progressRatio;
        $this->assertSame($expected['overallCompletionRatio'], $ratio);
        $chapterValues = [];
        foreach ($f['parts'] as $part) {
            $response = $this->get(route('learning.parts.show', $part))->assertOk();
            foreach ($response->viewData('chapters') as $chapter) {
                $values = $f['chapterDisplay'][$chapter->id];
                $this->assertSame($values['total'], (int) $chapter->sections_total_count);
                $this->assertSame($values['done'], (int) ($response->viewData('completedByChapter')[$chapter->id] ?? 0));
                if ($values['total'] > 0 && ! $values['isCompleted']) {
                    $response->assertSee('width: '.$values['percent'].'%', false);
                } else {
                    $response->assertSee($values['isCompleted'] ? '読了済' : 'Section なし');
                }
                $chapterValues[] = $values;
            }
        }
        if ($path = getenv('TA03_COMPARISON_REPORT')) {
            $this->assertStringStartsWith('/tmp/ta03-', $path);
            file_put_contents($path, json_encode(compact('scenario', 'staffSummary', 'learningSummary', 'ratio', 'chapterValues'))."\n", FILE_APPEND | LOCK_EX);
        }
    }

    public function test_student_detail_still_has_no_staff_summary_and_assigned_coach_can_view_it(): void
    {
        $f = LearningProgressFixture::create('partial');
        $detail = $this->actingAs($f['student'])->get(route('enrollments.show', $f['enrollment']))->assertOk();
        $this->assertNull($detail->viewData('progress'));
        $coach = User::factory()->coach()->inProgress()->create();
        $f['certification']->coaches()->attach($coach->id, ['id' => (string) Str::ulid(), 'assigned_by_user_id' => User::factory()->admin()->create()->id, 'assigned_at' => now(), 'unassigned_at' => null]);
        $response = $this->actingAs($coach)->get(route('enrollments.show', $f['enrollment']))->assertOk();
        $this->assertSame(LearningProgressFixture::expected('partial'), get_object_vars($response->viewData('progress')));
    }

    public function test_read_and_unread_operations_keep_redirects_and_update_all_progress_views(): void
    {
        $f = LearningProgressFixture::create('unread');
        $section = $f['sections'][0];
        $this->actingAs($f['student'])->post(route('learning.sections.markRead', $section))->assertRedirect(route('learning.sections.show', $section));
        $learning = $this->get(route('learning.enrollments.show', $f['enrollment']))->assertOk();
        $this->assertSame(LearningProgressFixture::expected('partial'), get_object_vars($learning->viewData('progress')));
        $dashboard = $this->get(route('dashboard.index'))->assertOk();
        $this->assertSame(0.3333, $dashboard->viewData('viewModel')->enrollmentCards->sole()->progressRatio);
        $this->delete(route('learning.sections.unmarkRead', $section))->assertRedirect(route('learning.sections.show', $section));
        $learning = $this->get(route('learning.enrollments.show', $f['enrollment']))->assertOk();
        $this->assertSame(LearningProgressFixture::expected('unread'), get_object_vars($learning->viewData('progress')));
        $dashboard = $this->get(route('dashboard.index'))->assertOk();
        $this->assertSame(0.0, $dashboard->viewData('viewModel')->enrollmentCards->sole()->progressRatio);
    }

    public function test_other_student_and_unassigned_coach_cannot_access_progress(): void
    {
        $f = LearningProgressFixture::create('partial');
        $this->actingAs(User::factory()->student()->inProgress()->create())->get(route('learning.enrollments.show', $f['enrollment']))->assertForbidden();
        $this->get(route('enrollments.show', $f['enrollment']))->assertForbidden();
        $this->actingAs(User::factory()->coach()->inProgress()->create())->get(route('enrollments.show', $f['enrollment']))->assertForbidden();
    }

    public function test_dashboard_preserves_null_progress_fallback_when_service_fails(): void
    {
        $f = LearningProgressFixture::create('partial');
        $service = \Mockery::mock(LearningProgressService::class);
        $service->shouldReceive('completionRatiosForEnrollments')->once()->andThrow(new \RuntimeException('Synthetic aggregate failure'));
        $this->app->instance(LearningProgressService::class, $service);
        $response = $this->actingAs($f['student'])->get(route('dashboard.index'))->assertOk();
        $this->assertNull($response->viewData('viewModel')->enrollmentCards->sole()->progressRatio);
    }

    public static function scenarios(): array
    {
        return LearningProgressFixture::scenarios();
    }
}
