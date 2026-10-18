<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use App\UseCases\Dashboard\FetchCoachDashboardAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_coach_can_only_view_and_reply_to_assigned_published_questions(): void
    {
        $coach = User::factory()->coach()->create();
        $assigned = QaThread::factory()->create();
        $other = QaThread::factory()->create();
        $archived = QaThread::factory()->for(Certification::factory()->archived())->create();
        $this->assign($coach, $assigned->certification);
        $this->assign($coach, $archived->certification);

        $this->actingAs($coach)->get(route('qa-board.index'))->assertOk()->assertSee($assigned->title)->assertDontSee($other->title)->assertDontSee($archived->title);
        $this->get(route('qa-board.show', $assigned))->assertOk();
        $this->post(route('qa-board.replies.store', $assigned), ['body' => '担当コーチの回答'])->assertRedirect();
        $reply = QaReply::query()->sole();
        $this->get(route('qa-board.replies.edit', [$assigned, $reply]))->assertOk();
        $this->patch(route('qa-board.replies.update', [$assigned, $reply]), ['body' => 'コーチの更新'])->assertRedirect();
        $this->delete(route('qa-board.replies.destroy', [$assigned, $reply]))->assertRedirect();
        foreach ([$other, $archived] as $hidden) {
            $this->get(route('qa-board.show', $hidden))->assertForbidden();
            $this->post(route('qa-board.replies.store', $hidden), ['body' => '不正'])->assertForbidden();
        }
        $this->get(route('qa-board.create'))->assertForbidden();
        $this->post(route('qa-board.store'), ['certification_id' => $assigned->certification_id, 'title' => '不正', 'body' => '不正'])->assertForbidden();
        $this->patch(route('qa-board.update', $assigned), ['title' => '不正', 'body' => '不正'])->assertForbidden();
        $this->delete(route('qa-board.destroy', $assigned))->assertForbidden();
        $this->post(route('qa-board.resolve', $assigned))->assertForbidden();
    }

    public function test_unassigned_coach_sees_empty_list_and_cannot_reply_after_unassignment(): void
    {
        $coach = User::factory()->coach()->create();
        $thread = QaThread::factory()->create();
        $reply = QaReply::factory()->for($thread, 'thread')->for($coach, 'user')->create();
        $this->assign($coach, $thread->certification);
        CertificationCoachAssignment::query()->where('user_id', $coach->id)->update(['unassigned_at' => now()]);
        $this->actingAs($coach)->get(route('qa-board.index'))->assertOk()->assertViewHas('threads', fn ($threads) => $threads->total() === 0);
        $this->patch(route('qa-board.replies.update', [$thread, $reply]), ['body' => '不正'])->assertForbidden();
        $this->delete(route('qa-board.replies.destroy', [$thread, $reply]))->assertForbidden();
    }

    public function test_students_cannot_access_unpublished_questions_even_if_they_own_them(): void
    {
        foreach (['draft', 'archived'] as $status) {
            $thread = QaThread::factory()->for(Certification::factory()->create(['status' => $status]))->create();
            $this->actingAs($thread->user)->get(route('qa-board.show', $thread))->assertForbidden();
            $this->get(route('qa-board.edit', $thread))->assertForbidden();
            $this->patch(route('qa-board.update', $thread), ['title' => '不正', 'body' => '不正'])->assertForbidden();
            $this->delete(route('qa-board.destroy', $thread))->assertForbidden();
            $this->post(route('qa-board.resolve', $thread))->assertForbidden();
            $this->post(route('qa-board.replies.store', $thread), ['body' => '不正'])->assertForbidden();
        }
    }

    public function test_admin_moderates_all_certifications_but_cannot_post_edit_or_resolve(): void
    {
        $admin = User::factory()->admin()->create();
        $thread = QaThread::factory()->for(Certification::factory()->archived())->create();
        $reply = QaReply::factory()->for($thread, 'thread')->create();
        $this->actingAs($admin)->get(route('admin.qa-board.index'))->assertOk()->assertSee($thread->title);
        $this->get(route('admin.qa-board.show', $thread))->assertOk()->assertDontSee('action="'.route('qa-board.replies.store', $thread).'"', false);
        $this->get(route('qa-board.index'))->assertForbidden();
        $this->get(route('qa-board.edit', $thread))->assertForbidden();
        $this->patch(route('qa-board.update', $thread), ['title' => '不正', 'body' => '不正'])->assertForbidden();
        $this->post(route('qa-board.resolve', $thread))->assertForbidden();
        $this->post(route('qa-board.unresolve', $thread))->assertForbidden();
        $this->post(route('qa-board.replies.store', $thread), ['body' => '不正'])->assertForbidden();
        $this->patch(route('qa-board.replies.update', [$thread, $reply]), ['body' => '不正'])->assertForbidden();
        $this->delete(route('admin.qa-board.replies.destroy', [$thread, $reply]))->assertRedirect(route('admin.qa-board.show', $thread));
        $this->assertDatabaseMissing('qa_replies', ['id' => $reply->id]);
        $remaining = QaReply::factory()->for($thread, 'thread')->create();
        $this->delete(route('admin.qa-board.destroy', $thread))->assertRedirect(route('admin.qa-board.index'));
        $this->assertDatabaseMissing('qa_threads', ['id' => $thread->id]);
        $this->assertDatabaseMissing('qa_replies', ['id' => $remaining->id]);
    }

    public function test_coach_dashboard_only_lists_assigned_published_unanswered_questions(): void
    {
        $coach = User::factory()->coach()->create();
        $thread = QaThread::factory()->create();
        $this->assign($coach, $thread->certification);
        QaThread::factory()->for($thread->certification)->resolved()->create();
        $answered = QaThread::factory()->for($thread->certification)->create();
        QaReply::factory()->for($answered, 'thread')->create();
        $hidden = QaThread::factory()->for(Certification::factory()->archived())->create();
        $this->assign($coach, $hidden->certification);
        QaThread::factory()->create();

        $vm = app(FetchCoachDashboardAction::class)($coach);
        $this->assertSame(1, $vm->unansweredQaCount);
        $this->assertSame([$thread->id], $vm->recentQaThreads->pluck('id')->all());
        $this->assertSame(QaThreadStatus::Open, $vm->recentQaThreads->first()->status);
    }

    private function assign(User $coach, Certification $cert): void
    {
        CertificationCoachAssignment::create([
            'certification_id' => $cert->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $cert->created_by_user_id,
            'assigned_at' => now(),
        ]);
    }
}
