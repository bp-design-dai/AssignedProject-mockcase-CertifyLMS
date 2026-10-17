<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThreadLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_create_edit_and_resolve_question_without_enrollment(): void
    {
        $student = User::factory()->student()->create();
        $cert = Certification::factory()->published()->create();
        $otherCert = Certification::factory()->published()->create();
        $this->actingAs($student)->get(route('qa-board.create'))->assertOk()->assertSee($cert->name);
        $this->post(route('qa-board.store'), ['certification_id' => $cert->id, 'title' => '学習について', 'body' => '本文', 'user_id' => $cert->created_by_user_id, 'status' => 'resolved'])
            ->assertSessionHas('success', '質問を投稿しました。');
        $thread = QaThread::query()->sole();
        $this->assertSame($student->id, $thread->user_id);
        $this->assertSame(QaThreadStatus::Open, $thread->status);
        $this->assertNull($thread->resolved_at);
        $this->get(route('qa-board.edit', $thread))->assertOk();
        $this->patch(route('qa-board.update', $thread), ['title' => '更新後', 'body' => '本文更新', 'certification_id' => $otherCert->id, 'user_id' => $cert->created_by_user_id])
            ->assertRedirect(route('qa-board.show', $thread))->assertSessionHas('success', '質問を更新しました。');
        $thread->refresh();
        $this->assertSame('更新後', $thread->title);
        $this->assertSame($cert->id, $thread->certification_id);
        $this->assertSame($student->id, $thread->user_id);

        $this->post(route('qa-board.resolve', $thread))->assertRedirect(route('qa-board.show', $thread));
        $this->assertSame(QaThreadStatus::Resolved, $thread->refresh()->status);
        $this->assertNotNull($thread->resolved_at);
        $this->post(route('qa-board.unresolve', $thread))->assertRedirect(route('qa-board.show', $thread));
        $this->assertSame(QaThreadStatus::Open, $thread->refresh()->status);
        $this->assertNull($thread->resolved_at);
    }

    public function test_owner_can_physically_delete_question_only_without_replies(): void
    {
        $thread = QaThread::factory()->create();
        $this->actingAs($thread->user)->get(route('qa-board.show', $thread))->assertOk()
            ->assertSee('action="'.route('qa-board.destroy', $thread).'"', false);
        $this->delete(route('qa-board.destroy', $thread))->assertRedirect(route('qa-board.index'))->assertSessionHas('success', '質問を削除しました。');
        $this->assertDatabaseMissing('qa_threads', ['id' => $thread->id]);
    }

    public function test_owner_cannot_delete_after_a_reply_but_can_still_edit_and_resolve(): void
    {
        $thread = QaThread::factory()->create();
        QaReply::factory()->for($thread, 'thread')->create();
        $this->actingAs($thread->user)->get(route('qa-board.show', $thread))->assertOk()
            ->assertDontSee('action="'.route('qa-board.destroy', $thread).'"', false);
        $this->delete(route('qa-board.destroy', $thread))->assertForbidden();
        $this->patch(route('qa-board.update', $thread), ['title' => '更新', 'body' => '本文'])->assertRedirect();
        $this->post(route('qa-board.resolve', $thread))->assertRedirect();
        $this->assertDatabaseHas('qa_threads', ['id' => $thread->id]);
        $this->assertDatabaseCount('qa_replies', 1);
    }

    public function test_other_student_cannot_edit_delete_or_change_resolution(): void
    {
        $thread = QaThread::factory()->create();
        $this->actingAs(User::factory()->student()->create())->get(route('qa-board.show', $thread))->assertOk();
        $this->get(route('qa-board.edit', $thread))->assertForbidden();
        $this->patch(route('qa-board.update', $thread), ['title' => '不正', 'body' => '不正'])->assertForbidden();
        $this->delete(route('qa-board.destroy', $thread))->assertForbidden();
        $this->post(route('qa-board.resolve', $thread))->assertForbidden();
        $this->post(route('qa-board.unresolve', $thread))->assertForbidden();
    }

    /** @dataProvider invalidQuestionInputs */
    public function test_creation_and_update_validate_required_fields_and_lengths(array $invalid, string $field): void
    {
        $thread = QaThread::factory()->create();
        $originalTitle = $thread->title;
        $originalBody = $thread->body;
        $valid = ['certification_id' => $thread->certification_id, 'title' => '質問', 'body' => '本文'];
        $this->actingAs($thread->user)->postJson(route('qa-board.store'), array_replace($valid, $invalid))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        if ($field !== 'certification_id') {
            $this->patchJson(route('qa-board.update', $thread), array_replace($valid, $invalid))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('qa_threads', 1);
        $this->assertSame($originalTitle, $thread->refresh()->title);
        $this->assertSame($originalBody, $thread->body);
    }

    public static function invalidQuestionInputs(): array
    {
        return [
            'no qualification' => [['certification_id' => null], 'certification_id'],
            'invalid qualification' => [['certification_id' => 'invalid'], 'certification_id'],
            'unknown qualification' => [['certification_id' => '01aaaaaaaaaaaaaaaaaaaaaaaa'], 'certification_id'],
            'no title' => [['title' => ''], 'title'],
            'long title' => [['title' => str_repeat('あ', 201)], 'title'],
            'array title' => [['title' => ['invalid']], 'title'],
            'no body' => [['body' => ''], 'body'],
            'long body' => [['body' => str_repeat('あ', 5001)], 'body'],
            'array body' => [['body' => ['invalid']], 'body'],
        ];
    }

    public function test_exact_text_limits_are_accepted_for_create_and_update(): void
    {
        $student = User::factory()->student()->create();
        $cert = Certification::factory()->published()->create();
        $payload = ['certification_id' => $cert->id, 'title' => str_repeat('あ', 200), 'body' => str_repeat('あ', 5000)];
        $this->actingAs($student)->post(route('qa-board.store'), $payload)->assertRedirect();
        $thread = QaThread::query()->sole();
        $this->patch(route('qa-board.update', $thread), $payload)->assertRedirect();
        $this->assertSame($payload['body'], $thread->refresh()->body);
    }

    public function test_student_cannot_post_to_unpublished_qualification(): void
    {
        foreach (['draft', 'archived'] as $status) {
            $cert = Certification::factory()->create(['status' => $status]);
            $this->actingAs(User::factory()->student()->create())->postJson(route('qa-board.store'), ['certification_id' => $cert->id, 'title' => '質問', 'body' => '本文'])
                ->assertUnprocessable()->assertJsonValidationErrors('certification_id');
        }
        $this->assertDatabaseCount('qa_threads', 0);
    }
}
