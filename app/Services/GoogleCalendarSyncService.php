<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\GoogleCalendarGateway;
use App\Enums\MeetingStatus;
use App\Exceptions\GoogleCalendarException;
use App\Models\GoogleCalendarCredential;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class GoogleCalendarSyncService
{
    public function __construct(private readonly GoogleCalendarGateway $gateway, private readonly GoogleCalendarConnectionService $connection,
        private readonly GoogleCalendarFailureNotifier $notifier) {}

    /** Store the generation and deterministic event ID inside the original booking transaction. */
    public function prepare(Meeting $meeting): void
    {
        $credential = GoogleCalendarCredential::where('user_id', $meeting->coach_id)->first();
        if ($credential !== null) {
            $meeting->update(['google_calendar_credential_id' => $credential->id,
                'google_calendar_event_id' => hash('sha256', 'certify-lms:'.$credential->id.':'.$meeting->id)]);
        }
    }

    /** Called by events dispatched AFTER the LMS transaction commits. */
    public function sync(Meeting $source): void
    {
        if ($source->google_calendar_credential_id === null) {
            return;
        }
        $credential = null;
        $operation = $source->status === MeetingStatus::Canceled ? 'delete' : 'create';
        try {
            // Separate synchronization transaction: serialize create/delete/disconnect using the
            // same coach -> meeting order. It never rolls back an already committed LMS operation.
            DB::transaction(function () use ($source, &$credential, &$operation) {
                $coach = User::withTrashed()->whereKey($source->coach_id)->lockForUpdate()->first();
                $meeting = Meeting::whereKey($source->id)->lockForUpdate()->first();
                if ($coach === null || $meeting === null) {
                    return;
                }
                $credential = GoogleCalendarCredential::whereKey($meeting->google_calendar_credential_id)->where('user_id', $coach->id)->first();
                // Disconnect/reconnect changes the generation: never act on an old account's events.
                if ($credential === null) {
                    return;
                }
                if ($meeting->status === MeetingStatus::Reserved && $meeting->google_calendar_created_at === null) {
                    $operation = 'create';
                    $this->connection->request($credential, fn ($token) => $this->gateway->createEvent($token, [
                        'id' => $meeting->google_calendar_event_id,
                        'summary' => 'Certify LMS面談｜'.$meeting->student->name,
                        'start' => ['dateTime' => $meeting->scheduled_at->toRfc3339String(), 'timeZone' => config('app.timezone')],
                        'end' => ['dateTime' => $meeting->scheduled_at->copy()->addHour()->toRfc3339String(), 'timeZone' => config('app.timezone')],
                        'location' => $meeting->meeting_url_snapshot,
                        'description' => $meeting->meeting_url_snapshot ?? '',
                        'extendedProperties' => ['private' => ['lms_meeting_id' => $meeting->id]],
                    ]));
                    $meeting->update(['google_calendar_created_at' => now()]);
                } elseif ($meeting->status === MeetingStatus::Canceled && $meeting->google_calendar_deleted_at === null) {
                    $operation = 'delete';
                    $this->connection->request($credential, fn ($token) => $this->gateway->deleteEvent($token, $meeting->google_calendar_event_id));
                    $meeting->update(['google_calendar_deleted_at' => now()]);
                }
            });
        } catch (\Throwable $exception) {
            // No provider message, tokens, names, private event data or URLs enter logs.
            Log::warning('Google Calendar synchronization failed', ['operation' => $operation, 'exception_class' => $exception::class]);
            if ($credential !== null) {
                $this->notifier->notify($credential, $operation, $source->id);
                // Persist authentication failure outside the rolled-back synchronization transaction.
                if ($exception instanceof GoogleCalendarException && $exception->authenticationFailure) {
                    try {
                        GoogleCalendarCredential::whereKey($credential->id)->update(['requires_reconnect' => true]);
                        $this->notifier->notify($credential, 'authentication');
                    } catch (\Throwable) {
                        Log::warning('Google Calendar authentication state could not be recorded');
                    }
                }
            }
        }
    }
}
