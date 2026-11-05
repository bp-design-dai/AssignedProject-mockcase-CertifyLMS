<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Certificate;
use App\Models\User;

final class CertificatePolicy
{
    public function download(User $user, Certificate $certificate): bool
    {
        return match ($user->role) {
            UserRole::Admin => true,
            UserRole::Coach => $certificate->certification?->coaches()->whereKey($user->id)->exists() ?? false,
            UserRole::Student => $certificate->user_id === $user->id
                && $user->status !== UserStatus::Withdrawn
                && ! $user->trashed(),
        };
    }
}
