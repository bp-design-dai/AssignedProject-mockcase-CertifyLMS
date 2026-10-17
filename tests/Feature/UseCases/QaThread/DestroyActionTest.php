<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\QaThread;

use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use App\UseCases\QaThread\DestroyAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestroyActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_reply_added_after_authorization_is_rechecked_in_delete_transaction(): void
    {
        $thread = QaThread::factory()->create();
        $owner = $thread->user;
        $thread->load('replies')->loadCount('replies');
        $this->assertTrue($owner->can('delete', $thread));
        QaReply::factory()->for($thread, 'thread')->create();

        try {
            app(DestroyAction::class)($thread, $owner);
            $this->fail('回答追加後の削除は拒否する必要がある');
        } catch (AuthorizationException) {
            $this->assertDatabaseHas('qa_threads', ['id' => $thread->id]);
            $this->assertDatabaseCount('qa_replies', 1);
        }
    }

    public function test_direct_action_call_cannot_delete_another_students_question(): void
    {
        $thread = QaThread::factory()->create();
        $this->expectException(AuthorizationException::class);
        app(DestroyAction::class)($thread, User::factory()->student()->create());
    }
}
