<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class StoreAction
{
    public function __invoke(array $data, User $actor): MeetingPack
    {
        return DB::transaction(fn () => MeetingPack::create([
            ...Arr::only($data, ['name', 'description', 'meeting_count', 'price', 'stripe_price_id']),
            'sort_order' => $data['sort_order'] ?? 0,
            'status' => MeetingPackStatus::Draft,
            'created_by_user_id' => $actor->id,
            'updated_by_user_id' => $actor->id,
        ]));
    }
}
