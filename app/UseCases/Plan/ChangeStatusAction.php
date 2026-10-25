<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ChangeStatusAction
{
    public function __invoke(Plan $plan, PlanStatus $next, User $actor): Plan
    {
        return DB::transaction(function () use ($plan, $next, $actor) {
            $locked = Plan::query()->lockForUpdate()->findOrFail($plan->id);
            $allowed = match ($locked->status) {
                PlanStatus::Draft => PlanStatus::Published,
                PlanStatus::Published => PlanStatus::Archived,
                PlanStatus::Archived => PlanStatus::Draft,
            };
            if ($next !== $allowed) {
                throw new ConflictHttpException('この順序ではプランの状態を変更できません。');
            }
            $locked->update(['status' => $next, 'updated_by_user_id' => $actor->id]);

            return $locked->fresh();
        });
    }
}
