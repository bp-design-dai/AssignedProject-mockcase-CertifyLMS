<?php

declare(strict_types=1);

namespace Tests\Feature\GoogleCalendar;

use App\Contracts\GoogleCalendarGateway;
use App\Exceptions\GoogleCalendarException;
use App\Integrations\GoogleCalendar\GoogleApiCalendarGateway;
use App\Models\GoogleCalendarCredential;
use App\Notifications\GoogleCalendarFailure;
use App\Services\GoogleCalendarAvailabilityService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MeetingActionTestCase;

#[Group('external-api')]
class TokenPrivacyTest extends MeetingActionTestCase
{
    public function test_failed_refresh_storage_then_second_401_never_saves_dirty_tokens_with_authentication_state(): void
    {
        [$student, $coach, $enrollment] = $this->context();
        $credential = GoogleCalendarCredential::factory()->create(['user_id' => $coach->id]);
        $gateway = $this->mock(GoogleCalendarGateway::class);
        $gateway->shouldReceive('busy')->twice()->andThrow(new GoogleCalendarException(true));
        $gateway->shouldReceive('refresh')->once()->andReturn(['access_token' => 'never-log-this-token', 'expires_in' => 3600]);
        Log::spy();
        GoogleCalendarCredential::updating(function ($model) {
            if ($model->isDirty('access_token')) {
                throw new \RuntimeException('never-log-this-token');
            }
        });
        try {
            $this->actingAs($student)->getJson(route('meetings.availability', [$enrollment, 'date' => '2030-06-17']))
                ->assertOk()->assertJsonPath('warning', GoogleCalendarAvailabilityService::WARNING)->assertDontSee('never-log-this-token');
        } finally {
            GoogleCalendarCredential::flushEventListeners();
        }
        $this->assertTrue($credential->fresh()->requires_reconnect);
        $this->assertSame('test-only-access', $credential->fresh()->access_token);
        Log::shouldNotHaveReceived('error');
    }

    public function test_oauth_storage_failure_is_sanitized_and_rolls_back_the_previous_connection(): void
    {
        $credential = GoogleCalendarCredential::factory()->create();
        $original = $credential->fresh()->getRawOriginal();
        $this->mock(GoogleCalendarGateway::class)->shouldReceive('exchange')->once()->andReturn([
            'access_token' => 'never-log-this-token', 'refresh_token' => 'never-log-this-refresh', 'expires_in' => 3600,
            'scope' => implode(' ', GoogleApiCalendarGateway::SCOPES),
        ]);
        Log::spy();
        GoogleCalendarCredential::creating(function () {
            throw new QueryException('testing', 'insert into google_calendar_credentials (access_token) values (?)', ['never-log-this-token'], new \RuntimeException('synthetic-storage-failure'));
        });
        try {
            $this->actingAs($credential->user)->withSession(['google_calendar_oauth' => [
                'state' => 'test-state', 'verifier' => str_repeat('v', 96), 'user_id' => $credential->user_id, 'expires' => now()->addMinutes(10)->timestamp,
            ]])->get(route('settings.google-calendar.callback', ['state' => 'test-state', 'code' => 'test-code']))
                ->assertRedirect(route('settings.availability.index'))->assertSessionHas('error');
        } finally {
            GoogleCalendarCredential::flushEventListeners();
        }
        $this->assertSame($original, $credential->fresh()->getRawOriginal());
        Log::shouldHaveReceived('warning')->once()->with('Google Calendar credential save failed');
        Log::shouldNotHaveReceived('error');
    }

    public function test_refresh_storage_failure_is_sanitized_and_does_not_prevent_a_successful_availability_response(): void
    {
        [$student, $coach, $enrollment] = $this->context();
        $credential = GoogleCalendarCredential::factory()->create(['user_id' => $coach->id, 'expires_at' => now()->subSecond()]);
        $gateway = $this->mock(GoogleCalendarGateway::class);
        $gateway->shouldReceive('refresh')->once()->andReturn(['access_token' => 'never-log-this-token', 'expires_in' => 3600]);
        $gateway->shouldReceive('busy')->once()->with('never-log-this-token', \Mockery::any(), \Mockery::any())->andReturn([]);
        Log::spy();
        GoogleCalendarCredential::updating(function () {
            throw new \RuntimeException('never-log-this-token');
        });
        try {
            $this->actingAs($student)->getJson(route('meetings.availability', [$enrollment, 'date' => '2030-06-17']))->assertOk()->assertJsonCount(9, 'slots')->assertDontSee('never-log-this-token');
        } finally {
            GoogleCalendarCredential::flushEventListeners();
        }
        $this->assertSame('test-only-access', $credential->fresh()->access_token);
        $this->assertSame(1, $coach->notifications()->where('type', GoogleCalendarFailure::class)->count());
        Log::shouldHaveReceived('warning')->once()->with('Google Calendar token storage failed', ['exception_class' => \RuntimeException::class]);
        Log::shouldNotHaveReceived('error');
    }
}
