<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Enums\UserStatus;
use App\Models\Plan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class IndexAction
{
    public function __invoke(?string $keyword, ?PlanStatus $status): LengthAwarePaginator
    {
        // 現在のplan_idを参照し、割当・延長履歴の件数とは区別する。
        $query = Plan::query()->withCount(['users' => fn ($query) => $query->where('status', UserStatus::InProgress)])->ordered();
        if ($keyword !== null && $keyword !== '') {
            $query->where('name', 'like', '%'.$keyword.'%');
        }
        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->paginate(20)->withQueryString();
    }
}
