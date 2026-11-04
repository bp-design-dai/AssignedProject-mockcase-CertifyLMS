<?php

declare(strict_types=1);

namespace App\Data;

final class GeminiResponse
{
    public function __construct(
        public readonly string $content,
        public readonly string $model,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly ?int $totalTokens = null,
        public readonly int $responseTimeMs = 0,
    ) {}

    public function metadata(): array
    {
        return ['model' => $this->model, 'input_tokens' => $this->inputTokens, 'output_tokens' => $this->outputTokens, 'total_tokens' => $this->totalTokens, 'response_time_ms' => $this->responseTimeMs];
    }
}
