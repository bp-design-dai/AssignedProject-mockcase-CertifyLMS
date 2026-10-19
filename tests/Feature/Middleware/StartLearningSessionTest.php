<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\LearningSession;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StartLearningSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', 'start-learning-session'])
            ->get('/_test/learning-response/{section}/{status}', fn (Section $section, string $status) => response('Test response', (int) $status));
    }

    /** @dataProvider unsuccessfulStatuses */
    public function test_non_successful_response_does_not_create_or_change_learning_sessions(int $status): void
    {
        [$student, $section, $openSession] = $this->makeOpenSession();
        $original = $openSession->getAttributes();

        $this->actingAs($student)
            ->get('/_test/learning-response/'.$section->id.'/'.$status)
            ->assertStatus($status);

        $this->assertDatabaseCount('learning_sessions', 1);
        $this->assertEquals($original, $openSession->fresh()->getAttributes());
        $this->assertNull($openSession->fresh()->ended_at);
    }

    public static function unsuccessfulStatuses(): array
    {
        return [
            'redirect' => [302],
            'unauthorized' => [401],
            'forbidden' => [403],
            'not found' => [404],
            'expired' => [419],
            'validation error' => [422],
            'server error' => [500],
        ];
    }

    public function test_successful_response_starts_a_session_and_closes_the_previous_one(): void
    {
        [$student, $section, $openSession] = $this->makeOpenSession();

        $this->actingAs($student)
            ->get('/_test/learning-response/'.$section->id.'/200')
            ->assertOk();

        $this->assertDatabaseCount('learning_sessions', 2);
        $this->assertDatabaseHas('learning_sessions', [
            'user_id' => $student->id,
            'section_id' => $section->id,
            'ended_at' => null,
        ]);
        $closedSession = $openSession->fresh();
        $this->assertTrue($closedSession->auto_closed);
        $this->assertNotNull($closedSession->ended_at);
        $this->assertGreaterThan(0, $closedSession->duration_seconds);
        $this->assertLessThanOrEqual((int) config('learning.max_session_seconds'), $closedSession->duration_seconds);
    }

    /** @return array{User, Section, LearningSession} */
    private function makeOpenSession(): array
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();
        $part = Part::factory()->forCertification($certification)->published()->create();
        $chapter = Chapter::factory()->forPart($part)->published()->create();
        $section = Section::factory()->forChapter($chapter)->published()->create();
        $previousSection = Section::factory()->forChapter($chapter)->published()->create();
        $openSession = LearningSession::factory()->forEnrollment($enrollment)->forSection($previousSection)->open()->create();

        return [$student, $section, $openSession];
    }
}
