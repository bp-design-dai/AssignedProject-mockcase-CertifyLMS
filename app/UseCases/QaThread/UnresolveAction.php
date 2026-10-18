<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\QaThread;

final class UnresolveAction
{
    public function __invoke(QaThread $thread): void
    {
        $thread->update(['status' => QaThreadStatus::Open, 'resolved_at' => null]);
    }
}
