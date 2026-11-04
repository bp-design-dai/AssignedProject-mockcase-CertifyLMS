<?php

declare(strict_types=1);

namespace Tests\Feature\Http\AiChat;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AiChatConversation;
use App\Models\AiChatDailyUsage;
use App\Models\AiChatMessage;
use App\Models\User;
use Database\Seeders\AiChatSeeder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AiChatTestCase;

class ConversationTest extends AiChatTestCase
{
    public function test_create_show_resume_and_delete_own_conversation(): void
    {
        $user = $this->learner();
        $id = $this->actingAs($user)->postJson(route('ai-chat.conversations.store'))->assertCreated()->json('conversation.id');
        $conversation = AiChatConversation::findOrFail($id);
        $this->send($conversation)->assertOk();
        $this->getJson(route('ai-chat.conversations.show', $id))->assertOk()->assertJsonCount(2, 'messages');
        $this->get(route('ai-chat.index'))->assertRedirect(route('ai-chat.conversations.show', $id));
        $this->deleteJson(route('ai-chat.conversations.destroy', $id))->assertNoContent();
        $this->assertDatabaseMissing('ai_chat_conversations', ['id' => $id]);
        $this->assertDatabaseCount('ai_chat_messages', 0);
        $this->assertSame(1, AiChatDailyUsage::sole()->attempts);
        $this->getJson(route('ai-chat.conversations.show', $id))->assertNotFound();
    }

    public function test_html_creation_with_first_question_redirects_with_saved_answer(): void
    {
        $user = $this->learner();
        $this->actingAs($user)->post(route('ai-chat.conversations.store'), ['source' => 'full-screen', 'message' => '最初の質問'])->assertRedirect();
        $conversation = $user->aiChatConversations()->sole();
        $this->get(route('ai-chat.conversations.show', $conversation))->assertOk()->assertSee('最初の質問')->assertSee('data-message-list', false)->assertSee('タイトルを自動更新する');
        $this->assertDatabaseCount('ai_chat_messages', 2);
    }

    public function test_empty_conversation_has_a_working_message_list_and_input(): void
    {
        $conversation = $this->conversation();
        $this->actingAs($conversation->user)->get(route('ai-chat.conversations.show', $conversation))
            ->assertOk()->assertSee('data-message-list', false)->assertSee('data-ai-chat-form', false);
    }

    public function test_empty_index_and_old_conversations_remain_accessible(): void
    {
        $user = $this->learner();
        $this->actingAs($user)->get(route('ai-chat.index'))->assertOk()->assertSee('まだ相談履歴はありません');
        $old = $this->conversation($user, ['title' => '古い相談', 'last_message_at' => now()->subYears(2)]);
        AiChatConversation::factory()->count(31)->create(['user_id' => $user->id, 'last_message_at' => now()]);
        $this->get(route('ai-chat.conversations.show', [$old, 'history_page' => 2]))->assertOk()->assertSee('古い相談');
        $this->assertDatabaseCount('ai_chat_conversations', 32);
    }

    public function test_other_students_conversations_are_forbidden_for_all_operations(): void
    {
        $conversation = $this->conversation();
        $other = $this->learner();
        $this->actingAs($other)->getJson(route('ai-chat.conversations.show', $conversation))->assertForbidden();
        $this->patchJson(route('ai-chat.conversations.update', $conversation), ['title' => '改ざん'])->assertForbidden();
        $this->deleteJson(route('ai-chat.conversations.destroy', $conversation))->assertForbidden();
        $this->postJson(route('ai-chat.conversations.messages.store', $conversation), ['content' => '不正'])->assertForbidden();
        $this->assertSame('新しい相談', $conversation->fresh()->title);
        $this->assertDatabaseCount('ai_chat_messages', 0);
        $this->assertDatabaseCount('ai_chat_daily_usages', 0);
    }

    #[DataProvider('ineligibleUsers')]
    public function test_ineligible_roles_and_statuses_cannot_use_any_endpoint(string $role, string $status): void
    {
        $conversation = $this->conversation();
        $user = User::factory()->create(['role' => $role, 'status' => $status]);
        $this->actingAs($user)->getJson(route('ai-chat.index'))->assertForbidden();
        $this->postJson(route('ai-chat.conversations.store'))->assertForbidden();
        $this->getJson(route('ai-chat.conversations.show', $conversation))->assertForbidden();
        $this->patchJson(route('ai-chat.conversations.update', $conversation), ['title' => '変更'])->assertForbidden();
        $this->deleteJson(route('ai-chat.conversations.destroy', $conversation))->assertForbidden();
        $this->postJson(route('ai-chat.conversations.messages.store', $conversation), ['content' => '質問'])->assertForbidden();
    }

    public static function ineligibleUsers(): array
    {
        return [['coach', 'in_progress'], ['admin', 'in_progress'], ['student', 'invited'], ['student', 'graduated'], ['student', 'withdrawn']];
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('ai-chat.index'))->assertRedirect(route('login'));
        $this->post(route('ai-chat.conversations.store'))->assertRedirect(route('login'));
    }

    public function test_feature_off_hides_ui_and_rejects_every_endpoint_before_lookup(): void
    {
        config(['ai-chat.enabled' => false]);
        $user = $this->learner();
        $id = '01KABC00000000000000000001';
        $this->actingAs($user)->getJson(route('ai-chat.index'))->assertNotFound();
        $this->postJson(route('ai-chat.conversations.store'))->assertNotFound();
        $this->getJson(route('ai-chat.conversations.show', $id))->assertNotFound();
        $this->patchJson(route('ai-chat.conversations.update', $id), ['title' => '変更'])->assertNotFound();
        $this->deleteJson(route('ai-chat.conversations.destroy', $id))->assertNotFound();
        $this->postJson(route('ai-chat.conversations.messages.store', $id), ['content' => '質問'])->assertNotFound();
        $this->get(route('settings.profile.edit'))->assertOk()->assertDontSee('data-ai-chat-widget', false)->assertDontSee('href="'.route('ai-chat.index').'"', false);
    }

    public function test_missing_api_key_explains_unavailability_without_writes(): void
    {
        config(['ai-chat.gemini.api_key' => '']);
        $user = $this->learner();
        $this->actingAs($user)->get(route('ai-chat.index'))->assertStatus(503)->assertSee('接続設定が準備されるまでお待ちください');
        $this->postJson(route('ai-chat.conversations.store'))->assertStatus(503);
        $this->get(route('settings.profile.edit'))->assertOk()->assertSee('data-ai-chat-widget', false)->assertSee('AI相談は現在利用できません');
        $this->assertDatabaseCount('ai_chat_conversations', 0);
        $this->assertDatabaseCount('ai_chat_daily_usages', 0);
        $this->gateway->shouldNotHaveReceived('generate');
    }

    public function test_mass_assignment_does_not_change_owner_or_internal_fields(): void
    {
        $user = $this->learner();
        $other = $this->learner();
        $id = $this->actingAs($user)->postJson(route('ai-chat.conversations.store'), ['user_id' => $other->id, 'title' => '無断指定', 'question_count' => 900])->assertCreated()->json('conversation.id');
        $this->assertDatabaseHas('ai_chat_conversations', ['id' => $id, 'user_id' => $user->id, 'title' => '新しい相談', 'question_count' => 0]);
        $this->patchJson(route('ai-chat.conversations.update', $id), ['title' => '本人の変更', 'user_id' => $other->id])->assertOk();
        $this->assertDatabaseHas('ai_chat_conversations', ['id' => $id, 'user_id' => $user->id, 'auto_title' => false]);
    }

    #[DataProvider('titleLengths')]
    public function test_manual_title_validation(int $length, int $status): void
    {
        $conversation = $this->conversation();
        $this->actingAs($conversation->user)->patchJson(route('ai-chat.conversations.update', $conversation), ['title' => str_repeat('あ', $length)])->assertStatus($status);
    }

    public static function titleLengths(): array
    {
        return [[0, 422], [1, 200], [50, 200], [51, 422]];
    }

    public function test_metadata_is_internal_and_absent_from_json_and_html(): void
    {
        $conversation = $this->conversation();
        $this->send($conversation)->assertOk()->assertDontSee('internal-test-model')->assertDontSee('input_tokens')->assertDontSee('response_time_ms')->assertDontSee('sa02-fake-key-never-real');
        $answer = $conversation->messages()->where('role', AiChatMessageRole::Assistant)->sole();
        $this->assertSame('internal-test-model', $answer->model);
        $this->assertSame(37, $answer->output_tokens);
        $this->assertSame(25, $answer->response_time_ms);
        $this->getJson(route('ai-chat.conversations.show', $conversation))->assertOk()->assertDontSee('internal-test-model')->assertDontSee('output_tokens')->assertDontSee('response_time_ms');
        $this->get(route('ai-chat.conversations.show', $conversation))->assertOk()->assertSee('AI 相談')->assertSee('18:00')->assertDontSee('internal-test-model')->assertDontSee('test-config-model')->assertDontSee(' tokens');
        $this->get(route('settings.profile.edit'))->assertOk()->assertSee('data-ai-chat-widget', false)->assertDontSee('test-config-model');
        $this->assertArrayNotHasKey('model', $answer->toArray());
        $this->assertArrayNotHasKey('title_metadata', $conversation->fresh()->toArray());
    }

    public function test_widget_is_hidden_for_non_students_and_on_ai_screen(): void
    {
        $conversation = $this->conversation();
        $this->actingAs($conversation->user)->get(route('ai-chat.conversations.show', $conversation))->assertOk()->assertDontSee('data-ai-chat-widget', false);
        foreach ([UserRole::Coach, UserRole::Admin] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'status' => UserStatus::InProgress]))->get(route('settings.profile.edit'))->assertOk()->assertDontSee('data-ai-chat-widget', false);
        }
        $this->actingAs(User::factory()->student()->create(['status' => UserStatus::Graduated]))->get(route('settings.profile.edit'))->assertOk()->assertDontSee('data-ai-chat-widget', false);
    }

    public function test_seed_demo_history_including_retry_is_idempotent_and_offline(): void
    {
        [$user] = $this->context();
        $user->update(['email' => 'student@certify-lms.test']);
        $this->seed(AiChatSeeder::class);
        $this->seed(AiChatSeeder::class);
        $this->assertDatabaseCount('ai_chat_conversations', 3);
        $this->assertDatabaseCount('ai_chat_messages', 6);
        $errorConversation = AiChatMessage::where('status', AiChatMessageStatus::Error)->sole()->conversation;
        $this->actingAs($user)->get(route('ai-chat.conversations.show', $errorConversation))->assertOk()->assertSee('同じ質問を再送');
        $this->gateway->shouldNotHaveReceived('generate');
    }
}
