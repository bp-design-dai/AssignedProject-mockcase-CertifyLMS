<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Models\QaThread;

final class UpdateAction
{
    public function __invoke(QaThread $thread, array $validated): void
    {
        $thread->update(['title' => $validated['title'], 'body' => $validated['body']]);
    }
}
