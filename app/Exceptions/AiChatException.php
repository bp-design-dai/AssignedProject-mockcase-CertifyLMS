<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class AiChatException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?int $upstreamStatus = null)
    {
        parent::__construct('AIが応答できませんでした。質問は保存しました。同じ内容を再送できます。');
    }
}
