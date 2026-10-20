<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\MeetingPack;
use App\Models\User;

class MeetingPackPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function view(User $user, MeetingPack $plan): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, MeetingPack $plan): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, MeetingPack $plan): bool
    {
        return $this->viewAny($user);
    }

    public function publish(User $user, MeetingPack $plan): bool
    {
        return $this->viewAny($user);
    }

    public function archive(User $user, MeetingPack $plan): bool
    {
        return $this->viewAny($user);
    }

    public function unarchive(User $user, MeetingPack $plan): bool
    {
        return $this->viewAny($user);
    }
}
