<?php

declare(strict_types=1);

namespace App\UseCases\SettingsProfile;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class UpdateProfileAction
{
    public function __invoke(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $current = User::query()->lockForUpdate()->findOrFail($user->id);
            $fields = ['name', 'bio'];
            if ($current->role === UserRole::Coach) {
                $fields[] = 'meeting_url';
            }
            $current->fill(Arr::only($data, $fields))->save();

            return $current;
        });
    }
}
