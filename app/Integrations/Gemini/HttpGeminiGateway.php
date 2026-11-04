<?php

declare(strict_types=1);

namespace App\Integrations\Gemini;

use App\Contracts\GeminiGateway;
use App\Data\GeminiResponse;
use App\Exceptions\AiChatException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class HttpGeminiGateway implements GeminiGateway
{
    public function generate(array $contents, string $systemInstruction): GeminiResponse
    {
        $key = config('ai-chat.gemini.api_key');
        $model = config('ai-chat.gemini.model');
        if (! is_string($key) || trim($key) === '' || ! is_string($model) || ! preg_match('/\A[a-zA-Z0-9._-]+\z/', $model)) {
            throw new AiChatException('not_configured');
        }
        $start = hrtime(true);
        try {
            $response = Http::acceptJson()->withHeaders(['x-goog-api-key' => $key])
                ->connectTimeout(5)->timeout(max(1, (int) config('ai-chat.gemini.timeout', 30)))
                ->post('https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent', [
                    'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
                    'contents' => $contents,
                ]);
            if (! $response->successful()) {
                throw new AiChatException('upstream_error', $response->status());
            }
            $data = $response->json();
            if (! is_array($data)) {
                throw new AiChatException('invalid_response');
            }
            if (! empty($data['promptFeedback']['blockReason'])) {
                throw new AiChatException('blocked');
            }
            $candidate = $data['candidates'][0] ?? [];
            if (in_array($candidate['finishReason'] ?? '', ['SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'MALFORMED_FUNCTION_CALL'], true)) {
                throw new AiChatException('blocked');
            }
            $text = '';
            foreach ($candidate['content']['parts'] ?? [] as $part) {
                if (is_array($part) && empty($part['thought']) && is_string($part['text'] ?? null)) {
                    $text .= $part['text'];
                }
            }
            if (trim($text) === '') {
                throw new AiChatException('empty_response');
            }
            $tokens = fn (string $name): ?int => isset($data['usageMetadata'][$name]) && is_int($data['usageMetadata'][$name]) && $data['usageMetadata'][$name] >= 0 ? $data['usageMetadata'][$name] : null;

            return new GeminiResponse(trim($text), is_string($data['modelVersion'] ?? null) ? mb_substr($data['modelVersion'], 0, 255) : $model,
                $tokens('promptTokenCount'), $tokens('candidatesTokenCount'), $tokens('totalTokenCount'), (int) round((hrtime(true) - $start) / 1000000));
        } catch (AiChatException $error) {
            throw $error;
        } catch (Throwable) {
            // Never carry the request, API key, response body, or provider exception to the application logger.
            throw new AiChatException('transport_error');
        }
    }
}
