<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class UpdateAction
{
    public function __invoke(MeetingPack $plan, array $data, User $actor): MeetingPack
    {
        return DB::transaction(function () use ($plan, $data, $actor) {
            $locked = MeetingPack::query()->lockForUpdate()->findOrFail($plan->id);
            $locked->update([
                ...Arr::only($data, ['name', 'description', 'meeting_count', 'price', 'stripe_price_id']),
                'sort_order' => array_key_exists('sort_order', $data) ? ($data['sort_order'] ?? 0) : $locked->sort_order,
                'updated_by_user_id' => $actor->id,
            ]);

            return $locked->fresh();
        });
    }
}
