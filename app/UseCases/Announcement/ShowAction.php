<?php

declare(strict_types=1);

namespace App\UseCases\Announcement;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class ShowAction
{
    public function __invoke(User $user, Announcement $announcement): array
    {
        Gate::forUser($user)->authorize('view', $announcement);

        return ['announcement' => $announcement->load(['targetCertification', 'targetUser', 'createdBy'])];
    }
}
