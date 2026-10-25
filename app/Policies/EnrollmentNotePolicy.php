<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;

class EnrollmentNotePolicy
{
    public function viewAny(User $user, Enrollment $enrollment): bool
    {
        return in_array($user->role, [UserRole::Coach, UserRole::Admin], true)
            && Enrollment::query()->whereKey($enrollment->id)->forUser($user)->exists();
    }

    public function view(User $user, EnrollmentNote $note): bool
    {
        return $note->enrollment !== null && $this->viewAny($user, $note->enrollment);
    }

    public function create(User $user, Enrollment $enrollment): bool
    {
        return $this->viewAny($user, $enrollment);
    }

    public function update(User $user, EnrollmentNote $note): bool
    {
        return $this->view($user, $note)
            && ($user->role === UserRole::Admin || $note->author_id === $user->id);
    }

    public function delete(User $user, EnrollmentNote $note): bool
    {
        return $this->update($user, $note);
    }
}
