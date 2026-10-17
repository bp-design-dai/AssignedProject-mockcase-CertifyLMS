<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\QaThread;

final class ResolveAction
{
    public function __invoke(QaThread $thread): void
    {
        $thread->update(['status' => QaThreadStatus::Resolved, 'resolved_at' => now()]);
    }
}
