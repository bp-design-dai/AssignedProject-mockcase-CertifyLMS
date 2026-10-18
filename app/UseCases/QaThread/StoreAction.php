<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class StoreAction
{
    public function __invoke(User $user, array $validated): QaThread
    {
        Gate::forUser($user)->authorize('create', QaThread::class);
        Certification::query()->published()->findOrFail($validated['certification_id']);

        return QaThread::create([
            'certification_id' => $validated['certification_id'],
            'user_id' => $user->id,
            'title' => $validated['title'],
            'body' => $validated['body'],
            'status' => QaThreadStatus::Open,
        ]);
    }
}
