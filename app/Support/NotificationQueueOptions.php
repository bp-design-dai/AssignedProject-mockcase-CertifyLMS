<?php

declare(strict_types=1);

namespace App\Support;

trait NotificationQueueOptions
{
    public int $tries = 4;

    public int $timeout = 60;

    public array $backoff = [60, 300, 900];
}
