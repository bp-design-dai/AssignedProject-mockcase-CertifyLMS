<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class IndexAction
{
    public function __invoke(?string $keyword, ?MeetingPackStatus $status): LengthAwarePaginator
    {
        $query = MeetingPack::query()->select('meeting_packs.*')->ordered();
        if ($keyword !== null && $keyword !== '') {
            $query->where('name', 'like', '%'.$keyword.'%');
        }
        if ($status !== null) {
            $query->where('status', $status);
        }
        if (Schema::hasTable('payments')) {
            $query->addSelect(['payments_count' => DB::table('payments')->selectRaw('COUNT(*)')
                ->whereColumn('meeting_pack_id', 'meeting_packs.id')]);
        }

        return $query->paginate(20)->withQueryString();
    }
}
