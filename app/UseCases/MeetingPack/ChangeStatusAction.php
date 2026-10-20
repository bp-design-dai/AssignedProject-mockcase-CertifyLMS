<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ChangeStatusAction
{
    public function __invoke(MeetingPack $plan, MeetingPackStatus $next, User $actor): MeetingPack
    {
        return DB::transaction(function () use ($plan, $next, $actor) {
            $locked = MeetingPack::query()->lockForUpdate()->findOrFail($plan->id);
            $allowed = match ($locked->status) {
                MeetingPackStatus::Draft => MeetingPackStatus::Published,
                MeetingPackStatus::Published => MeetingPackStatus::Archived,
                MeetingPackStatus::Archived => MeetingPackStatus::Draft,
            };
            if ($next !== $allowed) {
                throw new ConflictHttpException('この順序では面談パックの状態を変更できません。');
            }
            $locked->update(['status' => $next, 'updated_by_user_id' => $actor->id]);

            return $locked->fresh();
        });
    }
}
