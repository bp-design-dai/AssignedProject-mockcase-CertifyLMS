<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Models\QaThread;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class DestroyAction
{
    public function __invoke(QaThread $thread, User $user): void
    {
        DB::transaction(function () use ($thread, $user) {
            // 回答投稿と同じ親行をロックし、画面表示後に回答が付いた場合も再判定する。
            $locked = QaThread::query()->lockForUpdate()->findOrFail($thread->id);
            Gate::forUser($user)->authorize('delete', $locked);
            $locked->delete();
        });
    }
}
