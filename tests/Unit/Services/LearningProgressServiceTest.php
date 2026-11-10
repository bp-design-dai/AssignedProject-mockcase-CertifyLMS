<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Enrollment;
use App\Models\SectionProgress;
use App\Services\LearningProgressService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\LearningProgressFixture;
use Tests\TestCase;

class LearningProgressServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @dataProvider scenarios */
    public function test_all_four_hierarchy_counts_and_ratios_preserve_existing_rules(string $scenario): void
    {
        $f = LearningProgressFixture::create($scenario);
        [$summary, $queries] = $this->measure(fn () => app(LearningProgressService::class)->summarize($f['enrollment']));
        $this->assertSame(LearningProgressFixture::expected($scenario), get_object_vars($summary));
        $this->assertSame(5, $queries, 'Keep the original summary query strategy.');
    }

    /** @dataProvider scenarios */
    public function test_batch_ratios_preserve_existing_values_and_single_query(string $scenario): void
    {
        $f = LearningProgressFixture::create($scenario);
        [$ratios, $queries] = $this->measure(fn () => app(LearningProgressService::class)->completionRatiosForEnrollments(new Collection([$f['enrollment']])));
        $this->assertSame([$f['enrollment']->id => LearningProgressFixture::expected($scenario)['overallCompletionRatio']], $ratios);
        $this->assertSame(1, $queries);
    }

    /** @dataProvider scenarios */
    public function test_chapter_card_counts_and_integer_percentages_are_preserved(string $scenario): void
    {
        $f = LearningProgressFixture::create($scenario);
        $service = app(LearningProgressService::class);
        $actual = [];
        foreach ($f['parts'] as $part) {
            $chapters = $service->chaptersWithSectionTotals($part);
            $completed = $service->completedSectionsByChapter($f['enrollment'], $chapters);
            $actual += $service->chapterProgress($chapters, $completed);
        }
        $this->assertSame($f['chapterDisplay'], $actual);
    }

    public function test_empty_batch_and_missing_enrollment_do_not_query(): void
    {
        $service = app(LearningProgressService::class);
        [$batch, $queries] = $this->measure(fn () => $service->completionRatiosForEnrollments(new Collection));
        $this->assertSame([], $batch);
        $this->assertSame(0, $queries);
        $f = LearningProgressFixture::create('partial');
        $chapters = $service->chaptersWithSectionTotals($f['parts'][0]);
        [$counts, $queries] = $this->measure(fn () => $service->completedSectionsByChapter(null, $chapters));
        $this->assertSame([], $counts);
        $this->assertSame(0, $queries);
        [$counts, $queries] = $this->measure(fn () => $service->completedSectionsByChapter($f['enrollment'], new Collection));
        $this->assertSame([], $counts);
        $this->assertSame(0, $queries);
    }

    public function test_batch_keeps_same_certification_enrollments_and_other_certifications_separate(): void
    {
        $f = LearningProgressFixture::create('partial');
        $other = Enrollment::factory()->for($f['certification'])->learning()->create();
        foreach ($f['sections'] as $section) {
            SectionProgress::factory()->forEnrollment($other)->forSection($section)->create();
        }
        $another = LearningProgressFixture::create('complete');
        [$ratios, $queries] = $this->measure(fn () => app(LearningProgressService::class)->completionRatiosForEnrollments(new Collection([$f['enrollment'], $other, $another['enrollment']])));
        $this->assertSame([$f['enrollment']->id => 0.3333, $other->id => 1.0, $another['enrollment']->id => 1.0], $ratios);
        $this->assertSame(1, $queries);
    }

    public function test_summary_does_not_cache_or_mutate_completion_state(): void
    {
        $f = LearningProgressFixture::create('unread');
        $service = app(LearningProgressService::class);
        $this->assertSame(0, $service->summarize($f['enrollment'])->sectionsCompleted);
        $this->assertDatabaseCount('section_progresses', 0);
        $progress = SectionProgress::factory()->forEnrollment($f['enrollment'])->forSection($f['sections'][0])->create();
        $this->assertSame(1, $service->summarize($f['enrollment'])->sectionsCompleted);
        $this->assertDatabaseCount('section_progresses', 1);
        $progress->delete();
        $this->assertSame(0, $service->summarize($f['enrollment'])->sectionsCompleted);
        $this->assertDatabaseCount('section_progresses', 0);
    }

    public static function scenarios(): array
    {
        return LearningProgressFixture::scenarios();
    }

    private function measure(callable $callback): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $result = $callback();
            $queries = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        return [$result, $queries];
    }
}
