<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\GeminiResponse;

interface GeminiGateway
{
    public function generate(array $contents, string $systemInstruction): GeminiResponse;
}
