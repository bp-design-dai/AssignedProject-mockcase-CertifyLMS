<?php

declare(strict_types=1);

namespace App\UseCases\QaReply;

use App\Models\QaReply;

final class UpdateAction
{
    public function __invoke(QaReply $reply, array $validated): void
    {
        $reply->update(['body' => $validated['body']]);
    }
}
