<?php

declare(strict_types=1);

namespace Tests\Feature\GoogleCalendar;

use App\Contracts\GoogleCalendarGateway;
use App\Events\MeetingCanceled;
use App\Events\MeetingReserved;
use App\Exceptions\GoogleCalendarException;
use App\Models\CoachAvailability;
use App\Models\GoogleCalendarCredential;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\GoogleCalendarFailure;
use App\Services\GoogleCalendarAvailabilityService;
use App\Services\MeetingQuotaService;
use App\UseCases\GoogleCalendar\DisconnectAction;
use Database\Seeders\GoogleCalendarDemoSeeder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MeetingActionTestCase;

#[Group('external-api')]
class MeetingCalendarTest extends MeetingActionTestCase
{
    private $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = $this->mock(GoogleCalendarGateway::class);
        $this->gateway->shouldReceive('busy')->andReturn([])->byDefault();
        $this->gateway->shouldReceive('createEvent')->andReturnNull()->byDefault();
        $this->gateway->shouldReceive('deleteEvent')->andReturnNull()->byDefault();
    }

    private function linked(): array
    {
        [$student, $coach, $enrollment] = $this->context();
        $credential = GoogleCalendarCredential::factory()->create(['user_id' => $coach->id]);

        return [$student, $coach, $enrollment, $credential];
    }

    #[DataProvider('expandedBusyPeriods')]
    public function test_freebusy_busy_free_all_day_and_expanded_recurrences(array $periods, int $count, array $excluded): void
    {
        [$student, , $enrollment] = $this->linked();
        $this->gateway->shouldReceive('busy')->once()->andReturn($periods);
        $response = $this->actingAs($student)->getJson(route('meetings.availability', [$enrollment, 'date' => '2030-06-17']))->assertOk();
        $this->assertCount($count, $response->json('slots'));
        $hours = array_map(fn ($slot) => substr($slot['slot_start'], 11, 5), $response->json('slots'));
        foreach ($excluded as $time) {
            $this->assertNotContains($time, $hours);
        }
        $this->assertSame(['date', 'slots'], array_keys($response->json()));
        $response->assertDontSee('test-only-access')->assertDontSee('test-only-refresh');
    }

    public static function expandedBusyPeriods(): array
    {
        return [
            [[], 9, []],
            [[['start' => '2030-06-17T10:00:00+09:00', 'end' => '2030-06-17T11:00:00+09:00']], 8, ['10:00']],
            [[['start' => '2030-06-17T00:00:00+09:00', 'end' => '2030-06-18T00:00:00+09:00']], 0, ['09:00', '17:00']],
            [[['start' => '2030-06-17T10:00:00+09:00', 'end' => '2030-06-17T11:00:00+09:00'],
                ['start' => '2030-06-17T12:00:00+09:00', 'end' => '2030-06-17T13:00:00+09:00']], 7, ['10:00', '12:00']],
        ];
    }

    #[DataProvider('overlapBoundaries')]
    public function test_busy_period_overlap_uses_inclusive_start_exclusive_end(string $start, string $end, bool $tenAvailable): void
    {
        [$student, , $enrollment] = $this->linked();
        $this->gateway->shouldReceive('busy')->once()->andReturn([['start' => $start, 'end' => $end]]);
        $response = $this->actingAs($student)->getJson(route('meetings.availability', [$enrollment, 'date' => '2030-06-17']))->assertOk();
        $this->assertSame($tenAvailable, collect($response->json('slots'))->contains(fn ($slot) => str_contains($slot['slot_start'], 'T10:00:00')));
    }

    public static function overlapBoundaries(): array
    {
        return [
            ['2030-06-17T09:00:00+09:00', '2030-06-17T10:00:00+09:00', true],
            ['2030-06-17T11:00:00+09:00', '2030-06-17T12:00:00+09:00', true],
            ['2030-06-17T10:00:00+09:00', '2030-06-17T10:00:01+09:00', false],
            ['2030-06-17T10:59:59+09:00', '2030-06-17T11:00:00+09:00', false],
        ];
    }

    public function test_lms_bookings_other_coaches_and_different_qualification_do_not_corrupt_counts(): void
    {
        [$student, $coach, $enrollment] = $this->linked();
        $otherCoach = User::factory()->coach()->create();
        $enrollment->certification->coaches()->attach($otherCoach, ['assigned_by_user_id' => User::factory()->admin()->create()->id, 'assigned_at' => now()]);
        CoachAvailability::factory()->forCoach($otherCoach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        // Another qualification's linked coach must never be queried.
        $foreign = GoogleCalendarCredential::factory()->create();
        $this->gateway->shouldReceive('busy')->once()->with('test-only-access', \Mockery::any(), \Mockery::any())
            ->andReturn([['start' => '2030-06-17T10:00:00+09:00', 'end' => '2030-06-17T12:00:00+09:00']]);
        Meeting::factory()->forEnrollment($enrollment)->forCoach($coach)->create(['scheduled_at' => '2030-06-17 10:00:00']);
        $response = $this->actingAs($student)->getJson(route('meetings.availability', [$enrollment, 'date' => '2030-06-17']))->assertOk();
        $slots = collect($response->json('slots'))->keyBy(fn ($slot) => substr($slot['slot_start'], 11, 5));
        $this->assertSame(1, $slots['10:00']['available_coach_count']);
        $this->assertSame(1, $slots['11:00']['available_coach_count']);
        $this->assertSame(2, $slots['12:00']['available_coach_count']);
        $response->assertDontSee($foreign->id)->assertDontSee($coach->id);
    }

    public function test_reservation_rechecks_after_preview_and_rejects_a_new_busy_period_without_consumption(): void
    {
        [$student, , $enrollment] = $this->linked();
        $this->gateway->shouldReceive('busy')->twice()->andReturn([], [['start' => '2030-06-17T10:00:00+09:00', 'end' => '2030-06-17T11:00:00+09:00']]);
        $this->gateway->shouldNotReceive('createEvent');
        $this->actingAs($student)->getJson(route('meetings.availability', [$enrollment, 'date' => '2030-06-17']))->assertOk();
        $this->postJson(route('meetings.store', $enrollment), $this->payload())->assertStatus(409)->assertJsonPath('message', '指定された時刻には空きコーチがいません。別の時刻をお選びください。');
        $this->assertDatabaseCount('meetings', 0);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
        $this->assertSame(3, app(MeetingQuotaService::class)->remaining($student));
    }

    public function test_booking_syncs_fixed_url_name_and_hour_without_invites_then_ignores_replayed_event(): void
    {
        [$student, $coach, $enrollment, $credential] = $this->linked();
        $this->gateway->shouldReceive('createEvent')->once()->with('test-only-access', \Mockery::on(function ($event) use ($student, $coach) {
            $this->assertSame('Certify LMS面談｜'.$student->name, $event['summary']);
            $this->assertSame($coach->meeting_url, $event['location']);
            $this->assertStringContainsString('T10:00:00', $event['start']['dateTime']);
            $this->assertStringContainsString('T11:00:00', $event['end']['dateTime']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $event['id']);
            $this->assertArrayNotHasKey('attendees', $event);
            $this->assertArrayNotHasKey('conferenceData', $event);

            return true;
        }));
        $this->actingAs($student)->post(route('meetings.store', $enrollment), $this->payload())->assertRedirect();
        $meeting = Meeting::query()->sole();
        $this->assertSame($credential->id, $meeting->google_calendar_credential_id);
        $this->assertNotNull($meeting->google_calendar_created_at);
        $this->assertSame(2, app(MeetingQuotaService::class)->remaining($student));
        $this->assertDatabaseCount('notifications', 2);
        MeetingReserved::dispatch($meeting);
        $this->assertDatabaseCount('notifications', 2);
        $this->actingAs($student)->get(route('meetings.show', $meeting))->assertOk()->assertDontSee('test-only-access')->assertDontSee($meeting->google_calendar_event_id);
        $this->assertArrayNotHasKey('google_calendar_event_id', $meeting->toArray());
    }

    public function test_creation_failure_keeps_reservation_quota_and_one_failure_notification_even_after_replay(): void
    {
        [$student, $coach, $enrollment] = $this->linked();
        $this->gateway->shouldReceive('createEvent')->twice()->andThrow(new GoogleCalendarException);
        $this->actingAs($student)->post(route('meetings.store', $enrollment), $this->payload())->assertRedirect();
        $meeting = Meeting::query()->sole();
        $this->assertSame('reserved', $meeting->status->value);
        $this->assertNotNull($meeting->google_calendar_event_id);
        $this->assertNull($meeting->google_calendar_created_at);
        $this->assertSame(2, app(MeetingQuotaService::class)->remaining($student));
        $notice = $coach->notifications()->where('type', GoogleCalendarFailure::class)->sole();
        $notice->markAsRead();
        $original = $notice->fresh()->getRawOriginal();
        MeetingReserved::dispatch($meeting);
        $this->assertSame(1, $coach->notifications()->where('type', GoogleCalendarFailure::class)->count());
        $this->assertSame($original, $notice->fresh()->getRawOriginal());
    }

    public function test_cancel_deletes_google_event_once_and_refunds_once(): void
    {
        [$student, , $enrollment] = $this->linked();
        $this->actingAs($student)->post(route('meetings.store', $enrollment), $this->payload())->assertRedirect();
        $meeting = Meeting::query()->sole();
        $this->gateway->shouldReceive('deleteEvent')->once()->with('test-only-access', $meeting->google_calendar_event_id);
        $this->post(route('meetings.cancel', $meeting))->assertRedirect();
        $this->assertNotNull($meeting->fresh()->google_calendar_deleted_at);
        $this->assertSame(3, app(MeetingQuotaService::class)->remaining($student));
        MeetingCanceled::dispatch($meeting->fresh());
        $this->assertDatabaseCount('meeting_quota_transactions', 2);
        $this->assertDatabaseCount('notifications', 4);
    }

    public function test_google_delete_failure_does_not_roll_back_lms_cancellation_or_refund(): void
    {
        [$student, $coach, $enrollment] = $this->linked();
        $this->actingAs($student)->post(route('meetings.store', $enrollment), $this->payload())->assertRedirect();
        $meeting = Meeting::query()->sole();
        $original = $meeting->quotaTransaction->getRawOriginal();
        $this->gateway->shouldReceive('deleteEvent')->once()->andThrow(new GoogleCalendarException);
        $this->post(route('meetings.cancel', $meeting))->assertRedirect();
        $this->assertSame('canceled', $meeting->fresh()->status->value);
        $this->assertSame(3, app(MeetingQuotaService::class)->remaining($student));
        $this->assertSame($original, $meeting->quotaTransaction->fresh()->getRawOriginal());
        $this->assertSame(1, $coach->notifications()->where('type', GoogleCalendarFailure::class)->count());
    }

    public function test_disconnect_keeps_old_event_new_bookings_stay_local_and_old_cancel_does_not_delete(): void
    {
        [$student, $coach, $enrollment] = $this->linked();
        $this->actingAs($student)->post(route('meetings.store', $enrollment), $this->payload())->assertRedirect();
        $old = Meeting::query()->sole();
        app(DisconnectAction::class)($coach, app('session.store'));
        $this->gateway->shouldNotReceive('deleteEvent');
        $this->post(route('meetings.cancel', $old))->assertRedirect();
        $this->post(route('meetings.store', $enrollment), $this->payload())->assertRedirect();
        $new = Meeting::where('status', 'reserved')->sole();
        $this->assertNull($new->google_calendar_event_id);
        $this->assertNull($new->google_calendar_credential_id);
        $this->assertNotNull($old->fresh()->google_calendar_event_id);
        $this->assertNull($old->fresh()->google_calendar_deleted_at);
    }

    public function test_a_new_connection_generation_does_not_delete_the_previous_accounts_event(): void
    {
        [$student, $coach, $enrollment, $credential] = $this->linked();
        $this->actingAs($student)->post(route('meetings.store', $enrollment), $this->payload())->assertRedirect();
        $meeting = Meeting::query()->sole();
        $credential->delete();
        $new = GoogleCalendarCredential::factory()->create(['user_id' => $coach->id]);
        $this->gateway->shouldNotReceive('deleteEvent');
        $this->post(route('meetings.cancel', $meeting))->assertRedirect();
        $this->assertSame($credential->id, $meeting->fresh()->google_calendar_credential_id);
        $this->assertNotSame($new->id, $credential->id);
    }

    public function test_expired_tokens_are_refreshed_and_the_refresh_token_is_preserved(): void
    {
        [$student, , $enrollment, $credential] = $this->linked();
        $credential->update(['expires_at' => now()->subSecond()]);
        $this->gateway->shouldReceive('refresh')->once()->with('test-only-refresh')->andReturn(['access_token' => 'renewed-access', 'expires_in' => 3600]);
        $this->gateway->shouldReceive('busy')->once()->with('renewed-access', \Mockery::any(), \Mockery::any())->andReturn([]);
        $this->actingAs($student)->getJson(route('meetings.availability', [$enrollment, 'date' => '2030-06-17']))->assertOk();
        $this->assertSame('renewed-access', $credential->fresh()->access_token);
        $this->assertSame('test-only-refresh', $credential->fresh()->refresh_token);
        $this->assertTrue($credential->fresh()->expires_at->equalTo(now()->addHour()));
    }

    public function test_permanent_refresh_failure_warns_student_and_notifies_coach_once_without_resetting_read_state(): void
    {
        [$student, $coach, $enrollment, $credential] = $this->linked();
        $credential->update(['expires_at' => now()->subSecond()]);
        $this->gateway->shouldReceive('refresh')->once()->andThrow(new GoogleCalendarException(true));
        $this->gateway->shouldNotReceive('busy');
        $url = route('meetings.availability', [$enrollment, 'date' => '2030-06-17']);
        $this->actingAs($student)->getJson($url)->assertOk()->assertJsonPath('warning', GoogleCalendarAvailabilityService::WARNING)->assertJsonCount(9, 'slots');
        $this->assertTrue($credential->fresh()->requires_reconnect);
        $notice = $coach->notifications()->where('type', GoogleCalendarFailure::class)->sole();
        $notice->markAsRead();
        $original = $notice->fresh()->getRawOriginal();
        $this->getJson($url)->assertOk()->assertJsonCount(9, 'slots');
        $this->assertSame(1, $coach->notifications()->where('type', GoogleCalendarFailure::class)->count());
        $this->assertSame($original, $notice->fresh()->getRawOriginal());
        $this->assertSame(0, $student->notifications()->count());
    }

    public function test_transient_refresh_failure_does_not_disable_connection_and_later_attempt_recovers(): void
    {
        [$student, , $enrollment, $credential] = $this->linked();
        $credential->update(['expires_at' => now()->subSecond()]);
        $this->gateway->shouldReceive('refresh')->twice()->andReturnUsing(function () {
            static $attempt = 0;
            if ($attempt++ === 0) {
                throw new GoogleCalendarException;
            }

            return ['access_token' => 'recovered-access', 'expires_in' => 3600];
        });
        $url = route('meetings.availability', [$enrollment, 'date' => '2030-06-17']);
        $this->actingAs($student)->getJson($url)->assertOk()->assertJsonPath('warning', GoogleCalendarAvailabilityService::WARNING);
        $this->assertFalse($credential->fresh()->requires_reconnect);
        $this->getJson($url)->assertOk()->assertJsonMissingPath('warning');
        $this->assertSame('recovered-access', $credential->fresh()->access_token);
    }

    public function test_freebusy_failure_keeps_booking_and_displays_a_generic_student_warning(): void
    {
        [$student, , $enrollment] = $this->linked();
        $this->gateway->shouldReceive('busy')->once()->andThrow(new GoogleCalendarException);
        $this->actingAs($student)->post(route('meetings.store', $enrollment), $this->payload())->assertRedirect()
            ->assertSessionHas('warning', GoogleCalendarAvailabilityService::WARNING)->assertSessionHas('success');
        $this->assertDatabaseCount('meetings', 1);
        $this->assertSame(2, app(MeetingQuotaService::class)->remaining($student));
    }

    public function test_a_401_with_a_not_yet_expired_token_requires_reconnection_once(): void
    {
        [$student, $coach, $enrollment, $credential] = $this->linked();
        $this->gateway->shouldReceive('busy')->once()->andThrow(new GoogleCalendarException(true));
        $this->gateway->shouldReceive('refresh')->once()->andThrow(new GoogleCalendarException(true));
        $url = route('meetings.availability', [$enrollment, 'date' => '2030-06-17']);
        $this->actingAs($student)->getJson($url)->assertOk()->assertJsonPath('warning', GoogleCalendarAvailabilityService::WARNING);
        $this->getJson($url)->assertOk();
        $this->assertTrue($credential->fresh()->requires_reconnect);
        $this->assertSame(1, $coach->notifications()->where('type', GoogleCalendarFailure::class)->count());
    }

    public function test_unlinked_coaches_do_not_call_google_or_change_the_existing_json_contract(): void
    {
        [$student, , $enrollment] = $this->context();
        $this->gateway->shouldNotReceive('busy', 'createEvent', 'refresh');
        $response = $this->actingAs($student)->getJson(route('meetings.availability', [$enrollment, 'date' => '2030-06-17']))->assertOk();
        $this->assertSame(['date', 'slots'], array_keys($response->json()));
        $this->assertCount(9, $response->json('slots'));
        $this->post(route('meetings.store', $enrollment), $this->payload())->assertRedirect();
        $this->assertNull(Meeting::query()->sole()->google_calendar_event_id);
    }

    public function test_early_401_refreshes_once_and_retries_without_requiring_reconnection(): void
    {
        [$student, $coach, $enrollment, $credential] = $this->linked();
        $this->gateway->shouldReceive('busy')->with('test-only-access', \Mockery::any(), \Mockery::any())->once()->andThrow(new GoogleCalendarException(true));
        $this->gateway->shouldReceive('refresh')->once()->andReturn(['access_token' => 'early-renewed', 'expires_in' => 3600]);
        $this->gateway->shouldReceive('busy')->with('early-renewed', \Mockery::any(), \Mockery::any())->once()->andReturn([]);
        $this->actingAs($student)->getJson(route('meetings.availability', [$enrollment, 'date' => '2030-06-17']))->assertOk()->assertJsonMissingPath('warning');
        $this->assertFalse($credential->fresh()->requires_reconnect);
        $this->assertSame('early-renewed', $credential->fresh()->access_token);
        $this->assertSame(0, $coach->notifications()->count());
    }

    public function test_failure_notification_is_owner_only_and_uses_a_fixed_settings_target(): void
    {
        [$student, $coach, $enrollment] = $this->linked();
        $this->gateway->shouldReceive('createEvent')->once()->andThrow(new GoogleCalendarException);
        $this->actingAs($student)->post(route('meetings.store', $enrollment), $this->payload())->assertRedirect();
        $notice = $coach->notifications()->where('type', GoogleCalendarFailure::class)->sole();
        $this->post(route('notifications.markAsRead', $notice))->assertForbidden();
        $this->actingAs(User::factory()->coach()->create())->post(route('notifications.markAsRead', $notice))->assertForbidden();
        $notice->update(['data' => array_merge($notice->data, ['url' => 'https://evil.example'])]);
        $this->actingAs($coach)->post(route('notifications.markAsRead', $notice))->assertRedirect(route('settings.availability.index'));
        $this->assertNotNull($notice->fresh()->read_at);
    }

    public function test_isolated_demo_seed_has_linked_and_unlinked_coaches_only_in_testing(): void
    {
        $this->seed(GoogleCalendarDemoSeeder::class);
        $this->assertDatabaseCount('google_calendar_credentials', 1);
        $this->assertSame(2, User::where('role', 'coach')->count());
        $this->assertSame(1, User::where('role', 'coach')->whereDoesntHave('googleCredential')->count());
        app()->detectEnvironment(fn () => 'local');
        try {
            $this->seed(GoogleCalendarDemoSeeder::class);
            $this->fail('Local data must be protected.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('isolated', $exception->getMessage());
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }
}
