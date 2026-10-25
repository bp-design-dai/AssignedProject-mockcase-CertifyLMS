<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * 本人用設定と管理者向けユーザー運用画面の認可ルール。
 *
 * 「他者のプロフィール / ロール変更」動線は本 LMS では提供しないため、`update` / `updateRole` は持たない。
 * メールアドレス変更 / ロール変更は退会 + 新規招待で運用する。
 * 本人用設定の能力は管理者向け能力から分離し、全ロールで本人のみ許可する。
 */
class UserPolicy
{
    public function viewOwnProfile(User $auth, User $target): bool
    {
        return $auth->is($target);
    }

    public function updateOwnProfile(User $auth, User $target): bool
    {
        return $auth->is($target);
    }

    public function updateOwnAvatar(User $auth, User $target): bool
    {
        return $auth->is($target);
    }

    public function updateOwnPassword(User $auth, User $target): bool
    {
        return $auth->is($target);
    }

    public function viewAny(User $auth): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function view(User $auth, User $target): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function withdraw(User $auth, User $target): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function extendCourse(User $auth, User $target): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function grantMeetingQuota(User $auth, User $target): bool
    {
        return $auth->role === UserRole::Admin;
    }
}
