<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class DestroyAction
{
    public function __invoke(Plan $plan): void
    {
        DB::transaction(function () use ($plan) {
            $locked = Plan::query()->lockForUpdate()->findOrFail($plan->id);
            if ($locked->status !== PlanStatus::Draft) {
                throw new ConflictHttpException('下書き以外のプランは削除できません。');
            }
            // 契約中件数とは異なり、招待中・卒業・退会・削除済みの参照も保護する。
            if ($locked->users()->withTrashed()->exists() || $locked->userPlanLogs()->exists()) {
                throw new ConflictHttpException('受講者または契約履歴が紐づくプランは削除できません。');
            }
            $locked->delete();
        });
    }
}
