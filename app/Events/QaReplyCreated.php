<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\QaReply;
use Illuminate\Foundation\Events\Dispatchable;

final class QaReplyCreated
{
    use Dispatchable;

    public function __construct(public readonly QaReply $reply) {}
}
