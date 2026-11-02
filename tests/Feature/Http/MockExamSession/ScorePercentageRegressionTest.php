<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MockExamSession;

use App\Enums\MockExamSessionStatus;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\MockExam;
use App\Models\MockExamAnswer;
use App\Models\MockExamQuestion;
use App\Models\MockExamSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScorePercentageRegressionTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('percentageCases')]
    public function test_submission_uses_the_same_percentage_for_storage_pass_and_result(
        int $total,
        int $correct,
        int $unanswered,
        int $passingScore,
        string $expectedPercentage,
        bool $expectedPass,
    ): void {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();
        $exam = MockExam::factory()->forCertification($certification)->published()->passingScore($passingScore)->create();
        $questions = MockExamQuestion::factory()->forMockExam($exam)->withOptions(4, 0)->count($total)->create();
        $session = MockExamSession::factory()->forUser($student)->forEnrollment($enrollment)->forMockExam($exam)
            ->inProgress()->create([
                'generated_question_ids' => $questions->pluck('id')->all(),
                'total_questions' => $total,
                'passing_score_snapshot' => $passingScore,
            ]);

        foreach ($questions->take($total - $unanswered) as $index => $question) {
            $option = $question->options->firstWhere('is_correct', $index < $correct);
            MockExamAnswer::factory()->create([
                'mock_exam_session_id' => $session->id,
                'mock_exam_question_id' => $question->id,
                'selected_option_id' => $option->id,
                'selected_option_body' => $option->body,
                'is_correct' => false,
            ]);
        }

        $this->actingAs($student)->post(route('mock-exam-sessions.submit', $session))
            ->assertRedirect(route('mock-exam-sessions.show', $session))
            ->assertSessionHas('success');

        $session->refresh();
        $this->assertSame(MockExamSessionStatus::Graded, $session->status);
        $this->assertNotNull($session->submitted_at);
        $this->assertNotNull($session->graded_at);
        $this->assertSame($total, $session->total_questions);
        $this->assertSame($passingScore, $session->passing_score_snapshot);
        $this->assertSame($correct, $session->total_correct);
        $this->assertSame($expectedPercentage, $session->score_percentage);
        $this->assertSame($expectedPass, $session->pass);
        $this->assertSame($correct, $session->answers()->where('is_correct', true)->count());
        $this->assertSame($total - $unanswered, $session->answers()->count());
        $this->assertDatabaseHas('mock_exam_sessions', [
            'id' => $session->id,
            'score_percentage' => $expectedPercentage,
            'pass' => $expectedPass,
        ]);

        $displayedPercentage = rtrim(rtrim($expectedPercentage, '0'), '.');
        $this->get(route('mock-exam-sessions.show', $session))
            ->assertOk()
            ->assertViewIs('mock-exam-session.result')
            ->assertSeeText($displayedPercentage.'%')
            ->assertSeeText($expectedPass ? '合格点 突破' : '合格点未達')
            ->assertSeeText($correct.' / '.$total.' 正解');
    }

    public static function percentageCases(): array
    {
        return [
            'four of five is eighty percent' => [5, 4, 0, 60, '80.00', true],
            'perfect score meets the upper passing limit' => [5, 5, 0, 100, '100.00', true],
            'exact passing score is accepted' => [5, 3, 0, 60, '60.00', true],
            'below passing score fails' => [5, 2, 0, 60, '40.00', false],
            'all incorrect answers score zero' => [5, 0, 0, 1, '0.00', false],
            'percentage rounds down to two decimals' => [3, 1, 0, 50, '33.33', false],
            'percentage rounds up to two decimals' => [3, 2, 0, 66, '66.67', true],
            'rounded percentage remains below sixty seven' => [3, 2, 0, 67, '66.67', false],
            'nonperfect score fails a hundred percent threshold' => [5, 4, 0, 100, '80.00', false],
            'one percent meets the lower passing limit' => [100, 1, 0, 1, '1.00', true],
            'unanswered questions remain in the denominator' => [3, 1, 2, 50, '33.33', false],
            'all unanswered questions score zero' => [3, 0, 3, 1, '0.00', false],
            'zero questions does not divide by zero' => [0, 0, 0, 60, '0.00', false],
        ];
    }

    public function test_grading_does_not_recalculate_a_previous_result(): void
    {
        $previous = MockExamSession::factory()->graded()->create([
            'score_percentage' => '0.80',
            'pass' => false,
        ]);
        $previousAttributes = $previous->fresh()->getRawOriginal();
        $session = MockExamSession::factory()->inProgress()->create([
            'generated_question_ids' => [],
            'total_questions' => 0,
            'passing_score_snapshot' => 60,
        ]);

        $this->actingAs($session->user)->post(route('mock-exam-sessions.submit', $session))
            ->assertRedirect(route('mock-exam-sessions.show', $session));

        $this->assertSame($previousAttributes, $previous->fresh()->getRawOriginal());
        $this->assertSame('0.00', $session->fresh()->score_percentage);
    }
}
