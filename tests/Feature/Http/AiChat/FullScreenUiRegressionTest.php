<?php

declare(strict_types=1);

namespace Tests\Feature\Http\AiChat;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Exceptions\AiChatException;
use App\Models\AiChatConversation;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AiChatTestCase;

class FullScreenUiRegressionTest extends AiChatTestCase
{
    #[DataProvider('autoStates')]
    public function test_unchanged_form_save_preserves_title_and_auto_state_on_redisplay(bool $auto): void
    {
        $conversation = $this->conversation(attributes: ['title' => '保存されたAIタイトル', 'auto_title' => $auto]);
        $this->actingAs($conversation->user)->patch(route('ai-chat.conversations.update', $conversation), ['title' => $conversation->title, 'auto_title' => $auto])->assertRedirect(route('ai-chat.conversations.show', $conversation));
        $this->assertSame('保存されたAIタイトル', $conversation->fresh()->title);
        $this->assertSame($auto, $conversation->fresh()->auto_title);
        $this->assertEditState($conversation, '保存されたAIタイトル', $auto);
    }

    public static function autoStates(): array
    {
        return [[true], [false]];
    }

    public function test_unchanged_generated_title_can_be_omitted_from_form_and_survives_save(): void
    {
        $conversation = $this->conversation();
        $this->send($conversation)->assertOk();
        $generated = $conversation->fresh()->title;
        $this->assertNotSame('新しい相談', $generated);
        $this->patch(route('ai-chat.conversations.update', $conversation), ['auto_title' => '1'])->assertRedirect();
        $this->assertSame($generated, $conversation->fresh()->title);
        $this->assertTrue($conversation->fresh()->auto_title);
        $this->assertEditState($conversation, $generated, true);
    }

    public function test_real_manual_title_change_disables_auto_and_can_be_reenabled_without_resubmitting_title(): void
    {
        $conversation = $this->conversation();
        $this->send($conversation)->assertOk();
        $this->patch(route('ai-chat.conversations.update', $conversation), ['title' => '本人の手動タイトル', 'auto_title' => '1'])->assertRedirect();
        $this->assertEditState($conversation, '本人の手動タイトル', false);
        $this->patch(route('ai-chat.conversations.update', $conversation), ['auto_title' => '1'])->assertRedirect();
        $this->assertEditState($conversation, '本人の手動タイトル', true);
        $this->patch(route('ai-chat.conversations.update', $conversation), ['auto_title' => '0'])->assertRedirect();
        $this->assertEditState($conversation, '本人の手動タイトル', false);
    }

    public function test_empty_notice_exists_only_for_an_empty_conversation_even_after_switching(): void
    {
        $user = $this->learner();
        $empty = $this->conversation($user);
        $saved = $this->conversation($user);
        $this->actingAs($user)->get(route('ai-chat.conversations.show', $empty))->assertOk()->assertSee('data-ai-chat-empty-state', false);
        $this->send($saved)->assertOk();
        $this->get(route('ai-chat.conversations.show', $saved))->assertOk()->assertDontSee('data-ai-chat-empty-state', false);
        $this->get(route('ai-chat.conversations.show', $empty))->assertOk()->assertSee('data-ai-chat-empty-state', false);
    }

    public function test_failed_first_answer_keeps_question_and_does_not_restore_empty_notice_on_reload(): void
    {
        $conversation = $this->conversation();
        $this->gateway->shouldReceive('generate')->once()->andThrow(new AiChatException('transport_error'));
        $this->send($conversation, '失敗しても保存される質問')->assertStatus(502);
        $this->get(route('ai-chat.conversations.show', $conversation))->assertOk()->assertSee('失敗しても保存される質問')->assertSee('同じ質問を再送')->assertDontSee('data-ai-chat-empty-state', false);
        $this->assertSame(1, $conversation->messages()->where('role', AiChatMessageRole::User)->count());
    }

    public function test_question_without_an_answer_also_has_no_empty_notice(): void
    {
        $conversation = $this->conversation();
        $conversation->messages()->create(['sequence' => 1, 'role' => AiChatMessageRole::User, 'status' => AiChatMessageStatus::Completed, 'content' => '保存済みの質問のみ']);
        $this->actingAs($conversation->user)->get(route('ai-chat.conversations.show', $conversation))->assertOk()->assertSee('保存済みの質問のみ')->assertDontSee('data-ai-chat-empty-state', false);
    }

    private function assertEditState(AiChatConversation $conversation, string $title, bool $auto): void
    {
        $html = $this->get(route('ai-chat.conversations.show', $conversation))->assertOk()->getContent();
        $dom = new DOMDocument;
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);
        $field = $xpath->query('//*[@id="rename-ai-chat-form"]//input[@name="title"]')->item(0);
        $checkbox = $xpath->query('//*[@id="rename-ai-chat-form"]//input[@type="checkbox"]')->item(0);
        $this->assertSame($title, $field->getAttribute('value'));
        $this->assertSame($auto, $checkbox->hasAttribute('checked'));
        $this->assertSame($title, $conversation->fresh()->title);
        $this->assertSame($auto, $conversation->fresh()->auto_title);
    }
}
