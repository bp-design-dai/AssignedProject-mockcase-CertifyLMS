<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Meeting;
use Illuminate\Foundation\Events\Dispatchable;

final class MeetingCanceled
{
    use Dispatchable;

    public function __construct(public readonly Meeting $meeting) {}
}
