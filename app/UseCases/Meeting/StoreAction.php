<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Events\MeetingReserved;
use App\Exceptions\MeetingQuota\InsufficientMeetingQuotaException;
use App\Exceptions\Mentoring\MeetingNoAvailableCoachException;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\Services\CoachMeetingLoadService;
use App\Services\GoogleCalendarAvailabilityService;
use App\Services\GoogleCalendarSyncService;
use App\Services\MeetingAvailabilityService;
use App\Services\MeetingQuotaService;
use App\UseCases\MeetingQuota\ConsumeQuotaAction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class StoreAction
{
    private bool $calendarFallback = false;

    public function calendarWarning(): ?string
    {
        return $this->calendarFallback ? GoogleCalendarAvailabilityService::WARNING : null;
    }

    public function __construct(
        private readonly MeetingAvailabilityService $availabilityService,
        private readonly CoachMeetingLoadService $coachLoadService,
        private readonly MeetingQuotaService $quotaService,
        private readonly ConsumeQuotaAction $consumeAction,
        private readonly GoogleCalendarAvailabilityService $calendar,
        private readonly GoogleCalendarSyncService $calendarSync,
    ) {}

    /** @param array{scheduled_at: string, topic: string} $data 検証・認可済みの予約入力 */
    public function __invoke(Enrollment $enrollment, array $data): Meeting
    {
        $this->calendarFallback = false;
        $scheduledAt = Carbon::parse($data['scheduled_at']);
        $topic = $data['topic'];
        $student = $enrollment->user;
        $certification = $enrollment->certification;

        return DB::transaction(function () use ($enrollment, $student, $certification, $scheduledAt, $topic) {
            // 予約がまだ無い枠も保護する。全候補コーチを同じID順でロックし、
            // 最初の通常SELECTより前に待機を終える(REPEATABLE READの古いスナップショットを避ける)。
            $certification->coaches()->orderBy('users.id')->lockForUpdate()->get();
            $student = User::query()->whereKey($student->id)->lockForUpdate()->firstOrFail();

            if ($this->quotaService->remaining($student) < 1) {
                throw new InsufficientMeetingQuotaException;
            }

            $this->availabilityService->validateSlot($certification, $scheduledAt);

            $candidates = $this->findAvailableCoaches($certification, $scheduledAt);
            if ($candidates->isEmpty()) {
                throw new MeetingNoAvailableCoachException;
            }

            $google = $this->calendar->filterCoaches($candidates, $scheduledAt);
            $this->calendarFallback = $google['warning'];
            $candidates = $google['coaches'];
            if ($candidates->isEmpty()) {
                throw new MeetingNoAvailableCoachException;
            }
            $coach = $this->coachLoadService->leastLoadedCoach($candidates);

            $meeting = Meeting::create([
                'enrollment_id' => $enrollment->id,
                'coach_id' => $coach->id,
                'student_id' => $student->id,
                'scheduled_at' => $scheduledAt,
                'status' => MeetingStatus::Reserved->value,
                'topic' => $topic,
                'meeting_url_snapshot' => $coach->meeting_url,
            ]);

            $this->calendarSync->prepare($meeting);
            $transaction = ($this->consumeAction)($student, $meeting->id);
            $meeting->update(['meeting_quota_transaction_id' => $transaction->id]);
            DB::afterCommit(fn () => MeetingReserved::dispatch($meeting));

            return $meeting->fresh();
        });
    }

    /** @return Collection<int, User> */
    private function findAvailableCoaches(Certification $certification, Carbon $scheduledAt): Collection
    {
        $time = $scheduledAt->format('H:i:s');

        return $certification->coaches()
            ->whereHas('coachAvailabilities', function ($q) use ($scheduledAt, $time) {
                $q->where('day_of_week', $scheduledAt->dayOfWeek)
                    ->where('is_active', true)
                    ->where('start_time', '<=', $time)
                    ->where('end_time', '>', $time);
            })
            ->whereDoesntHave('meetingsAsCoach', function ($q) use ($scheduledAt) {
                $q->where('scheduled_at', $scheduledAt)
                    ->whereIn('status', [MeetingStatus::Reserved->value, MeetingStatus::Completed->value]);
            })
            ->get();
    }
}
