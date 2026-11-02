<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\GoogleCalendarGateway;
use App\Enums\MeetingStatus;
use App\Exceptions\GoogleCalendarException;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\GoogleCalendarCredential;
use App\Models\Meeting;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

final class GoogleCalendarAvailabilityService
{
    public const WARNING = 'Googleカレンダーの空き状況を確認できませんでした。LMS上の空き状況でご案内しています。予定が重なる場合はコーチへご相談ください。';

    public function __construct(private readonly GoogleCalendarGateway $gateway, private readonly GoogleCalendarConnectionService $connection) {}

    /** Called again under the existing coach/student locks immediately before insertion. */
    public function filterCoaches(Collection $coaches, Carbon $start): array
    {
        $warning = false;
        $credentials = GoogleCalendarCredential::whereIn('user_id', $coaches->pluck('id'))->get()->keyBy('user_id');
        $available = $coaches->filter(function ($coach) use ($credentials, $start, &$warning) {
            $credential = $credentials->get($coach->id);
            if ($credential === null) {
                return true;
            }
            $periods = $this->periods($credential, $start, $start->copy()->addHour(), $warning);

            return ! $this->overlaps($periods, $start, $start->copy()->addHour());
        });

        return ['coaches' => $available, 'warning' => $warning];
    }

    /** Preserve the LMS slot list/order/counts and subtract only Google-busy coaches. */
    public function filterSlots(Certification $certification, Carbon $date, Collection $slots): array
    {
        $warning = false;
        if ($slots->isEmpty()) {
            return ['slots' => $slots, 'warning' => false];
        }
        $ids = $certification->coaches()->pluck('users.id');
        $credentials = GoogleCalendarCredential::whereIn('user_id', $ids)->get();
        if ($credentials->isEmpty()) {
            return ['slots' => $slots, 'warning' => false];
        }
        $availabilities = CoachAvailability::whereIn('coach_id', $credentials->pluck('user_id'))
            ->where('day_of_week', $date->dayOfWeek)->where('is_active', true)->get()->groupBy('coach_id');
        $booked = Meeting::whereIn('coach_id', $credentials->pluck('user_id'))
            ->whereBetween('scheduled_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->whereIn('status', [MeetingStatus::Reserved->value, MeetingStatus::Completed->value])->get();
        $busy = [];
        foreach ($credentials as $credential) {
            $busy[$credential->user_id] = $this->periods($credential, $date->copy()->startOfDay(), $date->copy()->addDay()->startOfDay(), $warning);
        }
        $filtered = $slots->map(function (array $slot) use ($availabilities, $booked, $busy) {
            $count = $slot['available_coach_count'];
            foreach ($busy as $coachId => $periods) {
                if (! $this->overlaps($periods, $slot['slot_start'], $slot['slot_end'])
                    || $booked->contains(fn ($meeting) => $meeting->coach_id === $coachId && $meeting->scheduled_at->equalTo($slot['slot_start']))) {
                    continue;
                }
                // Match the existing service's contributions, including overlapping availability rows.
                foreach ($availabilities->get($coachId, collect()) as $availability) {
                    $cursor = Carbon::parse($slot['slot_start']->format('Y-m-d').' '.$availability->start_time);
                    $end = Carbon::parse($slot['slot_start']->format('Y-m-d').' '.$availability->end_time);
                    while ($cursor->copy()->addHour()->lessThanOrEqualTo($end)) {
                        if ($cursor->equalTo($slot['slot_start'])) {
                            $count--;
                        }
                        $cursor->addHour();
                    }
                }
            }
            $slot['available_coach_count'] = max(0, $count);

            return $slot;
        })->filter(fn ($slot) => $slot['available_coach_count'] > 0)->values();

        return ['slots' => $filtered, 'warning' => $warning];
    }

    private function periods(GoogleCalendarCredential $credential, Carbon $start, Carbon $end, bool &$warning): array
    {
        try {
            $periods = $this->connection->request($credential, fn ($token) => $this->gateway->busy($token, $start, $end));

            // FreeBusy expands recurring/all-day events and excludes transparent events at Google.
            return array_map(function ($period) {
                try {
                    if (! is_string($period['start'] ?? null) || ! is_string($period['end'] ?? null)) {
                        throw new GoogleCalendarException;
                    }
                    $start = Carbon::parse($period['start']);
                    $end = Carbon::parse($period['end']);
                } catch (\Throwable) {
                    throw new GoogleCalendarException;
                }
                if ($start->greaterThanOrEqualTo($end)) {
                    throw new GoogleCalendarException;
                }

                return ['start' => $start, 'end' => $end];
            }, $periods);
        } catch (GoogleCalendarException) {
            $warning = true;
            Log::warning('Google Calendar availability fallback');

            return [];
        }
    }

    private function overlaps(array $periods, Carbon $start, Carbon $end): bool
    {
        foreach ($periods as $period) {
            if ($period['start']->lessThan($end) && $period['end']->greaterThan($start)) {
                return true;
            }
        }

        return false;
    }
}
