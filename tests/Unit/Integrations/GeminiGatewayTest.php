<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use App\Exceptions\AiChatException;
use App\Integrations\Gemini\HttpGeminiGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('external-api')]
class GeminiGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ai-chat.gemini.api_key' => 'fake-secret-not-real', 'ai-chat.gemini.model' => 'test-model']);
        Http::preventStrayRequests();
    }

    public function test_request_uses_header_key_context_and_history_and_keeps_metadata_internal(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => '内部思考', 'thought' => true], ['text' => '回答です']]], 'finishReason' => 'STOP']],
            'modelVersion' => 'provider-actual-model', 'usageMetadata' => ['promptTokenCount' => 12, 'candidatesTokenCount' => 9, 'totalTokenCount' => 21],
        ])]);
        $contents = [['role' => 'user', 'parts' => [['text' => '質問']]]];
        $result = app(HttpGeminiGateway::class)->generate($contents, '教材コンテキスト');
        $this->assertSame('回答です', $result->content);
        $this->assertSame('provider-actual-model', $result->model);
        $this->assertSame(12, $result->inputTokens);
        $this->assertSame(9, $result->outputTokens);
        $this->assertSame(21, $result->totalTokens);
        $this->assertGreaterThanOrEqual(0, $result->responseTimeMs);
        Http::assertSent(fn ($request) => $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/test-model:generateContent'
            && $request->hasHeader('x-goog-api-key', 'fake-secret-not-real') && $request['contents'] === $contents
            && $request['systemInstruction']['parts'][0]['text'] === '教材コンテキスト'
            && ! str_contains($request->url(), 'fake-secret-not-real'));
    }

    #[DataProvider('badResponses')]
    public function test_failures_are_sanitized_without_provider_body_or_secret(array $body, int $status, string $reason): void
    {
        Http::fake(['*' => Http::response($body + ['private-provider-body' => 'fake-secret-not-real'], $status)]);
        try {
            app(HttpGeminiGateway::class)->generate([['role' => 'user', 'parts' => [['text' => '質問']]]], '指示');
            $this->fail('Expected a sanitized exception');
        } catch (AiChatException $error) {
            $this->assertSame($reason, $error->reason);
            $this->assertNull($error->getPrevious());
            $this->assertStringNotContainsString('fake-secret-not-real', (string) $error);
            $this->assertStringNotContainsString('private-provider-body', (string) $error);
        }
        Http::assertSentCount(1);
    }

    public static function badResponses(): array
    {
        return [[[], 400, 'upstream_error'], [[], 401, 'upstream_error'], [[], 403, 'upstream_error'], [[], 429, 'upstream_error'], [[], 503, 'upstream_error'],
            [[], 200, 'empty_response'], [['candidates' => [['content' => ['parts' => [['text' => '   ']]]]]], 200, 'empty_response'],
            [['promptFeedback' => ['blockReason' => 'SAFETY']], 200, 'blocked'],
            [['candidates' => [['finishReason' => 'SAFETY', 'content' => ['parts' => [['text' => '遮断対象']]]]]], 200, 'blocked']];
    }

    public function test_network_exception_has_no_secret_or_original_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('https://provider.invalid?key=fake-secret-not-real'));
        try {
            app(HttpGeminiGateway::class)->generate([], '指示');
            $this->fail('Expected failure');
        } catch (AiChatException $error) {
            $this->assertSame('transport_error', $error->reason);
            $this->assertNull($error->getPrevious());
            $this->assertStringNotContainsString('fake-secret-not-real', (string) $error);
        }
    }

    public function test_unconfigured_key_or_unsafe_model_never_sends_request(): void
    {
        foreach ([['', 'test-model'], ['fake-key', '../escape?key=secret']] as [$key, $model]) {
            config(['ai-chat.gemini.api_key' => $key, 'ai-chat.gemini.model' => $model]);
            try {
                app(HttpGeminiGateway::class)->generate([], '指示');
                $this->fail('Expected configuration failure');
            } catch (AiChatException $error) {
                $this->assertSame('not_configured', $error->reason);
            }
        }
        Http::assertNothingSent();
    }

    #[DataProvider('invalidJsonBodies')]
    public function test_invalid_json_or_scalar_response_is_a_sanitized_failure(string $body): void
    {
        Http::fake(['*' => Http::response($body, 200)]);
        try {
            app(HttpGeminiGateway::class)->generate([], '指示');
            $this->fail('Expected invalid response failure');
        } catch (AiChatException $error) {
            $this->assertSame('invalid_response', $error->reason);
            $this->assertNull($error->getPrevious());
        }
    }

    public static function invalidJsonBodies(): array
    {
        return [['private-invalid-json'], ['true']];
    }
}
