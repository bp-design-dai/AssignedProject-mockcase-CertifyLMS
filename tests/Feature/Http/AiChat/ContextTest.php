<?php

declare(strict_types=1);

namespace Tests\Feature\Http\AiChat;

use App\Enums\CertificationStatus;
use App\Enums\ContentStatus;
use App\Enums\EnrollmentStatus;
use App\Models\AiChatConversation;
use App\Models\Enrollment;
use App\Models\LearningSession;
use App\Services\AiChatContextService;
use App\UseCases\AiChat\StoreAction;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AiChatTestCase;

#[Group('external-api')]
class ContextTest extends AiChatTestCase
{
    public function test_same_section_reuses_own_conversation_and_other_students_are_separate(): void
    {
        [$user, $enrollment, $section] = $this->context();
        $first = $this->actingAs($user)->postJson(route('ai-chat.conversations.store'), ['section_id' => $section->id])->assertCreated()->json('conversation.id');
        $this->postJson(route('ai-chat.conversations.store'), ['section_id' => $section->id])->assertOk()->assertJsonPath('conversation.id', $first);
        $other = $this->learner();
        Enrollment::factory()->create(['user_id' => $other->id, 'certification_id' => $enrollment->certification_id]);
        $second = $this->actingAs($other)->postJson(route('ai-chat.conversations.store'), ['section_id' => $section->id])->assertCreated()->json('conversation.id');
        $this->assertNotSame($first, $second);
        $this->assertDatabaseCount('ai_chat_conversations', 2);
    }

    public function test_other_sections_are_distinct_and_deleted_section_conversations_can_be_recreated(): void
    {
        [$user, , $section] = $this->context();
        [, , $another] = $this->context($user);
        $first = app(StoreAction::class)($user, ['section_id' => $section->id])['conversation'];
        $second = app(StoreAction::class)($user, ['section_id' => $another->id])['conversation'];
        $this->assertNotSame($first->id, $second->id);
        $this->actingAs($user)->deleteJson(route('ai-chat.conversations.destroy', $first))->assertNoContent();
        $this->postJson(route('ai-chat.conversations.store'), ['section_id' => $section->id])->assertCreated();
    }

    public function test_explicit_certification_takes_priority_over_last_learning_and_default(): void
    {
        [$user, $first, $section] = $this->context();
        [, $second] = $this->context($user);
        LearningSession::factory()->forEnrollment($first)->forSection($section)->create(['started_at' => now()]);
        $user->update(['default_enrollment_id' => $first->id]);
        $id = $this->actingAs($user)->postJson(route('ai-chat.conversations.store'), ['certification_id' => $second->certification_id])->assertCreated()->json('conversation.id');
        $this->assertSame($second->id, AiChatConversation::findOrFail($id)->enrollment_id);
        $this->assertNull(AiChatConversation::findOrFail($id)->section_id);
    }

    public function test_general_page_uses_last_learning_not_default_or_another_user(): void
    {
        [$user, $first, $section1] = $this->context();
        [, $second, $section2] = $this->context($user);
        [$other, $third, $section3] = $this->context();
        $user->update(['default_enrollment_id' => $first->id]);
        LearningSession::factory()->forEnrollment($first)->forSection($section1)->create(['started_at' => now()->subHours(3)]);
        LearningSession::factory()->forEnrollment($second)->forSection($section2)->create(['started_at' => now()->subHour()]);
        LearningSession::factory()->forEnrollment($third)->forSection($section3)->create(['started_at' => now()]);
        $context = app(AiChatContextService::class)->resolve($user);
        $this->assertSame($second->id, $context['enrollment']->id);
        $this->assertNull($context['section']);
    }

    public function test_no_learning_history_does_not_invent_a_default_certification(): void
    {
        [$user, $enrollment] = $this->context();
        $user->update(['default_enrollment_id' => $enrollment->id]);
        $context = app(AiChatContextService::class)->resolve($user);
        $this->assertNull($context['enrollment']);
        $conversation = app(StoreAction::class)($user, [])['conversation'];
        $this->assertNull($conversation->enrollment_id);
        $this->assertSame(config('ai-chat.system_prompt'), app(AiChatContextService::class)->prompt($conversation));
    }

    public function test_public_unregistered_certification_context_does_not_grant_material_access(): void
    {
        [, $enrollment, $section] = $this->context();
        $user = $this->learner();
        $this->actingAs($user)->get(route('certifications.show', $enrollment->certification))->assertOk()->assertSee('data-certification-id="'.$enrollment->certification_id.'"', false);
        $id = $this->postJson(route('ai-chat.conversations.store'), ['certification_id' => $enrollment->certification_id])->assertCreated()->assertJsonPath('conversation.context.certification_name', $enrollment->certification->name)->json('conversation.id');
        $conversation = AiChatConversation::findOrFail($id);
        $this->assertNull($conversation->enrollment_id);
        $this->assertNull($conversation->section_id);
        $this->assertStringContainsString($enrollment->certification->name, app(AiChatContextService::class)->prompt($conversation));
        $this->assertStringNotContainsString($section->body, app(AiChatContextService::class)->prompt($conversation));
        $this->postJson(route('ai-chat.conversations.store'), ['section_id' => $section->id])->assertForbidden();
        $enrollment->certification->update(['status' => CertificationStatus::Archived]);
        $this->assertSame(config('ai-chat.system_prompt'), app(AiChatContextService::class)->prompt($conversation->fresh()));
        $this->getJson(route('ai-chat.conversations.show', $conversation))->assertOk()->assertJsonPath('conversation.context.certification_id', null);
    }

    public function test_hidden_material_or_stopped_enrollment_cannot_supply_context(): void
    {
        [$user, $enrollment, $section] = $this->context();
        $section->update(['status' => ContentStatus::Draft]);
        $this->actingAs($user)->postJson(route('ai-chat.conversations.store'), ['section_id' => $section->id])->assertNotFound();
        $section->update(['status' => ContentStatus::Published]);
        $enrollment->certification->update(['status' => CertificationStatus::Archived]);
        $this->postJson(route('ai-chat.conversations.store'), ['section_id' => $section->id])->assertNotFound();
        $enrollment->certification->update(['status' => CertificationStatus::Published]);
        $enrollment->update(['status' => EnrollmentStatus::Failed]);
        $this->postJson(route('ai-chat.conversations.store'), ['section_id' => $section->id])->assertForbidden();
    }

    public function test_prompt_has_only_authorized_section_body_and_certification(): void
    {
        [$user, $enrollment, $section] = $this->context();
        $conversation = app(StoreAction::class)($user, ['section_id' => $section->id])['conversation'];
        $prompt = app(AiChatContextService::class)->prompt($conversation);
        $this->assertStringContainsString($section->body, $prompt);
        $this->assertStringContainsString($enrollment->certification->name, $prompt);
        $section->chapter->update(['status' => ContentStatus::Draft]);
        $this->assertStringNotContainsString($section->body, app(AiChatContextService::class)->prompt($conversation->fresh()));
        $enrollment->delete();
        $this->assertSame(config('ai-chat.system_prompt'), app(AiChatContextService::class)->prompt($conversation->fresh()));
    }

    public function test_widget_context_uses_current_certification_page_and_section(): void
    {
        [$user, $enrollment, $section] = $this->context();
        $this->actingAs($user)->get(route('learning.sections.show', $section))->assertOk()->assertSee('data-section-id="'.$section->id.'"', false);
        $this->get(route('certifications.show', $enrollment->certification))->assertOk()->assertSee('data-certification-id="'.$enrollment->certification_id.'"', false)->assertSee('data-context-explicit="1"', false);
    }

    public function test_hidden_section_metadata_is_not_revealed_in_the_history_sidebar(): void
    {
        [$user, , $section] = $this->context();
        $section->update(['title' => 'private-unpublished-material-title']);
        $conversation = app(StoreAction::class)($user, ['section_id' => $section->id])['conversation'];
        $section->update(['status' => ContentStatus::Draft]);
        $this->actingAs($user)->get(route('ai-chat.conversations.show', $conversation))->assertOk()->assertDontSee('private-unpublished-material-title');
        $this->getJson(route('ai-chat.conversations.show', $conversation))->assertOk()->assertJsonPath('conversation.context.section_title', null);
    }
}
