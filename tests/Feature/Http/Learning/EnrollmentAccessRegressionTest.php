<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Learning;

use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\LearningSession;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use App\UseCases\Learning\ShowChapterAction;
use App\UseCases\Learning\ShowPartAction;
use App\UseCases\Learning\ShowSectionAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnrollmentAccessRegressionTest extends TestCase
{
    use RefreshDatabase;

    /** @dataProvider levelsAndEnrollments */
    public function test_direct_links_require_the_students_own_active_enrollment(string $level, string $state): void
    {
        $student = User::factory()->student()->inProgress()->create();
        [$certification, $part, $chapter, $section] = $this->content();
        $enrollment = null;
        if ($state !== 'missing') {
            $owner = $state === 'other_student' ? User::factory()->student()->inProgress()->create() : $student;
            $enrollment = Enrollment::factory()->for($owner)->for($certification)->create([
                'status' => $state === 'failed' ? 'failed' : ($state === 'passed' ? 'passed' : 'learning'),
            ]);
            if ($state === 'deleted') {
                $enrollment->delete();
            }
        }
        // 別資格の受講登録やデフォルト資格では対象教材の閲覧を許可しない。
        $otherEnrollment = Enrollment::factory()->for($student)->create();
        $student->update(['default_enrollment_id' => $otherEnrollment->id]);
        $beforeEnrollments = Enrollment::withTrashed()->orderBy('id')->get()->toArray();
        $beforeStudent = $student->fresh()->getAttributes();
        $allowed = in_array($state, ['learning', 'passed'], true);
        $resources = ['part' => $part, 'chapter' => $chapter, 'section' => $section];

        $response = $this->actingAs($student)
            ->get(route('learning.'.$level.'s.show', $resources[$level]))
            ->assertStatus($allowed ? 200 : 403)
            ->assertHeaderMissing('Location');

        if ($allowed) {
            $response->assertViewIs('learning.'.$level.'s.show');
        } else {
            $response->assertDontSee($part->title)->assertDontSee($chapter->title)->assertDontSee($section->body);
        }
        $this->assertSame($beforeEnrollments, Enrollment::withTrashed()->orderBy('id')->get()->toArray());
        $this->assertSame($beforeStudent, $student->fresh()->getAttributes());
        $this->assertDatabaseCount('section_progresses', 0);
        $this->assertDatabaseCount('learning_sessions', $allowed && $level === 'section' ? 1 : 0);
        if ($allowed && $level === 'section') {
            $this->assertDatabaseHas('learning_sessions', [
                'user_id' => $student->id,
                'enrollment_id' => $enrollment->id,
                'section_id' => $section->id,
                'ended_at' => null,
            ]);
        }
    }

    public static function levelsAndEnrollments(): array
    {
        $cases = [];
        foreach (['part', 'chapter', 'section'] as $level) {
            foreach (['learning', 'passed', 'missing', 'failed', 'deleted', 'other_student'] as $state) {
                $cases[$level.' '.$state] = [$level, $state];
            }
        }

        return $cases;
    }

    /** @dataProvider unpublishedCertifications */
    public function test_publication_checks_keep_returning_404_before_enrollment_checks(string $status, bool $registered): void
    {
        $student = User::factory()->student()->inProgress()->create();
        [$certification, $part, $chapter, $section] = $this->content();
        $certification->update(['status' => $status]);
        if ($registered) {
            Enrollment::factory()->for($student)->for($certification)->learning()->create();
        }

        foreach ($this->urls($part, $chapter, $section) as $url) {
            $this->actingAs($student)->get($url)->assertNotFound()->assertHeaderMissing('Location');
        }
        $this->assertDatabaseCount('learning_sessions', 0);
        $this->assertDatabaseCount('section_progresses', 0);
    }

    public static function unpublishedCertifications(): array
    {
        return [['draft', false], ['draft', true], ['archived', false], ['archived', true]];
    }

    public function test_rejected_access_does_not_create_sessions_or_close_an_existing_open_session(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        [$ownCertification, , , $ownSection] = $this->content();
        $ownEnrollment = Enrollment::factory()->for($student)->for($ownCertification)->learning()->create();
        $open = LearningSession::factory()->forUser($student)->forEnrollment($ownEnrollment)->open()->create([
            'section_id' => $ownSection->id,
        ]);
        $before = $open->fresh()->getAttributes();
        [$deniedCertification, $part, $chapter, $section] = $this->content();

        foreach ($this->urls($part, $chapter, $section) as $url) {
            $this->actingAs($student)->get($url)->assertForbidden();
            $this->assertSame($before, $open->fresh()->getAttributes());
            $this->assertDatabaseCount('learning_sessions', 1);
        }

        Enrollment::factory()->for($student)->for($deniedCertification)->failed()->create();
        $this->get(route('learning.sections.show', $section))->assertForbidden();
        $this->assertSame($before, $open->fresh()->getAttributes());
        $this->assertDatabaseCount('learning_sessions', 1);

        $this->get(route('learning.sections.show', $ownSection))->assertOk();
        $this->assertDatabaseCount('learning_sessions', 2);
        $this->assertTrue($open->fresh()->auto_closed);
    }

    /** @dataProvider actionLevels */
    public function test_actions_authorize_the_supplied_student_instead_of_the_current_auth_user(string $level, string $action): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        [$certification, $part, $chapter, $section] = $this->content();
        Enrollment::factory()->for($owner)->for($certification)->learning()->create();
        $resources = ['part' => $part, 'chapter' => $chapter, 'section' => $section];

        $this->actingAs($otherStudent);
        $data = app($action)($resources[$level], $owner);
        $this->assertTrue($data[$level]->is($resources[$level]));

        $this->actingAs($owner);
        $this->expectException(AuthorizationException::class);
        app($action)($resources[$level], $otherStudent);
    }

    public static function actionLevels(): array
    {
        return [['part', ShowPartAction::class], ['chapter', ShowChapterAction::class], ['section', ShowSectionAction::class]];
    }

    /** @return array{Certification, Part, Chapter, Section} */
    private function content(): array
    {
        $certification = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($certification)->published()->create(['title' => 'Protected Part '.$certification->id]);
        $chapter = Chapter::factory()->forPart($part)->published()->create(['title' => 'Protected Chapter '.$certification->id]);
        $section = Section::factory()->forChapter($chapter)->published()->create(['body' => 'Protected body '.$certification->id]);

        return [$certification, $part, $chapter, $section];
    }

    private function urls(Part $part, Chapter $chapter, Section $section): array
    {
        return [route('learning.parts.show', $part), route('learning.chapters.show', $chapter), route('learning.sections.show', $section)];
    }
}
