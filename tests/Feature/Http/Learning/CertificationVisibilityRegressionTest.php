<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Learning;

use App\Enums\CertificationStatus;
use App\Enums\ContentStatus;
use App\Enums\EnrollmentStatus;
use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\LearningSession;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CertificationVisibilityRegressionTest extends TestCase
{
    use RefreshDatabase;

    /** @dataProvider certificationAndEnrollmentStatuses */
    public function test_each_learning_level_requires_a_published_certification(string $certificationStatus, string $enrollmentStatus): void
    {
        $student = User::factory()->student()->inProgress()->create();
        [$certification, $enrollment, $part, $chapter, $section] = $this->makeContent(
            $student,
            CertificationStatus::from($certificationStatus),
            EnrollmentStatus::from($enrollmentStatus),
        );
        $expectedStatus = $certificationStatus === CertificationStatus::Published->value ? 200 : 404;

        foreach ($this->learningUrls($part, $chapter, $section) as $url) {
            $response = $this->actingAs($student)->get($url)
                ->assertStatus($expectedStatus)
                ->assertHeaderMissing('Location');
            if ($expectedStatus === 404) {
                $response->assertDontSee($part->title)->assertDontSee($chapter->title)->assertDontSee($section->body);
            }
        }

        $this->assertSame($certificationStatus, $certification->fresh()->status->value);
        $this->assertSame($enrollmentStatus, $enrollment->fresh()->status->value);
        $this->assertDatabaseCount('learning_sessions', $expectedStatus === 200 ? 1 : 0);
    }

    public static function certificationAndEnrollmentStatuses(): array
    {
        return [
            'published learning' => ['published', 'learning'],
            'published passed' => ['published', 'passed'],
            'draft learning' => ['draft', 'learning'],
            'draft passed' => ['draft', 'passed'],
            'archived learning' => ['archived', 'learning'],
            'archived passed' => ['archived', 'passed'],
        ];
    }

    /** @dataProvider activeEnrollmentStatuses */
    public function test_archiving_blocks_existing_direct_links_without_affecting_another_certification(string $enrollmentStatus): void
    {
        $student = User::factory()->student()->inProgress()->create();
        [$certification, $enrollment, $part, $chapter, $section] = $this->makeContent($student, enrollmentStatus: EnrollmentStatus::from($enrollmentStatus));
        [, , $otherPart, $otherChapter, $otherSection] = $this->makeContent($student);
        $urls = $this->learningUrls($part, $chapter, $section);

        foreach ($urls as $url) {
            $this->actingAs($student)->get($url)->assertOk();
        }
        $sessionCount = LearningSession::count();
        $certification->update(['status' => CertificationStatus::Archived, 'archived_at' => now()]);

        foreach ($urls as $url) {
            $this->get($url)->assertNotFound()->assertHeaderMissing('Location')->assertDontSee($section->body);
        }
        $this->assertSame($sessionCount, LearningSession::count());
        $this->assertSame($enrollmentStatus, $enrollment->fresh()->status->value);
        $this->assertSame($certification->id, $part->fresh()->certification_id);

        foreach ($this->learningUrls($otherPart, $otherChapter, $otherSection) as $url) {
            $this->get($url)->assertOk();
        }
    }

    public static function activeEnrollmentStatuses(): array
    {
        return [['learning'], ['passed']];
    }

    /** @dataProvider draftContentLevels */
    public function test_existing_content_publication_checks_are_preserved(string $level, array $expectedStatuses): void
    {
        $student = User::factory()->student()->inProgress()->create();
        [, , $part, $chapter, $section] = $this->makeContent($student);
        $content = ['part' => $part, 'chapter' => $chapter, 'section' => $section];
        $content[$level]->update(['status' => ContentStatus::Draft]);

        foreach ($this->learningUrls($part, $chapter, $section) as $index => $url) {
            $this->actingAs($student)->get($url)->assertStatus($expectedStatuses[$index]);
        }
    }

    public static function draftContentLevels(): array
    {
        return [
            ['part', [404, 404, 404]],
            ['chapter', [200, 404, 404]],
            ['section', [200, 200, 404]],
        ];
    }

    /** @dataProvider certificationStatuses */
    public function test_admin_management_is_not_restricted_by_student_visibility_checks(string $status): void
    {
        $student = User::factory()->student()->inProgress()->create();
        [, , $part, $chapter, $section] = $this->makeContent($student, CertificationStatus::from($status));
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.parts.show', $part))->assertOk();
        $this->get(route('admin.chapters.show', $chapter))->assertOk();
        $this->get(route('admin.sections.show', $section))->assertOk();
    }

    public static function certificationStatuses(): array
    {
        return [['published'], ['draft'], ['archived']];
    }

    /** @dataProvider nonStudentRoles */
    public function test_learning_routes_remain_student_only(string $role): void
    {
        $student = User::factory()->student()->inProgress()->create();
        [, , $part, $chapter, $section] = $this->makeContent($student);
        $user = User::factory()->inProgress()->create(['role' => $role]);

        foreach ($this->learningUrls($part, $chapter, $section) as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }
    }

    public static function nonStudentRoles(): array
    {
        return [['admin'], ['coach']];
    }

    public function test_guests_are_redirected_to_login_before_learning_content_is_shown(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        [, , $part, $chapter, $section] = $this->makeContent($student);

        foreach ($this->learningUrls($part, $chapter, $section) as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    /** @return array{Certification, Enrollment, Part, Chapter, Section} */
    private function makeContent(User $student, CertificationStatus $status = CertificationStatus::Published, EnrollmentStatus $enrollmentStatus = EnrollmentStatus::Learning): array
    {
        $certification = Certification::factory()->create(['status' => $status]);
        $enrollment = Enrollment::factory()->for($student)->for($certification)->create(['status' => $enrollmentStatus]);
        $part = Part::factory()->forCertification($certification)->published()->create(['title' => 'Part '.$certification->id]);
        $chapter = Chapter::factory()->forPart($part)->published()->create(['title' => 'Chapter '.$certification->id]);
        $section = Section::factory()->forChapter($chapter)->published()->create(['body' => 'Content '.$certification->id]);

        return [$certification, $enrollment, $part, $chapter, $section];
    }

    /** @return list<string> */
    private function learningUrls(Part $part, Chapter $chapter, Section $section): array
    {
        return [route('learning.parts.show', $part), route('learning.chapters.show', $chapter), route('learning.sections.show', $section)];
    }
}
