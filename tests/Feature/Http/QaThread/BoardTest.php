<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaThread;

use App\Enums\QaThreadStatus;
use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_browse_board(): void
    {
        $this->actingAs(User::factory()->student()->inProgress()->create())
            ->get('/qa-board')
            ->assertOk();
    }

    public function test_index_shows_published_threads_without_requiring_enrollment(): void
    {
        $visible = QaThread::factory()->create();
        $hidden = QaThread::factory()->for(Certification::factory()->archived())->create();

        $this->actingAs(User::factory()->student()->create())->get(route('qa-board.index'))
            ->assertOk()->assertSee($visible->title)->assertDontSee($hidden->title)
            ->assertViewHas('threads', fn ($threads) => $threads->total() === 1);
    }

    public function test_combined_filters_search_body_not_title_and_preserve_pagination(): void
    {
        $certification = Certification::factory()->published()->create();
        QaThread::factory()->count(21)->for($certification)->create(['body' => 'データベースの質問', 'created_at' => now()->subDay()]);
        $newest = QaThread::factory()->for($certification)->create(['body' => 'データベースの質問', 'created_at' => now()]);
        QaThread::factory()->resolved()->for($certification)->create(['body' => 'データベースの質問']);
        QaThread::factory()->for($certification)->create(['title' => 'データベース', 'body' => '本文は別の話題']);
        QaThread::factory()->create(['body' => 'データベースの質問']);
        $filters = ['certification_id' => $certification->id, 'status' => 'unresolved', 'keyword' => 'データベース'];

        $response = $this->actingAs(User::factory()->student()->create())->get(route('qa-board.index', $filters));
        $response->assertOk()->assertViewHas('threads', fn ($threads) => $threads->total() === 22 && $threads->perPage() === 20 && $threads->first()->is($newest));
        $this->assertStringContainsString('keyword=', $response->viewData('threads')->nextPageUrl());
        $this->get(route('qa-board.index', $filters + ['page' => 2]))->assertOk()
            ->assertViewHas('threads', fn ($threads) => $threads->count() === 2);
        $this->get(route('qa-board.index', ['status' => QaThreadStatus::Resolved->value]))->assertOk()
            ->assertViewHas('threads', fn ($threads) => $threads->total() === 1);
    }

    /** @dataProvider invalidFilters */
    public function test_invalid_filters_are_rejected(array $filter, string $field): void
    {
        $this->actingAs(User::factory()->student()->create())->getJson(route('qa-board.index', $filter))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidFilters(): array
    {
        return [
            'unknown status' => [['status' => 'invalid'], 'status'],
            'invalid certification' => [['certification_id' => 'bad'], 'certification_id'],
            'long keyword' => [['keyword' => str_repeat('a', 101)], 'keyword'],
            'invalid page' => [['page' => 0], 'page'],
        ];
    }

    public function test_index_and_reply_detail_do_not_add_queries_per_item(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()->create();
        $this->actingAs($student);
        $smallIndex = $this->queriesFor(route('qa-board.index'));
        QaThread::factory()->count(19)->create();
        $largeIndex = $this->queriesFor(route('qa-board.index'));
        $this->assertLessThanOrEqual($smallIndex + 1, $largeIndex);

        QaReply::factory()->for($thread, 'thread')->create();
        $smallDetail = $this->queriesFor(route('qa-board.show', $thread));
        QaReply::factory()->count(19)->for($thread, 'thread')->create();
        $largeDetail = $this->queriesFor(route('qa-board.show', $thread));
        $this->assertLessThanOrEqual($smallDetail + 1, $largeDetail);
    }

    public function test_non_active_student_and_coach_cannot_use_any_board_endpoint(): void
    {
        $thread = QaThread::factory()->create();
        foreach (['student', 'coach'] as $role) {
            foreach ([UserStatus::Invited, UserStatus::Graduated, UserStatus::Withdrawn] as $status) {
                $user = User::factory()->create(['role' => $role, 'status' => $status]);
                $this->actingAs($user)->get(route('qa-board.index'))->assertForbidden();
                $this->get(route('qa-board.show', $thread))->assertForbidden();
                $this->post(route('qa-board.replies.store', $thread), ['body' => '回答'])->assertForbidden();
            }
        }
    }

    public function test_guest_is_redirected_to_login_and_student_cannot_use_moderation(): void
    {
        $this->get(route('qa-board.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->student()->create())->get(route('admin.qa-board.index'))->assertForbidden();
    }

    public function test_question_and_reply_html_are_escaped(): void
    {
        $thread = QaThread::factory()->create(['title' => '<script>title()</script>', 'body' => '<script>question()</script>']);
        QaReply::factory()->for($thread, 'thread')->create(['body' => '<script>reply()</script>']);
        $response = $this->actingAs(User::factory()->student()->create())->get(route('qa-board.show', $thread))->assertOk();
        foreach (['title', 'question', 'reply'] as $value) {
            $response->assertDontSee('<script>'.$value.'()</script>', false)->assertSee('&lt;script&gt;'.$value.'()&lt;/script&gt;', false);
        }
    }

    private function queriesFor(string $url): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get($url)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
