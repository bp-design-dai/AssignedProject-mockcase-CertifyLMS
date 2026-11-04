<?php

declare(strict_types=1);

namespace Tests\Feature\Http\AiChat;

use App\Data\GeminiResponse;
use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Exceptions\AiChatException;
use App\Models\AiChatDailyUsage;
use App\Models\AiChatMessage;
use App\UseCases\AiChat\DestroyAction;
use App\UseCases\AiChat\UpdateAction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AiChatTestCase;

class MessageTest extends AiChatTestCase
{
    #[DataProvider('messageLengths')]
    public function test_message_length_and_whitespace_validation(string $text, int $expected): void
    {
        $conversation = $this->conversation();
        $this->send($conversation, $text)->assertStatus($expected);
        $this->assertDatabaseCount('ai_chat_messages', $expected === 200 ? 2 : 0);
        $this->assertDatabaseCount('ai_chat_daily_usages', $expected === 200 ? 1 : 0);
    }

    public static function messageLengths(): array
    {
        return [['', 422], ['   ', 422], ['a', 200], [str_repeat('あ', 2000), 200], [str_repeat('あ', 2001), 422]];
    }

    public function test_first_success_generates_title_then_each_five_questions_updates_it(): void
    {
        $conversation = $this->conversation();
        $this->gateway->shouldReceive('generate')->times(9)->andReturnUsing(function ($contents, $system) {
            return new GeminiResponse(str_contains($system, 'タイトルだけ') ? '自動のタイトル' : '回答です', 'internal-model');
        });
        for ($i = 1; $i <= 7; $i++) {
            $this->send($conversation, '質問'.$i)->assertOk();
        }
        $conversation->refresh();
        $this->assertSame('自動のタイトル', $conversation->title);
        $this->assertSame(6, $conversation->title_question_count);
        $this->assertSame(7, $conversation->question_count);
        $this->assertSame(7, AiChatDailyUsage::sole()->attempts);
    }

    public function test_manual_title_turns_off_auto_and_reenabling_resumes_updates(): void
    {
        $conversation = $this->conversation();
        $this->send($conversation)->assertOk();
        $this->patchJson(route('ai-chat.conversations.update', $conversation), ['title' => '自分のタイトル', 'auto_title' => true])->assertOk()->assertJsonPath('conversation.auto_title', false);
        for ($i = 0; $i < 5; $i++) {
            $this->send($conversation)->assertOk();
        }
        $this->assertSame('自分のタイトル', $conversation->fresh()->title);
        $this->patchJson(route('ai-chat.conversations.update', $conversation), ['auto_title' => true])->assertOk();
        $this->send($conversation)->assertOk();
        $this->assertNotSame('自分のタイトル', $conversation->fresh()->title);
        $this->assertSame(7, $conversation->fresh()->title_question_count);
    }

    public function test_auto_title_is_capped_at_fifty_unicode_characters(): void
    {
        $conversation = $this->conversation();
        $this->gateway->shouldReceive('generate')->andReturn(new GeminiResponse(str_repeat('あ', 80), 'test'));
        $this->send($conversation)->assertOk();
        $this->assertSame(50, mb_strlen($conversation->fresh()->title));
    }

    public function test_title_failure_keeps_answer_and_retries_title_on_next_success(): void
    {
        $conversation = $this->conversation();
        $this->gateway->shouldReceive('generate')->andReturnUsing(function ($contents, $system) {
            if (str_contains($system, 'タイトルだけ')) {
                throw new AiChatException('empty_response');
            }

            return new GeminiResponse('通常回答成功', 'test');
        });
        $this->send($conversation)->assertOk()->assertJsonPath('assistant_message.content', '通常回答成功');
        $this->assertSame('新しい相談', $conversation->fresh()->title);
        $this->assertSame(0, $conversation->fresh()->title_question_count);
        $this->assertSame('error', $conversation->fresh()->title_metadata['status']);
    }

    public function test_question_and_quota_are_committed_before_provider_failure_and_retry_counts_again(): void
    {
        $conversation = $this->conversation();
        $this->gateway->shouldReceive('generate')->once()->andThrow(new AiChatException('transport_error'));
        $this->send($conversation, '保存される質問')->assertStatus(502)->assertJsonPath('user_message.content', '保存される質問')->assertJsonPath('assistant_message.status', 'error');
        $this->assertDatabaseCount('ai_chat_messages', 2);
        $this->assertSame(1, AiChatDailyUsage::sole()->attempts);
        $this->assertSame('新しい相談', $conversation->fresh()->title);
        $this->get(route('ai-chat.conversations.show', $conversation))->assertOk()->assertSee('保存される質問')->assertSee('同じ質問を再送');
        $this->gateway->shouldReceive('generate')->andReturn(new GeminiResponse('再送成功', 'test'));
        $this->send($conversation, '保存される質問')->assertOk();
        $this->assertSame(2, AiChatDailyUsage::sole()->attempts);
        $this->assertDatabaseCount('ai_chat_messages', 4);
    }

    public function test_unexpected_provider_errors_are_sanitized_in_response_and_log(): void
    {
        Log::spy();
        Log::shouldReceive('channel')->with('ai-chat')->andReturnSelf();
        Log::shouldReceive('warning')->once()->with('AI response failed', \Mockery::on(fn ($context) => ! str_contains(json_encode($context), 'SECRET-API-KEY')));
        $conversation = $this->conversation();
        $this->gateway->shouldReceive('generate')->andThrow(new \RuntimeException('SECRET-API-KEY and private provider body'));
        $this->send($conversation)->assertStatus(502)->assertDontSee('SECRET-API-KEY')->assertDontSee('private provider body')->assertDontSee('internal_error');
        $answer = $conversation->messages()->where('role', AiChatMessageRole::Assistant)->sole();
        $this->assertSame('internal_error', $answer->error_code);
        $this->assertStringNotContainsString('SECRET-API-KEY', $answer->content);
        Log::shouldHaveReceived('channel')->with('ai-chat');
    }

    public function test_daily_thirtieth_send_succeeds_and_thirty_first_has_no_question_or_provider_call(): void
    {
        $conversation = $this->conversation(null, ['auto_title' => false]);
        AiChatDailyUsage::create(['user_id' => $conversation->user_id, 'usage_date' => '2030-06-10', 'attempts' => 29]);
        $this->gateway->shouldReceive('generate')->once()->andReturn(new GeminiResponse('30回目', 'test'));
        $this->send($conversation)->assertOk();
        $this->send($conversation)->assertStatus(429)->assertSee('0:00');
        $this->assertSame(30, AiChatDailyUsage::sole()->attempts);
        $this->assertDatabaseCount('ai_chat_messages', 2);
    }

    public function test_quota_is_shared_across_own_conversations_but_not_other_students(): void
    {
        config(['ai-chat.daily_limit' => 1]);
        $first = $this->conversation(null, ['auto_title' => false]);
        $second = $this->conversation($first->user);
        $third = $this->conversation(null, ['auto_title' => false]);
        $this->send($first)->assertOk();
        $this->send($second)->assertStatus(429);
        $this->send($third)->assertOk();
        $this->assertSame(2, (int) AiChatDailyUsage::sum('attempts'));
    }

    public function test_failed_requests_and_retries_count_and_zero_limit_is_enforced(): void
    {
        config(['ai-chat.daily_limit' => 2]);
        $conversation = $this->conversation();
        $this->gateway->shouldReceive('generate')->twice()->andThrow(new AiChatException('empty_response'));
        $this->send($conversation)->assertStatus(502);
        $this->send($conversation)->assertStatus(502);
        $this->send($conversation)->assertStatus(429);
        $this->assertSame(2, AiChatDailyUsage::sole()->attempts);
        config(['ai-chat.daily_limit' => 0]);
        $this->send($this->conversation())->assertStatus(429);
    }

    public function test_quota_resets_at_japan_midnight_even_when_application_timezone_is_utc(): void
    {
        config(['app.timezone' => 'UTC', 'ai-chat.daily_limit' => 1]);
        $conversation = $this->conversation(null, ['auto_title' => false]);
        Carbon::setTestNow(Carbon::parse('2030-06-10 14:59:59', 'UTC'));
        $this->send($conversation)->assertOk();
        $this->send($conversation)->assertStatus(429);
        Carbon::setTestNow(Carbon::parse('2030-06-10 15:00:00', 'UTC'));
        $this->send($conversation)->assertOk();
        $this->assertDatabaseHas('ai_chat_daily_usages', ['usage_date' => '2030-06-10', 'attempts' => 1]);
        $this->assertDatabaseHas('ai_chat_daily_usages', ['usage_date' => '2030-06-11', 'attempts' => 1]);
    }

    public function test_history_is_bounded_excludes_errors_and_other_conversations_but_saved_history_is_full(): void
    {
        $conversation = $this->conversation(null, ['auto_title' => false]);
        for ($i = 0; $i < 12; $i++) {
            AiChatMessage::factory()->create(['conversation_id' => $conversation->id, 'sequence' => $i * 2 + 1, 'content' => '質問'.$i]);
            AiChatMessage::factory()->create(['conversation_id' => $conversation->id, 'sequence' => $i * 2 + 2, 'role' => AiChatMessageRole::Assistant, 'content' => '回答'.$i]);
        }
        $other = $this->conversation();
        AiChatMessage::factory()->create(['conversation_id' => $other->id, 'content' => '他人の秘密']);
        $this->gateway->shouldReceive('generate')->once()->andReturnUsing(function ($contents) {
            $this->assertLessThanOrEqual(20, count($contents));
            $this->assertSame('user', $contents[0]['role']);
            $joined = json_encode($contents, JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('質問0"', $joined);
            $this->assertStringNotContainsString('他人の秘密', $joined);
            $this->assertSame('最新の質問', end($contents)['parts'][0]['text']);

            return new GeminiResponse('最新回答', 'test');
        });
        $this->send($conversation, '最新の質問')->assertOk();
        $this->getJson(route('ai-chat.conversations.show', $conversation))->assertOk()->assertJsonCount(26, 'messages');
    }

    public function test_pending_answer_blocks_interleaving_without_consuming_quota(): void
    {
        $conversation = $this->conversation();
        AiChatMessage::factory()->create(['conversation_id' => $conversation->id, 'role' => AiChatMessageRole::Assistant, 'status' => AiChatMessageStatus::Pending, 'content' => '']);
        $this->send($conversation)->assertStatus(409);
        $this->assertDatabaseCount('ai_chat_daily_usages', 0);
        $this->assertDatabaseCount('ai_chat_messages', 1);
    }

    public function test_interrupted_old_answer_can_be_retried_without_refunding_past_usage(): void
    {
        $conversation = $this->conversation(null, ['auto_title' => false]);
        $old = AiChatMessage::factory()->create(['conversation_id' => $conversation->id, 'role' => AiChatMessageRole::Assistant, 'status' => AiChatMessageStatus::Pending, 'created_at' => now()->subMinutes(3)]);
        $this->send($conversation)->assertOk();
        $this->assertSame(AiChatMessageStatus::Error, $old->fresh()->status);
        $this->assertSame('interrupted', $old->fresh()->error_code);
    }

    public function test_user_can_delete_during_provider_call_without_resurrecting_messages(): void
    {
        $conversation = $this->conversation(null, ['auto_title' => false]);
        $this->gateway->shouldReceive('generate')->andReturnUsing(function () use ($conversation) {
            app(DestroyAction::class)($conversation->user, $conversation->id);

            return new GeminiResponse('削除後の回答', 'test');
        });
        $this->send($conversation)->assertNotFound();
        $this->assertDatabaseCount('ai_chat_messages', 0);
        $this->assertDatabaseCount('ai_chat_conversations', 0);
        $this->assertSame(1, AiChatDailyUsage::sole()->attempts);
    }

    public function test_manual_title_change_during_provider_call_is_not_overwritten(): void
    {
        $conversation = $this->conversation();
        $this->gateway->shouldReceive('generate')->andReturnUsing(function () use ($conversation) {
            app(UpdateAction::class)($conversation->user, $conversation->id, ['title' => '送信中の手動編集']);

            return new GeminiResponse('自動回答', 'test');
        });
        $this->send($conversation)->assertOk();
        $this->assertSame('送信中の手動編集', $conversation->fresh()->title);
        $this->assertFalse($conversation->fresh()->auto_title);
    }

    public function test_failed_turns_also_bound_the_history_to_the_latest_ten_questions(): void
    {
        $conversation = $this->conversation(null, ['auto_title' => false]);
        for ($i = 0; $i < 12; $i++) {
            AiChatMessage::factory()->create(['conversation_id' => $conversation->id, 'sequence' => $i * 2 + 1, 'content' => 'failed-question-'.$i]);
            AiChatMessage::factory()->create(['conversation_id' => $conversation->id, 'sequence' => $i * 2 + 2, 'role' => AiChatMessageRole::Assistant, 'status' => AiChatMessageStatus::Error, 'content' => 'private-error-not-a-prompt']);
        }
        $this->gateway->shouldReceive('generate')->once()->andReturnUsing(function ($contents) {
            $this->assertCount(1, $contents);
            $this->assertCount(10, $contents[0]['parts']);
            $this->assertStringNotContainsString('private-error-not-a-prompt', json_encode($contents));
            $this->assertStringNotContainsString('failed-question-0"', json_encode($contents));

            return new GeminiResponse('再開できました', 'test');
        });
        $this->send($conversation)->assertOk();
        $this->assertSame(26, $conversation->messages()->count());
    }

    public function test_whitespace_title_does_not_replace_the_existing_title_with_empty_text(): void
    {
        $conversation = $this->conversation();
        $this->gateway->shouldReceive('generate')->andReturnUsing(fn ($contents, $system) => new GeminiResponse(str_contains($system, 'タイトルだけ') ? '   ' : '回答成功', 'test'));
        $this->send($conversation)->assertOk();
        $this->assertSame('新しい相談', $conversation->fresh()->title);
        $this->assertSame(0, $conversation->fresh()->title_question_count);
        $this->assertSame('error', $conversation->fresh()->title_metadata['status']);
    }

    public function test_log_destination_failure_does_not_hide_the_saved_question_or_api_error(): void
    {
        Log::shouldReceive('channel')->with('ai-chat')->andThrow(new \RuntimeException('log unavailable'));
        $conversation = $this->conversation();
        $this->gateway->shouldReceive('generate')->andThrow(new AiChatException('transport_error'));
        $this->send($conversation)->assertStatus(502)->assertJsonPath('assistant_message.status', 'error');
        $this->assertDatabaseCount('ai_chat_messages', 2);
        $this->assertSame(1, AiChatDailyUsage::sole()->attempts);
    }
}
