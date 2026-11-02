<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Models\Enrollment;
use App\Services\GoogleCalendarAvailabilityService;
use App\Services\MeetingAvailabilityService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

final class FetchAvailabilityAction
{
    public function __construct(private readonly MeetingAvailabilityService $availabilityService, private readonly GoogleCalendarAvailabilityService $calendar) {}

    /** @return array{date: Carbon, slots: Collection, calendar_warning: bool} */
    public function __invoke(Enrollment $enrollment, string $dateString): array
    {
        $date = Carbon::parse($dateString);
        $slots = $this->availabilityService->slotsForCertification(
            $enrollment->loadMissing('certification')->certification,
            $date,
        );

        $filtered = $this->calendar->filterSlots($enrollment->certification, $date, $slots);

        return ['date' => $date, 'slots' => $filtered['slots'], 'calendar_warning' => $filtered['warning']];
    }
}
