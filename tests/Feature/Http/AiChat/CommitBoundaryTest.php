<?php

declare(strict_types=1);

namespace Tests\Feature\Http\AiChat;

use App\Contracts\GeminiGateway;
use App\Exceptions\AiChatException;
use App\Models\AiChatConversation;
use App\Models\AiChatDailyUsage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('external-api')]
class CommitBoundaryTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent MySQL connection required');
        }
        if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'testing') {
            throw new \RuntimeException('Isolated testing database required');
        }
    }

    public function test_question_and_quota_are_visible_to_another_connection_before_api_failure(): void
    {
        config(['ai-chat.enabled' => true, 'ai-chat.gemini.api_key' => 'fake-key']);
        Carbon::setTestNow(Carbon::parse('2030-06-10 18:00:00', 'Asia/Tokyo'));
        Http::preventStrayRequests();
        $user = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $user->id, 'auto_title' => false]);
        config(['database.connections.sa02_probe' => config('database.connections.mysql')]);
        DB::purge('sa02_probe');
        $probe = DB::connection('sa02_probe');
        try {
            $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, (int) $probe->selectOne('SELECT CONNECTION_ID() AS id')->id);
            $this->mock(GeminiGateway::class)->shouldReceive('generate')->once()->andReturnUsing(function () use ($probe, $user, $conversation) {
                $this->assertSame(0, DB::transactionLevel());
                $this->assertSame(1, $probe->table('ai_chat_messages')->where('conversation_id', $conversation->id)->where('role', 'user')->count());
                $this->assertSame(1, (int) $probe->table('ai_chat_daily_usages')->where('user_id', $user->id)->value('attempts'));
                throw new AiChatException('transport_error');
            });
            $this->actingAs($user)->postJson(route('ai-chat.conversations.messages.store', $conversation), ['content' => '通信に失敗しても残す質問'])->assertStatus(502);
            $this->assertDatabaseHas('ai_chat_messages', ['conversation_id' => $conversation->id, 'role' => 'user', 'content' => '通信に失敗しても残す質問']);
            $this->assertDatabaseHas('ai_chat_messages', ['conversation_id' => $conversation->id, 'status' => 'error']);
            $this->assertSame(1, AiChatDailyUsage::sole()->attempts);
        } finally {
            DB::disconnect('sa02_probe');
            Carbon::setTestNow();
        }
    }
}
