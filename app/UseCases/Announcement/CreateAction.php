<?php

declare(strict_types=1);

namespace App\UseCases\Announcement;

use App\Models\Announcement;
use App\Models\Certification;
use App\Models\User;
use App\Services\AnnouncementRecipientService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class CreateAction
{
    public function __invoke(User $user): array
    {
        Gate::forUser($user)->authorize('create', Announcement::class);

        return [
            'certifications' => Certification::query()->orderBy('name')->get(['id', 'name']),
            'students' => app(AnnouncementRecipientService::class)->query()->orderBy('name')->get(['id', 'name', 'email']),
            'idempotencyKey' => (string) Str::uuid(),
        ];
    }
}
