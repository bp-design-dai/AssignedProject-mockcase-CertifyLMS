<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Http\Client\PendingRequest;

final class GuardedPendingRequest extends PendingRequest
{
    public function buildStubHandler()
    {
        $middleware = parent::buildStubHandler();

        return function ($handler) use ($middleware) {
            $stub = $middleware($handler);

            return function ($request, $options) use ($stub) {
                try {
                    return $stub($request, $options);
                } catch (\RuntimeException $exception) {
                    if (str_starts_with($exception->getMessage(), 'Attempted request to [')
                        && str_ends_with($exception->getMessage(), '] without a matching fake.')) {
                        ExternalApiGuard::deny('laravel');
                    }
                    throw $exception;
                }
            };
        };
    }
}
