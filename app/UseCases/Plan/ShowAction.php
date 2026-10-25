<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\UserStatus;
use App\Models\Plan;

final class ShowAction
{
    public function __invoke(Plan $plan): Plan
    {
        // 提供済み画面の受講者数と一覧にも、現在受講中の同じ対象を渡す。
        return $plan->load([
            'createdBy',
            'updatedBy',
            'users' => fn ($query) => $query->where('status', UserStatus::InProgress)->orderBy('name')->orderBy('id'),
        ]);
    }
}
