<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CertificationStatus;
use App\Enums\QaThreadStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\QaThread;
use App\Models\User;

class QaThreadPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Admin || $this->isActiveParticipant($user);
    }

    public function view(User $user, QaThread $thread): bool
    {
        if ($user->role === UserRole::Admin) {
            return true;
        }

        if (! $this->isActiveParticipant($user) || $thread->certification->status !== CertificationStatus::Published) {
            return false;
        }

        if ($user->role === UserRole::Coach) {
            // 詳細の回答ごとの認可でも同じ担当資格を繰り返し取得しない。
            $user->loadMissing('assignedCertifications');

            return $user->assignedCertifications->contains('id', $thread->certification_id);
        }

        return true;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Student && $this->isActiveParticipant($user);
    }

    public function update(User $user, QaThread $thread): bool
    {
        return $this->create($user) && $thread->user_id === $user->id && $this->view($user, $thread);
    }

    public function delete(User $user, QaThread $thread): bool
    {
        // 管理者専用のモデレーションは、投稿者による削除とは別の権限。
        if ($user->role === UserRole::Admin) {
            return true;
        }

        return $this->update($user, $thread) && ! $thread->replies()->exists();
    }

    public function resolve(User $user, QaThread $thread): bool
    {
        return $this->update($user, $thread) && $thread->status === QaThreadStatus::Open;
    }

    public function unresolve(User $user, QaThread $thread): bool
    {
        return $this->update($user, $thread) && $thread->status === QaThreadStatus::Resolved;
    }

    private function isActiveParticipant(User $user): bool
    {
        return $user->status === UserStatus::InProgress && in_array($user->role, [UserRole::Student, UserRole::Coach], true);
    }
}
