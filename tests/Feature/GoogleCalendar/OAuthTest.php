<?php

declare(strict_types=1);

namespace Tests\Feature\GoogleCalendar;

use App\Contracts\GoogleCalendarGateway;
use App\Exceptions\GoogleCalendarException;
use App\Integrations\GoogleCalendar\GoogleApiCalendarGateway;
use App\Models\GoogleCalendarCredential;
use App\Models\Meeting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('external-api')]
class OAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2030-06-10 18:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pending(User $coach): array
    {
        return ['user_id' => $coach->id, 'state' => 'one-use-state', 'verifier' => str_repeat('v', 96), 'expires' => now()->addMinutes(10)->timestamp];
    }

    private function tokens(): array
    {
        return ['access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh', 'expires_in' => 3600,
            'scope' => implode(' ', GoogleApiCalendarGateway::SCOPES)];
    }

    public function test_connect_binds_state_and_pkce_to_the_logged_in_coach_and_ignores_return_url(): void
    {
        $coach = User::factory()->coach()->create();
        $this->mock(GoogleCalendarGateway::class)->shouldReceive('authorizationUrl')->once()->andReturnUsing(function ($state, $verifier) {
            $this->assertSame(64, strlen($state));
            $this->assertSame(96, strlen($verifier));

            return 'https://accounts.google.com/o/oauth2/v2/auth?state='.$state;
        });
        $this->actingAs($coach)->get(route('settings.google-calendar.redirect', ['redirect_path' => 'https://evil.example']))
            ->assertRedirect()->assertSessionHas('google_calendar_oauth', fn ($data) => $data['user_id'] === $coach->id && $data['expires'] === now()->addMinutes(10)->timestamp);
        $this->assertDatabaseCount('google_calendar_credentials', 0);
    }

    #[DataProvider('otherRoles')]
    public function test_non_coaches_cannot_connect_callback_or_disconnect(string $role, string $method, string $route): void
    {
        $this->mock(GoogleCalendarGateway::class)->shouldNotReceive('authorizationUrl', 'exchange');
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user)->{$method}(route('settings.google-calendar.'.$route))->assertForbidden();
        $this->assertDatabaseCount('google_calendar_credentials', 0);
    }

    public static function otherRoles(): array
    {
        $cases = [];
        foreach (['student', 'admin'] as $role) {
            foreach ([['get', 'redirect'], ['get', 'callback'], ['delete', 'destroy']] as [$method, $route]) {
                $cases[] = [$role, $method, $route];
            }
        }

        return $cases;
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('settings.google-calendar.redirect'))->assertRedirect(route('login'));
        $this->get(route('settings.google-calendar.callback'))->assertRedirect(route('login'));
        $this->delete(route('settings.google-calendar.destroy'))->assertRedirect(route('login'));
    }

    public function test_callback_saves_only_the_authenticated_coachs_primary_credentials_and_cannot_replay(): void
    {
        $coach = User::factory()->coach()->create();
        $other = User::factory()->coach()->create();
        $this->mock(GoogleCalendarGateway::class)->shouldReceive('exchange')->once()->with('synthetic-code', str_repeat('v', 96))->andReturn($this->tokens());
        $url = route('settings.google-calendar.callback', ['state' => 'one-use-state', 'code' => 'synthetic-code', 'user_id' => $other->id, 'calendar_id' => 'other']);
        $this->actingAs($coach)->withSession(['google_calendar_oauth' => $this->pending($coach)])->get($url)
            ->assertRedirect(route('settings.availability.index'))->assertSessionHas('success')->assertSessionMissing('google_calendar_oauth');
        $credential = GoogleCalendarCredential::query()->sole();
        $this->assertSame($coach->id, $credential->user_id);
        $this->assertSame('primary', $credential->calendar_id);
        $this->assertSame('synthetic-access', $credential->access_token);
        $this->assertSame('synthetic-refresh', $credential->refresh_token);
        $this->assertTrue($credential->expires_at->equalTo(now()->addHour()));
        $this->assertTrue($credential->connected_at->equalTo(now()));
        $this->get($url)->assertForbidden();
        $this->assertDatabaseCount('google_calendar_credentials', 1);
    }

    #[DataProvider('invalidStates')]
    public function test_invalid_state_is_rejected_without_contacting_google(string $case): void
    {
        $coach = User::factory()->coach()->create();
        $pending = $this->pending($coach);
        $state = 'one-use-state';
        if ($case === 'mismatch') {
            $state = 'wrong';
        }
        if ($case === 'missing') {
            $state = null;
        }
        if ($case === 'expired') {
            $pending['expires'] = now()->subSecond()->timestamp;
        }
        if ($case === 'other-owner') {
            $pending['user_id'] = User::factory()->coach()->create()->id;
        }
        $this->mock(GoogleCalendarGateway::class)->shouldNotReceive('exchange');
        $this->actingAs($coach)->withSession($case === 'no-session' ? [] : ['google_calendar_oauth' => $pending])
            ->get(route('settings.google-calendar.callback', ['state' => $state, 'code' => 'synthetic-code']))->assertForbidden();
        $this->assertDatabaseCount('google_calendar_credentials', 0);
    }

    public static function invalidStates(): array
    {
        return [['mismatch'], ['missing'], ['expired'], ['other-owner'], ['no-session']];
    }

    public function test_denial_consumes_state_and_preserves_an_existing_connection(): void
    {
        $credential = GoogleCalendarCredential::factory()->create();
        $coach = $credential->user;
        $original = $credential->fresh()->getRawOriginal();
        $this->mock(GoogleCalendarGateway::class)->shouldNotReceive('exchange');
        $this->actingAs($coach)->withSession(['google_calendar_oauth' => $this->pending($coach)])
            ->get(route('settings.google-calendar.callback', ['state' => 'one-use-state', 'error' => 'access_denied']))
            ->assertRedirect(route('settings.availability.index'))->assertSessionHas('error')->assertSessionMissing('google_calendar_oauth');
        $this->assertSame($original, $credential->fresh()->getRawOriginal());
    }

    #[DataProvider('badTokens')]
    public function test_partial_consent_missing_refresh_or_provider_failure_does_not_replace_credentials(string $case): void
    {
        $credential = GoogleCalendarCredential::factory()->create();
        $coach = $credential->user;
        $gateway = $this->mock(GoogleCalendarGateway::class);
        if ($case === 'provider') {
            $gateway->shouldReceive('exchange')->once()->andThrow(new GoogleCalendarException);
        } else {
            $tokens = $this->tokens();
            if ($case === 'scope') {
                $tokens['scope'] = GoogleApiCalendarGateway::SCOPES[0];
            }
            if ($case === 'refresh') {
                unset($tokens['refresh_token']);
            }
            $gateway->shouldReceive('exchange')->once()->andReturn($tokens);
        }
        $this->actingAs($coach)->withSession(['google_calendar_oauth' => $this->pending($coach)])
            ->get(route('settings.google-calendar.callback', ['state' => 'one-use-state', 'code' => 'synthetic-code']))
            ->assertSessionHas('error')->assertRedirect(route('settings.availability.index'));
        $this->assertSame($credential->id, GoogleCalendarCredential::query()->sole()->id);
    }

    public static function badTokens(): array
    {
        return [['scope'], ['refresh'], ['provider']];
    }

    public function test_reconnect_creates_a_new_generation_without_rewriting_old_meetings(): void
    {
        $credential = GoogleCalendarCredential::factory()->create(['requires_reconnect' => true]);
        $meeting = Meeting::factory()->forCoach($credential->user)->create(['google_calendar_credential_id' => $credential->id, 'google_calendar_event_id' => str_repeat('a', 64)]);
        $original = $meeting->fresh()->getRawOriginal();
        $this->mock(GoogleCalendarGateway::class)->shouldReceive('exchange')->once()->andReturn($this->tokens());
        $this->actingAs($credential->user)->withSession(['google_calendar_oauth' => $this->pending($credential->user)])
            ->get(route('settings.google-calendar.callback', ['state' => 'one-use-state', 'code' => 'synthetic-code']))->assertSessionHas('success');
        $new = GoogleCalendarCredential::query()->sole();
        $this->assertNotSame($credential->id, $new->id);
        $this->assertFalse($new->requires_reconnect);
        $this->assertSame($original, $meeting->fresh()->getRawOriginal());
    }

    public function test_disconnect_only_removes_own_credentials_and_never_deletes_google_events(): void
    {
        $own = GoogleCalendarCredential::factory()->create();
        $other = GoogleCalendarCredential::factory()->create();
        $meeting = Meeting::factory()->forCoach($own->user)->create(['google_calendar_credential_id' => $own->id, 'google_calendar_event_id' => str_repeat('b', 64)]);
        $original = $meeting->fresh()->getRawOriginal();
        $this->mock(GoogleCalendarGateway::class)->shouldNotReceive('deleteEvent');
        $this->actingAs($own->user)->withSession(['google_calendar_oauth' => $this->pending($own->user)])
            ->delete(route('settings.google-calendar.destroy'), ['user_id' => $other->user_id])
            ->assertRedirect(route('settings.availability.index'))->assertSessionMissing('google_calendar_oauth');
        $this->assertNull($own->fresh());
        $this->assertNotNull($other->fresh());
        $this->assertSame($original, $meeting->fresh()->getRawOriginal());
    }

    public function test_tokens_are_hidden_from_json_and_settings_html_including_reconnect_state(): void
    {
        $credential = GoogleCalendarCredential::factory()->create(['requires_reconnect' => true]);
        $json = $credential->user->load('googleCredential')->toJson();
        $this->assertStringNotContainsString($credential->access_token, $json);
        $this->assertStringNotContainsString($credential->refresh_token, $json);
        $this->actingAs($credential->user)->get(route('settings.availability.index'))->assertOk()->assertSee('再連携する')
            ->assertDontSee($credential->access_token)->assertDontSee($credential->refresh_token);
    }

    public function test_unconfigured_connect_returns_a_safe_error_without_an_external_request(): void
    {
        config(['services.google_calendar.client_id' => null, 'services.google_calendar.client_secret' => null, 'services.google_calendar.redirect_uri' => null]);
        $this->actingAs(User::factory()->coach()->create())->get(route('settings.google-calendar.redirect'))
            ->assertRedirect(route('settings.availability.index'))->assertSessionHas('error');
    }
}
