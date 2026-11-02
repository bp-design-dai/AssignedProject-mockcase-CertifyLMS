<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Meeting;

use App\Events\MeetingCanceled;
use App\Events\MeetingReserved;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\MeetingQuotaTransaction;
use App\Models\User;
use App\Services\MeetingQuotaService;
use App\UseCases\MeetingQuota\ConsumeQuotaAction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BookingConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent-connection locking requires MySQL.');
        }
        if (! app()->environment('testing') || ! preg_match('/^testing(?:_\w+)?$/', DB::connection()->getDatabaseName())) {
            throw new \RuntimeException('Only an isolated MySQL testing database may be migrated.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2030-06-10 18:00:00', 'Asia/Tokyo'));
        Event::fake([MeetingReserved::class, MeetingCanceled::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_parallel_same_coach_and_slot_has_one_winner_and_only_the_winner_spends_quota(): void
    {
        // Repeat with fresh users/rows; every round overlaps two real MySQL transactions.
        for ($round = 0; $round < 3; $round++) {
            [$coaches, $enrollments] = $this->context();
            $this->assertSame(0, Meeting::where('coach_id', $coaches[0]->id)->count());
            $results = $this->reserveTogether($coaches, $enrollments, ['2030-06-17T10:00:00', '2030-06-17T10:00:00']);
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame([302, 409], $statuses);
            $meeting = Meeting::where('coach_id', $coaches[0]->id)->sole();
            $this->assertSame('reserved', $meeting->status->value);
            foreach ($results as $result) {
                $student = User::findOrFail($result['student_id']);
                $won = $result['status'] === 302;
                $this->assertSame($won ? 2 : 3, app(MeetingQuotaService::class)->remaining($student));
                $this->assertSame($won ? 1 : 0, MeetingQuotaTransaction::where('user_id', $student->id)->count());
                if ($won) {
                    $this->assertSame($student->id, $meeting->student_id);
                }
            }
        }
    }

    public function test_parallel_same_slot_can_use_two_different_available_coaches(): void
    {
        [$coaches, $enrollments] = $this->context(2);
        $results = $this->reserveTogether($coaches, $enrollments, ['2030-06-17T10:00:00', '2030-06-17T10:00:00']);
        $this->assertSame([302, 302], array_column($results, 'status'));
        $this->assertSame(2, Meeting::distinct()->count('coach_id'));
        $this->assertSuccessfulBalances($enrollments);
    }

    public function test_parallel_different_slots_can_use_the_same_coach(): void
    {
        [$coaches, $enrollments] = $this->context();
        $results = $this->reserveTogether($coaches, $enrollments, ['2030-06-17T10:00:00', '2030-06-17T11:00:00']);
        $this->assertSame([302, 302], array_column($results, 'status'));
        $this->assertSame(2, Meeting::distinct()->count('scheduled_at'));
        $this->assertSuccessfulBalances($enrollments);
    }

    public function test_parallel_reservations_for_different_certifications_still_share_the_coach_lock(): void
    {
        [$coaches, $enrollments] = $this->context();
        $other = Certification::factory()->published()->create();
        $other->coaches()->attach($coaches[0], ['assigned_by_user_id' => User::factory()->admin()->create()->id, 'assigned_at' => now()]);
        $enrollments[1]->update(['certification_id' => $other->id]);
        $results = $this->reserveTogether($coaches, $enrollments, ['2030-06-17T10:00:00', '2030-06-17T10:00:00']);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([302, 409], $statuses);
        $this->assertDatabaseCount('meetings', 1);
        $this->assertDatabaseCount('meeting_quota_transactions', 1);
    }

    public function test_same_students_last_quota_cannot_be_spent_twice_across_different_coaches(): void
    {
        [$firstCoaches, $firstEnrollments] = $this->context();
        [$secondCoaches, $secondEnrollments] = $this->context();
        $student = $firstEnrollments[0]->user;
        $student->update(['max_meetings' => 1]);
        $secondEnrollments[0]->update(['user_id' => $student->id]);

        $results = $this->reserveTogether([$firstCoaches[0], $secondCoaches[0]], [$firstEnrollments[0], $secondEnrollments[0]], ['2030-06-17T10:00:00', '2030-06-17T11:00:00']);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([302, 409], $statuses);
        $this->assertDatabaseCount('meetings', 1);
        $this->assertDatabaseCount('meeting_quota_transactions', 1);
        $this->assertSame(0, app(MeetingQuotaService::class)->remaining($student));
    }

    public function test_reservation_waits_for_concurrent_cancellation_then_reuses_the_slot(): void
    {
        [$coaches, $enrollments] = $this->context();
        $old = Meeting::factory()->for($enrollments[0])->forStudent($enrollments[0]->user)->forCoach($coaches[0])
            ->reserved()->create(['scheduled_at' => '2030-06-17 10:00:00']);
        $consumed = app(ConsumeQuotaAction::class)($old->student, $old->id);
        $old->update(['meeting_quota_transaction_id' => $consumed->id]);
        $original = $consumed->fresh()->getRawOriginal();

        $results = $this->reserveTogether($coaches, [$enrollments[1]], ['2030-06-17T10:00:00'], function () use ($old) {
            $this->actingAs($old->student)->postJson(route('meetings.cancel', $old))->assertRedirect();
        });

        $this->assertSame([302], array_column($results, 'status'));
        $this->assertSame('canceled', $old->fresh()->status->value);
        $this->assertSame($original, $consumed->fresh()->getRawOriginal());
        $this->assertSame(1, Meeting::where('status', 'reserved')->count());
        $this->assertSame(1, MeetingQuotaTransaction::where('related_meeting_id', $old->id)->where('type', 'refunded')->count());
        $this->assertSame(3, app(MeetingQuotaService::class)->remaining($old->student));
        $this->assertSame(2, app(MeetingQuotaService::class)->remaining($enrollments[1]->user));
    }

    private function reserveTogether(array $coaches, array $enrollments, array $slots, ?callable $whileBlocked = null): array
    {
        $processes = [];
        DB::beginTransaction();
        try {
            // A real existing parent row is held even when no booking exists yet.
            User::whereKey(array_map(fn (User $coach) => $coach->id, $coaches))->orderBy('id')->lockForUpdate()->get();
            $parentId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            foreach ($enrollments as $index => $enrollment) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/MeetingReservationWorker.php'), $enrollment->user_id, $enrollment->id, $slots[$index]], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
                    'DB_DATABASE' => DB::connection()->getDatabaseName(),
                    'CACHE_DRIVER' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                ], timeout: 25);
                $process->start();
                $processes[] = $process;
            }

            $ready = [];
            foreach ($processes as $process) {
                $ready[] = $this->await(function () use ($process) {
                    $message = $this->message($process, 'ready');
                    if ($message === null && ! $process->isRunning()) {
                        $this->fail('Worker exited before connecting: '.$process->getOutput().$process->getErrorOutput());
                    }

                    return $message;
                }, 'Worker did not open its independent connection.');
            }
            $ids = array_map(fn (array $row) => (int) $row['connection']['id'], $ready);
            $this->assertCount(count($processes), array_unique($ids));
            $this->assertNotContains($parentId, $ids);

            // SHOW PROCESSLIST exposes this DB user's own connections without PROCESS grants.
            // Both workers must be executing a statement that is blocked by the held coach row.
            $waiting = $this->await(function () use ($ids) {
                $rows = array_values(array_filter(DB::select('SHOW FULL PROCESSLIST'), fn ($row) => in_array((int) $row->Id, $ids, true)
                    && $row->Command !== 'Sleep'
                    && (str_contains(strtolower($row->Info ?? ''), 'for update') || str_starts_with(strtolower($row->Info ?? ''), 'insert into `meetings`'))));

                return count($rows) === count($ids) ? $rows : null;
            }, 'Workers did not overlap at the locked coach row.');
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), $process->getOutput().$process->getErrorOutput());
            }

            $whileBlocked?->__invoke();
            DB::commit();
            $results = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getOutput().$process->getErrorOutput());
                $result = $this->message($process, 'status');
                $this->assertNotNull($result, $process->getOutput());
                $results[] = $result;
            }

            if ($report = getenv('BA01_CONCURRENCY_REPORT')) {
                file_put_contents($report, json_encode(['test' => $this->name(), 'parent_connection_id' => $parentId, 'workers' => $ready, 'blocked_statements' => $waiting, 'results' => $results], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
            }

            return $results;
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0.1);
                }
            }
        }
    }

    private function await(callable $condition, string $failure): mixed
    {
        $deadline = microtime(true) + 10;
        do {
            if ($value = $condition()) {
                return $value;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail($failure);
    }

    private function message(Process $process, string $key): ?array
    {
        foreach (explode("\n", $process->getOutput()) as $line) {
            $data = json_decode($line, true);
            if (is_array($data) && array_key_exists($key, $data)) {
                return $data;
            }
        }

        return null;
    }

    private function context(int $coachCount = 1): array
    {
        $certification = Certification::factory()->published()->create();
        $admin = User::factory()->admin()->create();
        $coaches = User::factory()->coach()->inProgress()->count($coachCount)->create()->all();
        foreach ($coaches as $coach) {
            $certification->coaches()->attach($coach, ['assigned_by_user_id' => $admin->id, 'assigned_at' => now()]);
            CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        }
        $enrollments = User::factory()->student()->inProgress()->count(2)->create(['max_meetings' => 3])
            ->map(fn (User $student) => Enrollment::factory()->for($student)->for($certification)->learning()->create())->all();

        return [$coaches, $enrollments];
    }

    private function assertSuccessfulBalances(array $enrollments): void
    {
        $this->assertDatabaseCount('meetings', 2);
        $this->assertDatabaseCount('meeting_quota_transactions', 2);
        foreach ($enrollments as $enrollment) {
            $this->assertSame(2, app(MeetingQuotaService::class)->remaining($enrollment->user));
        }
    }
}
