<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Http\Client\Factory;

final class GuardedHttpFactory extends Factory
{
    protected function newPendingRequest()
    {
        return (new GuardedPendingRequest($this, $this->globalMiddleware))
            ->withOptions($this->globalOptions)
            ->setHandler(ExternalApiGuard::guzzleHandler('laravel'));
    }
}
