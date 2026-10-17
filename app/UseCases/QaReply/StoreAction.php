<?php

declare(strict_types=1);

namespace App\UseCases\QaReply;

use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class StoreAction
{
    public function __invoke(QaThread $thread, User $user, array $validated): QaReply
    {
        return DB::transaction(function () use ($thread, $user, $validated) {
            // スレッド削除と直列化し、削除直前の回答の有無を保証する。
            $locked = QaThread::query()->lockForUpdate()->findOrFail($thread->id);
            Gate::forUser($user)->authorize('create', [QaReply::class, $locked]);

            return $locked->replies()->create(['user_id' => $user->id, 'body' => $validated['body']]);
        });
    }
}
