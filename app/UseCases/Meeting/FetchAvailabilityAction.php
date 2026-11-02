<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Models\Enrollment;
use App\Services\MeetingAvailabilityService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

final class FetchAvailabilityAction
{
    public function __construct(private readonly MeetingAvailabilityService $availabilityService) {}

    /** @return array{date: Carbon, slots: Collection} */
    public function __invoke(Enrollment $enrollment, string $dateString): array
    {
        $date = Carbon::parse($dateString);
        $slots = $this->availabilityService->slotsForCertification(
            $enrollment->loadMissing('certification')->certification,
            $date,
        );

        return ['date' => $date, 'slots' => $slots];
    }
}
