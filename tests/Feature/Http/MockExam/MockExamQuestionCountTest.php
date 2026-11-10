<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MockExam;

use App\Models\Certification;
use App\Models\MockExam;
use App\Models\MockExamQuestion;
use App\Models\User;
use App\UseCases\MockExam\IndexAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MockExamQuestionCountTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('questionCounts')]
    public function test_admin_listing_and_detail_display_the_same_actual_question_count(int $count): void
    {
        $admin = User::factory()->admin()->create();
        $exam = MockExam::factory()->create(['is_published' => $count === 1]);
        MockExamQuestion::factory()->forMockExam($exam)->count($count)->create();
        $list = $this->actingAs($admin)->get(route('admin.mock-exams.index'))->assertOk();
        $detail = $this->get(route('admin.mock-exams.show', $exam))->assertOk();

        $this->assertSame($count, $this->displayedCount($list->getContent(), $exam));
        $this->assertSame($count, $detail->viewData('mockExam')->mock_exam_questions_count);
        $this->assertMatchesRegularExpression('/問題\s*<span[^>]*>\s*'.$count.'\s*<\/span>\s*件/u', $detail->getContent());
        $this->assertSame($count, $exam->mockExamQuestions()->count());
    }

    public static function questionCounts(): array
    {
        return [[0], [1], [6]];
    }

    public function test_counts_do_not_mix_between_exams_or_certifications(): void
    {
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->create();
        $expected = [];
        foreach ([0, 1, 6] as $count) {
            $exam = MockExam::factory()->forCertification($certification)->create();
            MockExamQuestion::factory()->forMockExam($exam)->count($count)->create();
            $expected[$exam->id] = $count;
        }
        $other = MockExam::factory()->create();
        MockExamQuestion::factory()->forMockExam($other)->count(3)->create();
        $expected[$other->id] = 3;
        $response = $this->actingAs($admin)->get(route('admin.mock-exams.index'))->assertOk();

        foreach ($response->viewData('mockExams') as $exam) {
            $this->assertSame($expected[$exam->id], $this->displayedCount($response->getContent(), $exam));
        }
    }

    public function test_physically_deleted_questions_are_excluded_in_both_views(): void
    {
        $admin = User::factory()->admin()->create();
        $exam = MockExam::factory()->create();
        $questions = MockExamQuestion::factory()->forMockExam($exam)->count(2)->create();
        $questions->first()->delete();

        $list = $this->actingAs($admin)->get(route('admin.mock-exams.index'))->assertOk();
        $detail = $this->get(route('admin.mock-exams.show', $exam))->assertOk();
        $this->assertSame(1, $this->displayedCount($list->getContent(), $exam));
        $this->assertSame(1, $detail->viewData('mockExam')->mock_exam_questions_count);
        $this->assertSame(1, $exam->mockExamQuestions()->count());
    }

    public function test_reading_counts_and_existing_related_names_requires_no_per_row_queries(): void
    {
        $admin = User::factory()->admin()->create();
        $expected = [];
        foreach ([0, 1, 6] as $count) {
            $exam = MockExam::factory()->create();
            MockExamQuestion::factory()->forMockExam($exam)->count($count)->create();
            $expected[$exam->id] = $count;
        }
        $items = app(IndexAction::class)($admin);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            foreach ($items as $exam) {
                $this->assertSame($expected[$exam->id], $exam->mock_exam_questions_count);
                $this->assertNotNull($exam->certification->name);
                $this->assertNotNull($exam->createdBy->name);
                $this->assertNotNull($exam->updatedBy->name);
            }
            $this->assertCount(0, DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_http_query_count_stays_constant_with_nonzero_questions_and_pagination(): void
    {
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();
        $expected = [];
        $this->actingAs($admin);
        $queries = [];
        $created = 0;
        foreach ([[2, 1], [12, 1], [20, 1], [30, 1], [30, 2]] as [$total, $page]) {
            for (; $created < $total; $created++) {
                $exam = MockExam::factory()->forCertification($certification)->create(['order' => $created]);
                $count = [0, 1, 6][$created % 3];
                MockExamQuestion::factory()->forMockExam($exam)->count($count)->create();
                $expected[$exam->id] = $count;
            }
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $response = $this->get(route('admin.mock-exams.index', ['page' => $page]));
                $queries[] = count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
            $response->assertOk();
            $this->assertSame(20, $response->viewData('mockExams')->perPage());
            $this->assertCount(min(20, $total - ($page - 1) * 20), $response->viewData('mockExams'));
            foreach ($response->viewData('mockExams') as $exam) {
                $this->assertSame($expected[$exam->id], $this->displayedCount($response->getContent(), $exam));
            }
        }
        $this->assertSame(array_fill(0, 5, $queries[0]), $queries, 'Question counts must not introduce N+1 SQL.');
    }

    private function displayedCount(string $html, MockExam $exam): int
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument;
            $document->loadHTML('<?xml encoding="UTF-8">'.$html);
            $cells = (new \DOMXPath($document))->query('//a[@href="'.route('admin.mock-exams.show', $exam).'"]/ancestor::tr[1]/td[3]');
            $this->assertGreaterThan(0, $cells->length);

            return (int) trim($cells->item(0)->textContent);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
