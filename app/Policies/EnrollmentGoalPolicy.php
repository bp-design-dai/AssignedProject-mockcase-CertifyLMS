<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;

class EnrollmentGoalPolicy
{
    public function viewAny(User $user, Enrollment $enrollment): bool
    {
        if ($enrollment->trashed()) {
            return false;
        }

        return match ($user->role) {
            UserRole::Admin => true,
            UserRole::Coach => Certification::query()->whereKey($enrollment->certification_id)->assignedTo($user)->exists(),
            UserRole::Student => $this->create($user, $enrollment),
        };
    }

    public function create(User $user, Enrollment $enrollment): bool
    {
        return $user->role === UserRole::Student
            && $user->status === UserStatus::InProgress
            && $enrollment->user_id === $user->id
            && ! $enrollment->trashed();
    }

    public function update(User $user, EnrollmentGoal $goal): bool
    {
        return $goal->enrollment !== null && $this->create($user, $goal->enrollment);
    }

    public function delete(User $user, EnrollmentGoal $goal): bool
    {
        return $this->update($user, $goal);
    }

    public function markAchieved(User $user, EnrollmentGoal $goal): bool
    {
        return $this->update($user, $goal);
    }

    public function unmarkAchieved(User $user, EnrollmentGoal $goal): bool
    {
        return $this->update($user, $goal);
    }
}
