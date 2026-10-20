<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Part;

use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Part;
use App\Models\Section;
use App\Models\SectionImage;
use App\Models\SectionQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ContentTestHelpers;
use Tests\TestCase;

class CoachAuthorizationRegressionTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    /** @dataProvider viewers */
    public function test_management_pages_require_an_active_assignment(string $actor, bool $allowed): void
    {
        $content = $this->content();
        $this->authenticate($actor, $content['certification']);

        foreach ($this->pages($content) as $url) {
            $response = $this->get($url);
            if ($actor === 'guest') {
                $response->assertRedirect(route('login'));
            } else {
                $response->assertStatus($allowed ? 200 : 403);
            }
        }
    }

    public static function viewers(): array
    {
        return [
            'assigned coach' => ['assigned', true],
            'unassigned coach' => ['unassigned', false],
            'revoked coach' => ['revoked', false],
            'admin' => ['admin', true],
            'student' => ['student', false],
            'guest' => ['guest', false],
        ];
    }

    /** @dataProvider rejectedActors */
    public function test_unauthorized_writes_do_not_change_content_or_files(string $actor): void
    {
        Storage::fake('public');
        $content = $this->content();
        $this->authenticate($actor, $content['certification']);
        $image = SectionImage::factory()->forSection($content['section'])->create();
        Storage::disk('public')->put($image->path, 'existing image');
        $before = $this->snapshot();
        $requests = [];

        foreach (['part', 'chapter', 'section'] as $level) {
            $resource = $content[$level];
            $requests[] = ['PATCH', route('admin.'.$level.'s.update', $resource), ['title' => 'Denied', 'body' => 'Denied']];
            foreach (['publish', 'unpublish'] as $operation) {
                $requests[] = ['POST', route('admin.'.$level.'s.'.$operation, $resource), []];
            }
            $requests[] = ['DELETE', route('admin.'.$level.'s.destroy', $resource), []];
        }
        foreach ([
            ['admin.certifications.parts', $content['certification'], $content['part']],
            ['admin.parts.chapters', $content['part'], $content['chapter']],
            ['admin.chapters.sections', $content['chapter'], $content['section']],
        ] as [$prefix, $parent, $child]) {
            $requests[] = ['POST', route($prefix.'.store', $parent), ['title' => 'Denied', 'body' => 'Denied']];
            $requests[] = ['PATCH', route($prefix.'.reorder', $parent), ['ids' => [$child->id]]];
        }
        $requests[] = ['POST', route('admin.sections.preview', $content['section']), ['body' => '# Denied']];
        $requests[] = ['POST', route('admin.sections.images.store', $content['section']), ['file' => UploadedFile::fake()->image('denied.png')]];
        $requests[] = ['DELETE', route('admin.section-images.destroy', $image), []];
        $requests[] = ['POST', route('admin.sections.questions.store', $content['section']), $this->questionData($content['category']->id)];
        $requests[] = ['PATCH', route('admin.section-questions.update', $content['question']), $this->questionData($content['category']->id)];
        foreach (['publish', 'unpublish'] as $operation) {
            $requests[] = ['POST', route('admin.section-questions.'.$operation, $content['question']), []];
        }
        $requests[] = ['DELETE', route('admin.section-questions.destroy', $content['question']), []];
        $requests[] = ['POST', route('admin.certifications.question-categories.store', $content['certification']), ['name' => 'Denied', 'slug' => 'denied']];
        $requests[] = ['PATCH', route('admin.question-categories.update', $content['category']), ['name' => 'Denied', 'slug' => 'denied']];
        $requests[] = ['DELETE', route('admin.question-categories.destroy', $content['category']), []];

        foreach ($requests as [$method, $url, $data]) {
            $this->json($method, $url, $data)->assertStatus($actor === 'guest' ? 401 : 403);
            $this->assertSame($before, $this->snapshot());
        }
        $this->assertSame([$image->path], Storage::disk('public')->allFiles());
    }

    public static function rejectedActors(): array
    {
        return [['unassigned'], ['revoked'], ['student'], ['guest']];
    }

    /** @dataProvider managers */
    public function test_managers_can_create_update_publish_reorder_and_delete_each_level(string $actor): void
    {
        $content = $this->content();
        $certification = $content['certification'];
        $this->authenticate($actor, $certification);
        $created = [];
        $parent = $certification;

        foreach ([
            ['part', Part::class, 'admin.certifications.parts'],
            ['chapter', Chapter::class, 'admin.parts.chapters'],
            ['section', Section::class, 'admin.chapters.sections'],
        ] as [$level, $model, $prefix]) {
            $title = 'Created '.$level;
            $this->post(route($prefix.'.store', $parent), ['title' => $title, 'body' => '# Body'])->assertRedirect();
            $resource = $model::where('title', $title)->firstOrFail();
            $this->assertSame('draft', $resource->status->value);
            $this->get(route('admin.'.$level.'s.show', $resource))->assertOk();
            $this->patch(route('admin.'.$level.'s.update', $resource), ['title' => 'Updated '.$level, 'body' => '# Updated'])->assertRedirect();
            $this->assertSame('Updated '.$level, $resource->fresh()->title);
            $this->post(route('admin.'.$level.'s.publish', $resource))->assertRedirect();
            $this->assertSame('published', $resource->fresh()->status->value);
            $this->deleteJson(route('admin.'.$level.'s.destroy', $resource))->assertStatus(409);
            $this->post(route('admin.'.$level.'s.unpublish', $resource))->assertRedirect();
            $this->assertSame('draft', $resource->fresh()->status->value);
            $relation = $level.'s';
            $ids = $parent->$relation()->orderBy('order', 'desc')->pluck('id')->all();
            $this->patch(route($prefix.'.reorder', $parent), ['ids' => $ids])->assertRedirect();
            $this->assertSame($ids, $parent->$relation()->ordered()->pluck('id')->all());
            $created[$level] = $resource;
            $parent = $resource;
        }

        $this->postJson(route('admin.sections.preview', $created['section']), ['body' => '# Preview'])->assertOk()->assertJsonPath('html', "<h1>Preview</h1>\n");
        foreach (array_reverse($created, true) as $level => $resource) {
            $this->delete(route('admin.'.$level.'s.destroy', $resource))->assertRedirect();
            $this->assertDatabaseMissing($resource->getTable(), ['id' => $resource->id]);
        }
        $this->assertDatabaseHas('parts', ['id' => $content['part']->id]);
    }

    /** @dataProvider managers */
    public function test_managers_can_manage_questions_categories_and_images(string $actor): void
    {
        Storage::fake('public');
        $content = $this->content();
        $this->authenticate($actor, $content['certification']);
        $this->post(route('admin.certifications.question-categories.store', $content['certification']), ['name' => 'New category', 'slug' => 'new-category'])->assertRedirect();
        $category = $content['certification']->questionCategories()->where('slug', 'new-category')->firstOrFail();
        $this->patch(route('admin.question-categories.update', $category), ['name' => 'Updated category', 'slug' => 'updated-category'])->assertRedirect();
        $this->assertSame('Updated category', $category->fresh()->name);
        $this->post(route('admin.sections.questions.store', $content['section']), $this->questionData($category->id))->assertRedirect();
        $question = SectionQuestion::where('body', 'New question')->firstOrFail();
        $this->get(route('admin.section-questions.show', $question))->assertOk();
        $data = $this->questionData($category->id);
        $data['body'] = 'Updated question';
        $this->patch(route('admin.section-questions.update', $question), $data)->assertRedirect();
        $this->assertSame('Updated question', $question->fresh()->body);
        $this->post(route('admin.section-questions.publish', $question))->assertRedirect();
        $this->assertSame('published', $question->fresh()->status->value);
        $this->post(route('admin.section-questions.unpublish', $question))->assertRedirect();
        $this->assertSame('draft', $question->fresh()->status->value);
        $this->deleteJson(route('admin.question-categories.destroy', $category))->assertStatus(409);
        $this->delete(route('admin.section-questions.destroy', $question))->assertRedirect();
        $this->assertDatabaseMissing('section_questions', ['id' => $question->id]);
        $this->delete(route('admin.question-categories.destroy', $category))->assertRedirect();
        $this->assertDatabaseMissing('question_categories', ['id' => $category->id]);

        $upload = $this->postJson(route('admin.sections.images.store', $content['section']), ['file' => UploadedFile::fake()->image('coach.png')])->assertCreated();
        $image = SectionImage::findOrFail($upload->json('id'));
        Storage::disk('public')->assertExists($image->path);
        $this->deleteJson(route('admin.section-images.destroy', $image))->assertNoContent();
        $this->assertDatabaseMissing('section_images', ['id' => $image->id]);
    }

    public static function managers(): array
    {
        return [['assigned'], ['admin']];
    }

    public function test_multiple_assignments_and_revocation_are_checked_on_each_request(): void
    {
        $first = $this->content();
        $second = $this->content();
        $coach = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();
        $this->assignCoach($coach, $first['certification']);
        $this->assignCoach($coach, $second['certification']);
        $this->assignCoach($otherCoach, $first['certification']);
        $this->actingAs($coach);
        foreach (array_merge($this->pages($first), $this->pages($second)) as $url) {
            $this->get($url)->assertOk();
        }
        $first['certification']->coaches()->updateExistingPivot($coach->id, ['unassigned_at' => now()]);
        foreach ($this->pages($first) as $url) {
            $this->get($url)->assertForbidden();
        }
        foreach ($this->pages($second) as $url) {
            $this->get($url)->assertOk();
        }
    }

    /** @dataProvider unpublishedStatuses */
    public function test_assigned_coach_can_manage_unpublished_certifications(string $status): void
    {
        $content = $this->content();
        $content['certification']->update(['status' => $status]);
        $this->authenticate('assigned', $content['certification']);
        foreach ($this->pages($content) as $url) {
            $this->get($url)->assertOk();
        }
        $this->patch(route('admin.sections.update', $content['section']), ['title' => 'Draft content', 'body' => '# Editable'])->assertRedirect();
    }

    public static function unpublishedStatuses(): array
    {
        return [['draft'], ['archived']];
    }

    public function test_assignment_does_not_allow_reordering_another_parent(): void
    {
        $own = $this->content();
        $other = $this->content();
        $this->authenticate('assigned', $own['certification']);
        $before = $this->snapshot();
        foreach ([
            ['admin.certifications.parts.reorder', $own['certification'], $other['part']],
            ['admin.parts.chapters.reorder', $own['part'], $other['chapter']],
            ['admin.chapters.sections.reorder', $own['chapter'], $other['section']],
        ] as [$route, $parent, $foreign]) {
            $this->patchJson(route($route, $parent), ['ids' => [$foreign->id]])->assertStatus(422);
        }
        $this->assertSame($before, $this->snapshot());
    }

    private function content(): array
    {
        $certification = Certification::factory()->published()->create();
        [$part, $chapter, $section] = $this->makePartChain($certification, 'draft');
        $category = $this->makeCategory($certification);
        $question = SectionQuestion::factory()->forSection($section)->forCategory($category)->withOptions(2)->draft()->create();

        return compact('certification', 'part', 'chapter', 'section', 'category', 'question');
    }

    private function authenticate(string $actor, Certification $certification): void
    {
        if ($actor === 'guest') {
            return;
        }
        $role = in_array($actor, ['admin', 'student'], true) ? $actor : 'coach';
        $user = User::factory()->state(['role' => $role])->inProgress()->create();
        if (in_array($actor, ['assigned', 'revoked'], true)) {
            $this->assignCoach($user, $certification);
            if ($actor === 'revoked') {
                $certification->coaches()->updateExistingPivot($user->id, ['unassigned_at' => now()]);
            }
        }
        // 他コーチの有効な割当だけでは本人を許可しない。
        $this->assignCoach(User::factory()->coach()->create(), $certification);
        $this->actingAs($user);
    }

    private function pages(array $content): array
    {
        return [
            route('admin.certifications.parts.index', $content['certification']),
            route('admin.parts.show', $content['part']),
            route('admin.chapters.show', $content['chapter']),
            route('admin.sections.show', $content['section']),
            route('admin.sections.questions.index', $content['section']),
            route('admin.sections.questions.create', $content['section']),
            route('admin.section-questions.show', $content['question']),
            route('admin.certifications.question-categories.index', $content['certification']),
        ];
    }

    private function questionData(string $categoryId): array
    {
        return [
            'body' => 'New question',
            'category_id' => $categoryId,
            'options' => [
                ['body' => 'Correct', 'is_correct' => true, 'order' => 0],
                ['body' => 'Incorrect', 'is_correct' => false, 'order' => 1],
            ],
        ];
    }

    private function snapshot(): array
    {
        $rows = [];
        foreach (['parts', 'chapters', 'sections', 'section_questions', 'section_question_options', 'section_images', 'question_categories', 'certification_coach_assignments'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $rows;
    }
}
