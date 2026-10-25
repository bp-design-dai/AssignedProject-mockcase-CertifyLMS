<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * 本機能が本人専用ディレクトリに保存した画像のみを削除する。
 * 既存の共用画像・外部URL・他ユーザーのファイルは削除しない。
 */
final class AvatarStorageService
{
    public function removeOwned(User $user, ?string $url): void
    {
        if ($url === null) {
            return;
        }

        $directory = "avatars/{$user->id}/";
        $prefix = Storage::disk('public')->url($directory);
        if (! str_starts_with($url, $prefix)) {
            return;
        }
        $filename = substr($url, strlen($prefix));
        if (! preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\.(?:png|jpe?g|webp)\z/', $filename)) {
            return;
        }

        try {
            if (! Storage::disk('public')->delete($directory.$filename)) {
                Log::warning('Avatar cleanup failed.', ['user_id' => $user->id]);
            }
        } catch (Throwable $e) {
            Log::warning('Avatar cleanup failed.', ['user_id' => $user->id, 'exception' => $e::class]);
        }
    }
}
