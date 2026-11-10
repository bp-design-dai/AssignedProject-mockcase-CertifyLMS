<?php

declare(strict_types=1);

namespace Tests\Feature\Http\AiChat;

use App\Contracts\GeminiGateway;
use App\Enums\AiChatMessageRole;
use App\Integrations\Gemini\HttpGeminiGateway;
use App\Models\AiChatDailyUsage;
use App\Models\AiChatMessage;
use App\UseCases\AiChat\StoreAction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AiChatTestCase;

#[Group('external-api')]
class HttpGatewayRetryTest extends AiChatTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->forgetInstance(GeminiGateway::class);
        $this->app->bind(GeminiGateway::class, HttpGeminiGateway::class);
    }

    private function answer(): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => 'モックによる再送回答']]]]], 'modelVersion' => 'synthetic-model'];
    }

    public function test_transient_failure_then_user_resend_succeeds_and_consumes_two_attempts(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()->push([], 503)->push($this->answer())]);
        $conversation = $this->conversation(null, ['auto_title' => false]);
        $this->send($conversation, '再送する架空の質問')->assertStatus(502)->assertJsonPath('assistant_message.status', 'error');
        Http::assertSentCount(1);
        $this->assertSame(1, AiChatDailyUsage::sole()->attempts);
        $this->assertSame('再送する架空の質問', $conversation->messages()->where('role', AiChatMessageRole::User)->sole()->content);
        $this->send($conversation, '再送する架空の質問')->assertOk()->assertJsonPath('assistant_message.content', 'モックによる再送回答');
        $this->assertSame(2, AiChatDailyUsage::sole()->attempts);
        $this->assertSame(4, $conversation->messages()->count());
        Http::assertSentCount(2);
    }

    public function test_repeated_failed_resends_stop_at_existing_configured_daily_limit(): void
    {
        config(['ai-chat.daily_limit' => 2]);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 503)]);
        $conversation = $this->conversation(null, ['auto_title' => false]);
        $this->send($conversation)->assertStatus(502);
        $this->send($conversation)->assertStatus(502);
        $this->send($conversation)->assertStatus(429);
        $this->assertSame(2, AiChatDailyUsage::sole()->attempts);
        $this->assertSame(4, $conversation->messages()->count());
        Http::assertSentCount(2);
    }

    #[DataProvider('failedResponses')]
    public function test_real_http_gateway_failure_preserves_question_and_attempt(string $kind): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => function () use ($kind) {
            if ($kind === 'connection') {
                throw new ConnectionException('synthetic-provider-detail');
            }

            return Http::response(['candidates' => []]);
        }]);
        $conversation = $this->conversation(null, ['auto_title' => false]);
        $this->send($conversation, '保存される架空の質問')->assertStatus(502)->assertDontSee('synthetic-provider-detail');
        $this->assertSame(1, AiChatDailyUsage::sole()->attempts);
        $this->assertSame(2, $conversation->messages()->count());
        $this->assertSame('保存される架空の質問', $conversation->messages()->where('role', AiChatMessageRole::User)->sole()->content);
    }

    public static function failedResponses(): array
    {
        return [['empty'], ['connection']];
    }

    public function test_section_certification_and_only_recent_own_history_reach_the_http_payload(): void
    {
        [$user, $enrollment, $section] = $this->context();
        $conversation = app(StoreAction::class)($user, ['section_id' => $section->id])['conversation'];
        $conversation->update(['auto_title' => false]);
        for ($i = 0; $i < 12; $i++) {
            AiChatMessage::factory()->create(['conversation_id' => $conversation->id, 'sequence' => $i * 2 + 1, 'content' => 'synthetic-question-'.$i]);
            AiChatMessage::factory()->create(['conversation_id' => $conversation->id, 'sequence' => $i * 2 + 2, 'role' => AiChatMessageRole::Assistant, 'content' => 'synthetic-answer-'.$i]);
        }
        AiChatMessage::factory()->create(['conversation_id' => $this->conversation()->id, 'content' => 'synthetic-other-student-private']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->answer())]);
        $this->send($conversation, 'synthetic-latest-question')->assertOk();
        Http::assertSent(function ($request) use ($section, $enrollment) {
            $data = $request->data();
            $prompt = $data['systemInstruction']['parts'][0]['text'];
            $this->assertStringContainsString($section->body, $prompt);
            $this->assertStringContainsString($enrollment->certification->name, $prompt);
            // Ten questions plus nine prior answers: the current answer is not generated yet.
            $this->assertCount(19, $data['contents']);
            $this->assertSame('synthetic-latest-question', end($data['contents'])['parts'][0]['text']);
            $body = json_encode($data);
            $this->assertStringNotContainsString('synthetic-question-0"', $body);
            $this->assertStringNotContainsString('synthetic-other-student-private', $body);

            return true;
        });
        Http::assertSentCount(1);
        $this->assertSame(26, $conversation->messages()->count());
    }
}
