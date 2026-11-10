<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MockExam;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\MockExam;
use App\Models\MockExamQuestion;
use App\Models\User;
use App\UseCases\MockExam\IndexAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MockExamIndexRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_read_all_related_names_without_additional_queries(): void
    {
        $admin = User::factory()->admin()->create();
        $expected = [];
        for ($i = 0; $i < 20; $i++) {
            $certification = Certification::factory()->published()->create();
            $creator = User::factory()->admin()->create();
            $updater = User::factory()->admin()->create();
            $exam = MockExam::factory()->forCertification($certification)->create([
                'created_by_user_id' => $creator->id, 'updated_by_user_id' => $updater->id,
            ]);
            $expected[$exam->id] = [$certification->name, $creator->name, $updater->name];
        }
        $items = app(IndexAction::class)($admin);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $actual = $items->mapWithKeys(fn ($exam) => [$exam->id => [
                $exam->certification?->name, $exam->createdBy?->name, $exam->updatedBy?->name,
            ]])->all();
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertEquals($expected, $actual);
        $this->assertCount(0, $queries, 'Reading listing relationships must not trigger per-row SQL.');
    }

    public function test_empty_index_retains_its_empty_state(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.mock-exams.index'))->assertOk()
            ->assertSee('模試マスタがまだありません')
            ->assertViewHas('mockExams', fn ($items) => $items->total() === 0 && $items->isEmpty());
    }

    public function test_http_query_count_is_constant_for_small_full_and_later_pages(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $counts = [];
        $created = 0;
        foreach ([[2, 1, 2], [20, 1, 20], [41, 2, 20], [41, 3, 1]] as [$total, $page, $shown]) {
            for (; $created < $total; $created++) {
                // Distinct parents and authors prevent a shared relation from masking N+1 queries.
                MockExam::factory()->create(['title' => '件数検証 '.$created]);
            }
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $response = $this->get(route('admin.mock-exams.index', ['page' => $page]));
                $counts[] = count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
            $response->assertOk();
            $this->assertCount($shown, $response->viewData('mockExams'));
            $this->assertSame($total, $response->viewData('mockExams')->total());
        }
        $this->assertSame([$counts[0], $counts[0], $counts[0], $counts[0]], $counts);
    }

    public function test_deleted_creator_and_updater_keep_missing_relation_and_placeholder(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = User::factory()->admin()->create(['name' => '削除済み作成者']);
        $updater = User::factory()->admin()->create(['name' => '削除済み更新者']);
        $exam = MockExam::factory()->create(['created_by_user_id' => $creator->id, 'updated_by_user_id' => $updater->id]);
        $creator->delete();
        $updater->delete();
        $this->actingAs($admin)->get(route('admin.mock-exams.index'))->assertOk()
            ->assertSee($exam->title)->assertSee('—')
            ->assertDontSee('削除済み更新者')
            ->assertViewHas('mockExams', function ($items) {
                $this->assertNull($items->first()->createdBy);
                $this->assertNull($items->first()->updatedBy);

                return true;
            });
    }

    public function test_sorting_still_uses_certification_order_then_updated_at_descending(): void
    {
        $admin = User::factory()->admin()->create();
        $first = Certification::factory()->create();
        $second = Certification::factory()->create();
        $other = MockExam::factory()->forCertification($second)->create(['order' => 0]);
        $laterOrder = MockExam::factory()->forCertification($first)->create(['order' => 2]);
        $older = MockExam::factory()->forCertification($first)->create(['order' => 0, 'updated_at' => '2025-01-01 00:00:00']);
        $newer = MockExam::factory()->forCertification($first)->create(['order' => 0, 'updated_at' => '2025-02-01 00:00:00']);
        $expected = [$newer->id, $older->id, $laterOrder->id, $other->id];
        $this->actingAs($admin)->get(route('admin.mock-exams.index'))->assertOk()
            ->assertViewHas('mockExams', fn ($items) => $items->pluck('id')->all() === $expected);
    }

    public function test_filters_and_twenty_row_pagination_keep_query_strings_and_row_order(): void
    {
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();
        $matching = collect();
        for ($i = 0; $i < 25; $i++) {
            $matching->push(MockExam::factory()->published()->forCertification($certification)->create(['title' => '対象キーワード '.$i, 'order' => $i]));
        }
        MockExam::factory()->draft()->forCertification($certification)->create(['title' => '対象キーワード 下書き']);
        MockExam::factory()->published()->create(['title' => '対象キーワード 別資格']);
        MockExam::factory()->published()->forCertification($certification)->create(['title' => '検索対象外']);
        $filters = ['keyword' => '対象キーワード', 'certification_id' => $certification->id, 'is_published' => '1'];
        $first = $this->actingAs($admin)->get(route('admin.mock-exams.index', $filters))->assertOk();
        $items = $first->viewData('mockExams');
        $this->assertSame(25, $items->total());
        $this->assertSame(20, $items->perPage());
        $this->assertSame($matching->take(20)->pluck('id')->all(), $items->pluck('id')->all());
        parse_str(parse_url($items->nextPageUrl(), PHP_URL_QUERY), $query);
        $this->assertSame($filters + ['page' => '2'], $query);
        $this->get($items->nextPageUrl())->assertOk()
            ->assertViewHas('mockExams', fn ($page) => $page->total() === 25 && $page->currentPage() === 2 && $page->pluck('id')->all() === $matching->skip(20)->values()->pluck('id')->all());
    }

    /** @dataProvider publicationFilters */
    public function test_publication_filters_keep_existing_boolean_string_behavior(string $filter, bool $published): void
    {
        $admin = User::factory()->admin()->create();
        $yes = MockExam::factory()->published()->create();
        $no = MockExam::factory()->draft()->create();
        $expected = $published ? $yes->id : $no->id;
        $this->actingAs($admin)->get(route('admin.mock-exams.index', ['is_published' => $filter]))->assertOk()
            ->assertViewHas('mockExams', fn ($items) => $items->pluck('id')->all() === [$expected]);
    }

    public static function publicationFilters(): array
    {
        return [['0', false], ['false', false], ['1', true], ['true', true]];
    }

    public function test_coach_keeps_existing_access_only_to_currently_assigned_certifications(): void
    {
        $coach = User::factory()->coach()->create();
        $assigned = Certification::factory()->create();
        $removed = Certification::factory()->create();
        CertificationCoachAssignment::factory()->create(['certification_id' => $assigned->id, 'user_id' => $coach->id]);
        CertificationCoachAssignment::factory()->create(['certification_id' => $removed->id, 'user_id' => $coach->id, 'unassigned_at' => now()]);
        $allowed = MockExam::factory()->forCertification($assigned)->create();
        MockExam::factory()->forCertification($removed)->create();
        MockExam::factory()->create();
        $this->actingAs($coach)->get(route('admin.mock-exams.index'))->assertOk()
            ->assertViewHas('mockExams', fn ($items) => $items->pluck('id')->all() === [$allowed->id]);
    }

    public function test_student_is_forbidden_and_guest_still_redirects_to_login(): void
    {
        $this->get(route('admin.mock-exams.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->student()->create())
            ->get(route('admin.mock-exams.index'))->assertForbidden();
    }

    public function test_listing_does_not_modify_exam_or_related_records(): void
    {
        $admin = User::factory()->admin()->create();
        MockExam::factory()->count(3)->create();
        $tables = ['mock_exams', 'certifications', 'users', 'mock_exam_questions'];
        $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toArray()])->all();
        $this->actingAs($admin)->get(route('admin.mock-exams.index'))->assertOk();
        foreach ($tables as $table) {
            $this->assertEquals($before[$table], DB::table($table)->orderBy('id')->get()->toArray());
        }
    }

    public function test_admin_question_count_display_matches_the_existing_relation(): void
    {
        $admin = User::factory()->admin()->create();
        $exam = MockExam::factory()->create();
        MockExamQuestion::factory()->forMockExam($exam)->count(3)->create();
        $response = $this->actingAs($admin)->get(route('admin.mock-exams.index'))->assertOk();
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument;
            $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
            $cells = (new \DOMXPath($document))->query('//a[@href="'.route('admin.mock-exams.show', $exam).'"]/ancestor::tr[1]/td[3]');
            $this->assertGreaterThan(0, $cells->length);
            $this->assertSame('3', trim($cells->item(0)->textContent));
            $this->assertSame(3, $exam->mockExamQuestions()->count());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
