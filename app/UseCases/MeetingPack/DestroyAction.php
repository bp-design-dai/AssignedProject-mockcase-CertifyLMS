<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class DestroyAction
{
    public function __invoke(MeetingPack $plan): void
    {
        DB::transaction(function () use ($plan) {
            $locked = MeetingPack::query()->lockForUpdate()->findOrFail($plan->id);
            if ($locked->status === MeetingPackStatus::Published) {
                throw new ConflictHttpException('公開中の面談パックは削除できません。');
            }
            if ($locked->hasPurchaseHistory()) {
                throw new ConflictHttpException('購入履歴がある面談パックは削除できません。');
            }
            $locked->delete();
        });
    }
}
