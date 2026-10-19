<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AnnouncementTargetType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class AnnouncementRecipientService
{
    public function query(array $data = []): Builder
    {
        $query = User::query()->where('role', UserRole::Student)->where('status', UserStatus::InProgress);

        return match (isset($data['target_type']) ? AnnouncementTargetType::from($data['target_type']) : null) {
            AnnouncementTargetType::Certification => $query->whereHas('enrollments', fn (Builder $enrollments) => $enrollments->where('certification_id', $data['target_certification_id'])),
            AnnouncementTargetType::User => $query->whereKey($data['target_user_id']),
            AnnouncementTargetType::AllStudents, null => $query,
        };
    }
}
