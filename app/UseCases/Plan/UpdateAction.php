<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class UpdateAction
{
    public function __invoke(Plan $plan, array $data, User $actor): Plan
    {
        return DB::transaction(function () use ($plan, $data, $actor) {
            $locked = Plan::query()->lockForUpdate()->findOrFail($plan->id);
            $locked->update([
                ...Arr::only($data, ['name', 'description', 'duration_days', 'default_meeting_quota']),
                'sort_order' => array_key_exists('sort_order', $data) ? ($data['sort_order'] ?? 0) : $locked->sort_order,
                'updated_by_user_id' => $actor->id,
            ]);

            return $locked->fresh();
        });
    }
}
