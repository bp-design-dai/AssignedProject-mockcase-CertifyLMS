<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\MeetingCanceled;
use App\Events\MeetingReserved;
use App\Services\GoogleCalendarSyncService;

final class SyncMeetingGoogleCalendar
{
    public function __construct(private readonly GoogleCalendarSyncService $calendar) {}

    public function handle(MeetingReserved|MeetingCanceled $event): void
    {
        $this->calendar->sync($event->meeting);
    }
}
