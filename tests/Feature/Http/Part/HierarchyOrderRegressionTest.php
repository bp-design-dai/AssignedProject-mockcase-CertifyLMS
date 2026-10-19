<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Part;

use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HierarchyOrderRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_part_list_reflects_reordering_without_mixing_certifications(): void
    {
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();
        $first = Part::factory()->forCertification($certification)->published()->create(['order' => 1, 'title' => 'Original first part']);
        $second = Part::factory()->forCertification($certification)->create(['order' => 2, 'title' => 'Original second part']);
        $foreign = Part::factory()->create(['order' => 1, 'title' => 'Foreign certification part']);

        $response = $this->actingAs($admin)
            ->followingRedirects()
            ->patch(route('admin.certifications.parts.reorder', $certification), [
                'ids' => [$second->id, $first->id],
            ]);

        $response->assertOk()
            ->assertSeeInOrder([$second->title, $first->title])
            ->assertDontSee($foreign->title);
        $this->assertSame([$second->id, $first->id], $response->viewData('parts')->modelKeys());
        $this->assertSame([1, 2], $response->viewData('parts')->pluck('order')->all());
        $this->assertSame($certification->id, $first->fresh()->certification_id);
        $this->assertSame($certification->id, $second->fresh()->certification_id);
        $this->assertSame(1, $foreign->fresh()->order);
        $this->assertSame('published', $first->fresh()->status->value);
        $this->assertSame('draft', $second->fresh()->status->value);
    }

    public function test_chapter_list_reflects_reordering_within_its_part(): void
    {
        $admin = User::factory()->admin()->create();
        $part = Part::factory()->published()->create();
        $first = Chapter::factory()->forPart($part)->published()->create(['order' => 1, 'title' => 'Original first chapter']);
        $second = Chapter::factory()->forPart($part)->create(['order' => 2, 'title' => 'Original second chapter']);
        $siblingPart = Part::factory()->forCertification($part->certification)->create();
        $foreign = Chapter::factory()->forPart($siblingPart)->create(['order' => 1, 'title' => 'Sibling part chapter']);
        Section::factory()->forChapter($first)->count(2)->create();
        Section::factory()->forChapter($second)->create();

        $response = $this->actingAs($admin)
            ->followingRedirects()
            ->patch(route('admin.parts.chapters.reorder', $part), [
                'ids' => [$second->id, $first->id],
            ]);

        $response->assertOk()
            ->assertSeeInOrder([$second->title, $first->title])
            ->assertDontSee($foreign->title);
        $chapters = $response->viewData('part')->chapters;
        $this->assertSame([$second->id, $first->id], $chapters->modelKeys());
        $this->assertSame([1, 2], $chapters->pluck('order')->all());
        $this->assertSame([1, 2], $chapters->pluck('sections_count')->all());
        $this->assertSame($part->id, $first->fresh()->part_id);
        $this->assertSame($part->id, $second->fresh()->part_id);
        $this->assertSame(1, $foreign->fresh()->order);
        $this->assertSame('published', $first->fresh()->status->value);
        $this->assertSame('draft', $second->fresh()->status->value);
    }

    public function test_section_list_reflects_reordering_within_its_chapter(): void
    {
        $admin = User::factory()->admin()->create();
        $chapter = Chapter::factory()->published()->create();
        $first = Section::factory()->forChapter($chapter)->published()->create(['order' => 1, 'title' => 'Original first section']);
        $second = Section::factory()->forChapter($chapter)->create(['order' => 2, 'title' => 'Original second section']);
        $siblingChapter = Chapter::factory()->forPart($chapter->part)->create();
        $foreign = Section::factory()->forChapter($siblingChapter)->create(['order' => 1, 'title' => 'Sibling chapter section']);

        $response = $this->actingAs($admin)
            ->followingRedirects()
            ->patch(route('admin.chapters.sections.reorder', $chapter), [
                'ids' => [$second->id, $first->id],
            ]);

        $response->assertOk()
            ->assertSeeInOrder([$second->title, $first->title])
            ->assertDontSee($foreign->title);
        $sections = $response->viewData('chapter')->sections;
        $this->assertSame([$second->id, $first->id], $sections->modelKeys());
        $this->assertSame([1, 2], $sections->pluck('order')->all());
        $this->assertSame($chapter->id, $first->fresh()->chapter_id);
        $this->assertSame($chapter->id, $second->fresh()->chapter_id);
        $this->assertSame(1, $foreign->fresh()->order);
        $this->assertSame('published', $first->fresh()->status->value);
        $this->assertSame('draft', $second->fresh()->status->value);
    }

    public function test_part_index_preserves_ordered_chapters_and_section_counts(): void
    {
        $admin = User::factory()->admin()->create();
        $part = Part::factory()->create();
        $later = Chapter::factory()->forPart($part)->create(['order' => 9]);
        $earlier = Chapter::factory()->forPart($part)->create(['order' => 2]);
        Section::factory()->forChapter($later)->create();
        Section::factory()->forChapter($earlier)->count(2)->create();

        $response = $this->actingAs($admin)
            ->get(route('admin.certifications.parts.index', $part->certification))
            ->assertOk();

        $chapters = $response->viewData('parts')->sole()->chapters;
        $this->assertSame([$earlier->id, $later->id], $chapters->modelKeys());
        $this->assertSame([2, 1], $chapters->pluck('sections_count')->all());
    }

    public function test_hierarchy_management_still_requires_authentication(): void
    {
        $chapter = Chapter::factory()->create();
        $part = $chapter->part;

        $this->get(route('admin.certifications.parts.index', $part->certification))->assertRedirect(route('login'));
        $this->get(route('admin.parts.show', $part))->assertRedirect(route('login'));
        $this->get(route('admin.chapters.show', $chapter))->assertRedirect(route('login'));
    }
}
