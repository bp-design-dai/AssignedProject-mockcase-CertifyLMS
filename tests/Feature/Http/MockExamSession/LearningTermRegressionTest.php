<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MockExamSession;

use App\Enums\MockExamSessionStatus;
use App\Enums\TermType;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\MockExam;
use App\Models\MockExamSession;
use App\Models\User;
use App\Services\TermJudgementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LearningTermRegressionTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('termCases')]
    public function test_status_selection_is_persisted_and_shared_by_both_pages(array $statuses, TermType $expected): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $initial = $expected === TermType::BasicLearning ? TermType::MockPractice : TermType::BasicLearning;
        $enrollment = $this->createEnrollment($student, $initial);
        $sessions = collect($statuses)->map(fn (MockExamSessionStatus $status) => $this->createSession($enrollment, $status));
        $sessionAttributes = $sessions->map(fn (MockExamSession $session) => $session->fresh()->getRawOriginal())->all();

        $term = DB::transaction(fn () => app(TermJudgementService::class)->recalculate($enrollment));

        $this->assertSame($expected, $term);
        $this->assertSame($expected, $enrollment->fresh()->current_term);
        $this->assertDatabaseHas('enrollments', ['id' => $enrollment->id, 'current_term' => $expected->value]);
        $this->assertSame($sessionAttributes, $sessions->map(fn (MockExamSession $session) => $session->fresh()->getRawOriginal())->all());
        $this->assertDisplayedTerm($student, $enrollment, $expected);
    }

    public static function termCases(): array
    {
        return [
            'no sessions' => [[], TermType::BasicLearning],
            'not started only' => [[MockExamSessionStatus::NotStarted], TermType::BasicLearning],
            'canceled only' => [[MockExamSessionStatus::Canceled], TermType::BasicLearning],
            'not started and canceled' => [[MockExamSessionStatus::NotStarted, MockExamSessionStatus::Canceled], TermType::BasicLearning],
            'in progress exists' => [[MockExamSessionStatus::InProgress], TermType::MockPractice],
            'submitted exists' => [[MockExamSessionStatus::Submitted], TermType::MockPractice],
            'graded exists' => [[MockExamSessionStatus::Graded], TermType::MockPractice],
            'mixed eligible and excluded sessions' => [[MockExamSessionStatus::NotStarted, MockExamSessionStatus::Canceled, MockExamSessionStatus::InProgress, MockExamSessionStatus::Submitted, MockExamSessionStatus::Graded], TermType::MockPractice],
            'multiple excluded sessions' => [[MockExamSessionStatus::NotStarted, MockExamSessionStatus::Canceled, MockExamSessionStatus::Canceled], TermType::BasicLearning],
        ];
    }

    #[DataProvider('eligibleStatuses')]
    public function test_canceling_one_pending_session_keeps_practice_when_an_eligible_session_remains(MockExamSessionStatus $status): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = $this->createEnrollment($student, TermType::MockPractice);
        $pending = $this->createSession($enrollment, MockExamSessionStatus::NotStarted);
        $remaining = $this->createSession($enrollment, $status);
        $remainingAttributes = $remaining->fresh()->getRawOriginal();

        $this->actingAs($student)->delete(route('mock-exam-sessions.destroy', $pending))
            ->assertRedirect(route('mock-exam-sessions.index'))
            ->assertSessionHas('success');

        $this->assertSame(MockExamSessionStatus::Canceled, $pending->fresh()->status);
        $this->assertNotNull($pending->fresh()->canceled_at);
        $this->assertSame($remainingAttributes, $remaining->fresh()->getRawOriginal());
        $this->assertSame(TermType::MockPractice, $enrollment->fresh()->current_term);
        $this->assertDisplayedTerm($student, $enrollment, TermType::MockPractice);
    }

    public function test_canceling_the_last_pending_session_ignores_active_sessions_for_another_certification(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $target = $this->createEnrollment($student, TermType::MockPractice);
        $other = $this->createEnrollment($student, TermType::MockPractice);
        $this->createSession($target, MockExamSessionStatus::Canceled);
        $pending = $this->createSession($target, MockExamSessionStatus::NotStarted);
        $otherSession = $this->createSession($other, MockExamSessionStatus::InProgress);
        $otherAttributes = $other->fresh()->getRawOriginal();
        $otherSessionAttributes = $otherSession->fresh()->getRawOriginal();

        $this->assertDisplayedTerm($student, $target, TermType::MockPractice);
        $this->actingAs($student)->delete(route('mock-exam-sessions.destroy', $pending))
            ->assertRedirect(route('mock-exam-sessions.index'));

        $this->assertSame(MockExamSessionStatus::Canceled, $pending->fresh()->status);
        $this->assertSame(TermType::BasicLearning, $target->fresh()->current_term);
        $this->assertSame($otherAttributes, $other->fresh()->getRawOriginal());
        $this->assertSame($otherSessionAttributes, $otherSession->fresh()->getRawOriginal());
        $this->assertDisplayedTerm($student, $target, TermType::BasicLearning);
        $this->assertDisplayedTerm($student, $other, TermType::MockPractice);
    }

    public function test_another_students_active_session_for_the_same_certification_is_not_counted(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $target = $this->createEnrollment($student, TermType::MockPractice);
        $otherStudent = User::factory()->student()->inProgress()->create();
        $other = Enrollment::factory()->for($otherStudent)->for($target->certification)->learning()
            ->create(['current_term' => TermType::MockPractice]);
        $this->createSession($other, MockExamSessionStatus::InProgress);
        $pending = $this->createSession($target, MockExamSessionStatus::NotStarted);

        $this->actingAs($student)->delete(route('mock-exam-sessions.destroy', $pending))
            ->assertRedirect(route('mock-exam-sessions.index'));

        $this->assertSame(TermType::BasicLearning, $target->fresh()->current_term);
        $this->assertSame(TermType::MockPractice, $other->fresh()->current_term);
        $this->assertDisplayedTerm($student, $target, TermType::BasicLearning);
    }

    public function test_another_student_cannot_cancel_or_recalculate_the_owners_term(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $enrollment = $this->createEnrollment($owner, TermType::MockPractice);
        $this->createSession($enrollment, MockExamSessionStatus::InProgress);
        $pending = $this->createSession($enrollment, MockExamSessionStatus::NotStarted);
        $sessionAttributes = $pending->fresh()->getRawOriginal();
        $enrollmentAttributes = $enrollment->fresh()->getRawOriginal();

        $this->actingAs(User::factory()->student()->inProgress()->create())
            ->deleteJson(route('mock-exam-sessions.destroy', $pending))->assertForbidden();

        $this->assertSame($sessionAttributes, $pending->fresh()->getRawOriginal());
        $this->assertSame($enrollmentAttributes, $enrollment->fresh()->getRawOriginal());
    }

    public function test_repeated_cancellation_does_not_change_the_basic_term_or_cancellation_record(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = $this->createEnrollment($student, TermType::MockPractice);
        $pending = $this->createSession($enrollment, MockExamSessionStatus::NotStarted);
        $this->actingAs($student)->delete(route('mock-exam-sessions.destroy', $pending))
            ->assertRedirect(route('mock-exam-sessions.index'));
        $sessionAttributes = $pending->fresh()->getRawOriginal();
        $enrollmentAttributes = $enrollment->fresh()->getRawOriginal();

        $this->deleteJson(route('mock-exam-sessions.destroy', $pending))->assertStatus(409);

        $this->assertSame($sessionAttributes, $pending->fresh()->getRawOriginal());
        $this->assertSame($enrollmentAttributes, $enrollment->fresh()->getRawOriginal());
        $this->assertSame(TermType::BasicLearning, $enrollment->fresh()->current_term);
        $this->assertDisplayedTerm($student, $enrollment, TermType::BasicLearning);
    }

    #[DataProvider('eligibleStatuses')]
    public function test_eligible_sessions_still_cannot_be_canceled(MockExamSessionStatus $status): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = $this->createEnrollment($student, TermType::MockPractice);
        $session = $this->createSession($enrollment, $status);
        $sessionAttributes = $session->fresh()->getRawOriginal();
        $enrollmentAttributes = $enrollment->fresh()->getRawOriginal();

        $this->actingAs($student)->deleteJson(route('mock-exam-sessions.destroy', $session))->assertStatus(409);

        $this->assertSame($sessionAttributes, $session->fresh()->getRawOriginal());
        $this->assertSame($enrollmentAttributes, $enrollment->fresh()->getRawOriginal());
        $this->assertDisplayedTerm($student, $enrollment, TermType::MockPractice);
    }

    public static function eligibleStatuses(): array
    {
        return [
            'in progress' => [MockExamSessionStatus::InProgress],
            'submitted' => [MockExamSessionStatus::Submitted],
            'graded' => [MockExamSessionStatus::Graded],
        ];
    }

    private function createEnrollment(User $student, TermType $term): Enrollment
    {
        $certification = Certification::factory()->published()->create();

        return Enrollment::factory()->for($student)->for($certification)->learning()->create(['current_term' => $term]);
    }

    private function createSession(Enrollment $enrollment, MockExamSessionStatus $status): MockExamSession
    {
        $exam = MockExam::factory()->forCertification($enrollment->certification)->published()->create();

        return MockExamSession::factory()->forEnrollment($enrollment)->forMockExam($exam)->create(['status' => $status]);
    }

    private function assertDisplayedTerm(User $student, Enrollment $enrollment, TermType $term): void
    {
        $this->actingAs($student)->get(route('dashboard.index'))
            ->assertOk()
            ->assertViewIs('dashboard.student')
            ->assertViewHas('viewModel', fn ($viewModel) => $viewModel->enrollmentCards->firstWhere('enrollmentId', $enrollment->id)?->currentTerm === $term)
            ->assertSeeText($term->label());
        $this->get(route('enrollments.show', $enrollment))
            ->assertOk()
            ->assertViewIs('enrollment.show')
            ->assertViewHas('enrollment', fn (Enrollment $shown) => $shown->id === $enrollment->id && $shown->current_term === $term)
            ->assertSeeText('現在ターム: '.$term->label());
    }
}
