<?php

declare(strict_types=1);

namespace App\UseCases\Announcement;

use App\Models\Announcement;
use App\Models\AnnouncementDispatch;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class IndexAction
{
    public function __invoke(User $user): array
    {
        Gate::forUser($user)->authorize('viewAny', Announcement::class);

        return ['dispatches' => AnnouncementDispatch::whereNotNull('payload')->latest()->limit(20)->get(), 'announcements' => Announcement::query()->with(['targetCertification', 'targetUser', 'createdBy'])->orderByDesc('dispatched_at')->orderByDesc('id')->paginate(20)];
    }
}
