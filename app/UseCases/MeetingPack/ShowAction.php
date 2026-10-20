<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Models\MeetingPack;
use Illuminate\Support\Facades\Schema;

final class ShowAction
{
    public function __invoke(MeetingPack $plan): MeetingPack
    {
        $plan->load(['createdBy', 'updatedBy']);
        // 提供済み画面と同じく、購入機能の導入前は購入履歴を空として扱う。
        if (class_exists('App\\Models\\Payment') && Schema::hasTable('payments')) {
            $plan->load(['payments' => fn ($query) => $query->with('user')->latest()->limit(20)]);
        }

        return $plan;
    }
}
