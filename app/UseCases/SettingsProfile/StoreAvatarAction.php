<?php

declare(strict_types=1);

namespace App\UseCases\SettingsProfile;

use App\Models\User;
use App\Services\AvatarStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class StoreAvatarAction
{
    public function __construct(private AvatarStorageService $storage) {}

    public function __invoke(User $user, UploadedFile $file): User
    {
        $directory = "avatars/{$user->id}";
        $filename = Str::ulid().'.'.$file->extension();
        $url = Storage::disk('public')->url($directory.'/'.$filename);

        try {
            return DB::transaction(function () use ($user, $file, $directory, $filename, $url) {
                $current = User::query()->lockForUpdate()->findOrFail($user->id);
                $oldUrl = $current->avatar_url;
                if (Storage::disk('public')->putFileAs($directory, $file, $filename) === false) {
                    throw new \RuntimeException('Avatar write failed.');
                }
                $current->forceFill(['avatar_url' => $url])->save();
                DB::afterCommit(fn () => $this->storage->removeOwned($current, $oldUrl));

                return $current;
            });
        } catch (Throwable $e) {
            $this->storage->removeOwned($user, $url);
            Log::warning('Avatar upload failed.', ['user_id' => $user->id, 'exception' => $e::class]);

            throw ValidationException::withMessages(['avatar' => '画像を保存できませんでした。もう一度お試しください。']);
        }
    }
}
