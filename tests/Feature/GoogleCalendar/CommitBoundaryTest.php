<?php

declare(strict_types=1);

namespace Tests\Feature\GoogleCalendar;

use App\Contracts\GoogleCalendarGateway;
use App\Exceptions\GoogleCalendarException;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\GoogleCalendarCredential;
use App\Models\Meeting;
use App\Models\MeetingQuotaTransaction;
use App\Models\User;
use App\Services\MeetingQuotaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** Real commits (no wrapping test transaction) plus an independent connection probe. */
#[Group('external-api')]
class CommitBoundaryTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (! app()->environment('testing') || DB::getDriverName() !== 'mysql'
            || ! preg_match('/^testing(?:_\w+)?$/', DB::connection()->getDatabaseName())) {
            throw new \RuntimeException('An isolated MySQL testing database is required.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2030-06-10 18:00:00');
    }

    protected function tearDown(): void
    {
        DB::disconnect('sa01_probe');
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function context(): array
    {
        $student = User::factory()->student()->create(['max_meetings' => 3]);
        $coach = User::factory()->coach()->create(['meeting_url' => 'https://meet.example.test/fixed']);
        $certification = Certification::factory()->published()->create();
        $certification->coaches()->attach($coach, ['assigned_by_user_id' => User::factory()->admin()->create()->id, 'assigned_at' => now()]);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        GoogleCalendarCredential::factory()->create(['user_id' => $coach->id]);

        return [$student, Enrollment::factory()->for($student)->for($certification)->learning()->create()];
    }

    #[DataProvider('writeOutcomes')]
    public function test_google_create_observes_committed_booking_and_consumption_even_when_google_fails(bool $fails): void
    {
        [$student, $enrollment] = $this->context();
        config(['database.connections.sa01_probe' => DB::connection()->getConfig()]);
        $gateway = $this->mock(GoogleCalendarGateway::class);
        $gateway->shouldReceive('busy')->once()->andReturn([]);
        $gateway->shouldReceive('createEvent')->once()->andReturnUsing(function ($token, $event) use ($fails) {
            $probe = DB::connection('sa01_probe');
            $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, (int) $probe->selectOne('SELECT CONNECTION_ID() AS id')->id);
            $id = $event['extendedProperties']['private']['lms_meeting_id'];
            $this->assertSame('reserved', $probe->table('meetings')->where('id', $id)->value('status'));
            $this->assertSame(1, $probe->table('meeting_quota_transactions')->where('related_meeting_id', $id)->count());
            $this->assertSame($event['id'], $probe->table('meetings')->where('id', $id)->value('google_calendar_event_id'));
            if ($fails) {
                throw new GoogleCalendarException;
            }
        });
        $this->actingAs($student)->post(route('meetings.store', $enrollment), ['scheduled_at' => '2030-06-17T10:00:00', 'topic' => 'S-A-01 commit check'])->assertRedirect();
        $this->assertDatabaseCount('meetings', 1);
        $this->assertSame(2, app(MeetingQuotaService::class)->remaining($student));
        $this->assertSame($fails, Meeting::query()->sole()->google_calendar_created_at === null);
    }

    public static function writeOutcomes(): array
    {
        return [[false], [true]];
    }

    public function test_rolled_back_booking_never_creates_a_google_event(): void
    {
        [$student, $enrollment] = $this->context();
        $gateway = $this->mock(GoogleCalendarGateway::class);
        $gateway->shouldReceive('busy')->once()->andReturn([]);
        $gateway->shouldNotReceive('createEvent');
        MeetingQuotaTransaction::created(function () {
            throw new \RuntimeException('synthetic-ledger-failure');
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($student)->post(route('meetings.store', $enrollment), ['scheduled_at' => '2030-06-17T10:00:00', 'topic' => 'S-A-01 rollback check']);
            $this->fail('Expected ledger failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('synthetic-ledger-failure', $exception->getMessage());
        } finally {
            MeetingQuotaTransaction::flushEventListeners();
        }
        $this->assertDatabaseCount('meetings', 0);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame(3, app(MeetingQuotaService::class)->remaining($student));
    }
}
