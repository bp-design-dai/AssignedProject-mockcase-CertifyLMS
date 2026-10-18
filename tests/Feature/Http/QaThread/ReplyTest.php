<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaThread;

use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_post_edit_and_physically_delete_own_reply(): void
    {
        Notification::fake();
        $thread = QaThread::factory()->create();
        $student = User::factory()->student()->create();
        $this->actingAs($student)->post(route('qa-board.replies.store', $thread), ['body' => '回答です', 'user_id' => $thread->user_id])
            ->assertRedirect(route('qa-board.show', $thread))->assertSessionHas('success', '回答を投稿しました。');
        $reply = QaReply::query()->sole();
        $this->assertSame($student->id, $reply->user_id);
        $this->get(route('qa-board.replies.edit', [$thread, $reply]))->assertOk();
        $this->patch(route('qa-board.replies.update', [$thread, $reply]), ['body' => '回答を更新', 'user_id' => $thread->user_id])
            ->assertRedirect(route('qa-board.show', $thread))->assertSessionHas('success', '回答を更新しました。');
        $this->assertSame('回答を更新', $reply->refresh()->body);
        $this->assertSame($student->id, $reply->user_id);
        $this->delete(route('qa-board.replies.destroy', [$thread, $reply]))->assertRedirect(route('qa-board.show', $thread));
        $this->assertDatabaseMissing('qa_replies', ['id' => $reply->id]);
        $this->assertDatabaseHas('qa_threads', ['id' => $thread->id]);
        Notification::assertNothingSent();
    }

    public function test_other_student_cannot_edit_or_delete_reply(): void
    {
        $reply = QaReply::factory()->create();
        $this->actingAs(User::factory()->student()->create())->get(route('qa-board.replies.edit', [$reply->thread, $reply]))->assertForbidden();
        $this->patch(route('qa-board.replies.update', [$reply->thread, $reply]), ['body' => '不正'])->assertForbidden();
        $this->delete(route('qa-board.replies.destroy', [$reply->thread, $reply]))->assertForbidden();
        $this->assertDatabaseHas('qa_replies', ['id' => $reply->id, 'body' => $reply->body]);
    }

    public function test_reply_cannot_be_operated_through_a_different_thread_url(): void
    {
        $reply = QaReply::factory()->create();
        $other = QaThread::factory()->create();
        $this->actingAs($reply->user)->get(route('qa-board.replies.edit', [$other, $reply]))->assertNotFound();
        $this->patch(route('qa-board.replies.update', [$other, $reply]), ['body' => '不正'])->assertNotFound();
        $this->delete(route('qa-board.replies.destroy', [$other, $reply]))->assertNotFound();
        $this->actingAs(User::factory()->admin()->create())->delete(route('admin.qa-board.replies.destroy', [$other, $reply]))->assertNotFound();
        $this->assertDatabaseHas('qa_replies', ['id' => $reply->id]);
    }

    /** @dataProvider invalidBodies */
    public function test_reply_creation_and_update_validate_body(mixed $body): void
    {
        $reply = QaReply::factory()->create();
        $this->actingAs($reply->user)->postJson(route('qa-board.replies.store', $reply->thread), ['body' => $body])
            ->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->patchJson(route('qa-board.replies.update', [$reply->thread, $reply]), ['body' => $body])
            ->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->assertDatabaseCount('qa_replies', 1);
    }

    public static function invalidBodies(): array
    {
        return ['empty' => [''], 'null' => [null], 'too long' => [str_repeat('あ', 5001)], 'array' => [['invalid']]];
    }

    public function test_reply_accepts_5000_characters(): void
    {
        $thread = QaThread::factory()->resolved()->create();
        $body = str_repeat('あ', 5000);
        $this->actingAs($thread->user)->post(route('qa-board.replies.store', $thread), ['body' => $body])->assertRedirect();
        $reply = QaReply::query()->sole();
        $this->patch(route('qa-board.replies.update', [$thread, $reply]), ['body' => $body])->assertRedirect();
        $this->assertSame($body, $reply->refresh()->body);
    }
}
