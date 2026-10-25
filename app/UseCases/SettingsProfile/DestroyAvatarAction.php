<?php

declare(strict_types=1);

namespace App\UseCases\SettingsProfile;

use App\Models\User;
use App\Services\AvatarStorageService;
use Illuminate\Support\Facades\DB;

final class DestroyAvatarAction
{
    public function __construct(private AvatarStorageService $storage) {}

    public function __invoke(User $user): void
    {
        DB::transaction(function () use ($user) {
            $current = User::query()->lockForUpdate()->findOrFail($user->id);
            $oldUrl = $current->avatar_url;
            $current->forceFill(['avatar_url' => null])->save();
            DB::afterCommit(fn () => $this->storage->removeOwned($current, $oldUrl));
        });
    }
}
