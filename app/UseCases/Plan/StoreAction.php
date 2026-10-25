<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class StoreAction
{
    public function __invoke(array $data, User $actor): Plan
    {
        return DB::transaction(fn () => Plan::create([
            ...Arr::only($data, ['name', 'description', 'duration_days', 'default_meeting_quota']),
            'sort_order' => $data['sort_order'] ?? 0,
            'status' => PlanStatus::Draft,
            'created_by_user_id' => $actor->id,
            'updated_by_user_id' => $actor->id,
        ]));
    }
}
