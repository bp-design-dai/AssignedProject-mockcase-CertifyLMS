<?php

declare(strict_types=1);

namespace Tests\Feature\Http\AiChat;

use App\Models\AiChatConversation;
use App\Models\AiChatDailyUsage;
use App\Models\AiChatMessage;
use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('external-api')]
class ConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL concurrency required');
        }
        if (! app()->environment('testing') || ! preg_match('/^testing(?:_\w+)?$/', DB::connection()->getDatabaseName())) {
            throw new \RuntimeException('Only isolated testing DBs may be migrated');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2030-06-10 18:00:00', 'Asia/Tokyo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_parallel_last_daily_slot_is_consumed_once_across_conversations(): void
    {
        for ($round = 0; $round < 3; $round++) {
            $user = User::factory()->student()->inProgress()->create();
            $conversations = AiChatConversation::factory()->count(2)->create(['user_id' => $user->id, 'auto_title' => false]);
            AiChatDailyUsage::create(['user_id' => $user->id, 'usage_date' => '2030-06-10', 'attempts' => 29]);
            $results = $this->together($user, $conversations->pluck('id')->all());
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame([200, 429], $statuses);
            $this->assertSame(30, AiChatDailyUsage::where('user_id', $user->id)->sole()->attempts);
            $this->assertSame(1, AiChatMessage::whereIn('conversation_id', $conversations->pluck('id'))->where('role', 'user')->count());
        }
    }

    public function test_parallel_first_usage_row_respects_configured_limit(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $conversations = AiChatConversation::factory()->count(2)->create(['user_id' => $user->id, 'auto_title' => false]);
        $results = $this->together($user, $conversations->pluck('id')->all(), limit: 1);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 429], $statuses);
        $this->assertSame(1, AiChatDailyUsage::sole()->attempts);
    }

    public function test_parallel_failure_still_uses_the_final_daily_slot(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $conversations = AiChatConversation::factory()->count(2)->create(['user_id' => $user->id, 'auto_title' => false]);
        AiChatDailyUsage::create(['user_id' => $user->id, 'usage_date' => '2030-06-10', 'attempts' => 29]);
        $results = $this->together($user, $conversations->pluck('id')->all(), fail: true);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([429, 502], $statuses);
        $this->assertSame(30, AiChatDailyUsage::sole()->attempts);
        $this->assertSame(1, AiChatMessage::where('role', 'user')->count());
        $this->assertSame(1, AiChatMessage::where('status', 'error')->count());
    }

    public function test_parallel_same_section_creation_reuses_one_conversation(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        Enrollment::factory()->create(['user_id' => $user->id, 'certification_id' => $certification->id]);
        $part = Part::factory()->published()->create(['certification_id' => $certification->id]);
        $chapter = Chapter::factory()->published()->create(['part_id' => $part->id]);
        $section = Section::factory()->published()->create(['chapter_id' => $chapter->id]);
        $results = $this->together($user, [$section->id, $section->id], create: true);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 201], $statuses);
        $this->assertSame($results[0]['conversation_id'], $results[1]['conversation_id']);
        $this->assertDatabaseCount('ai_chat_conversations', 1);
        $this->assertDatabaseCount('ai_chat_daily_usages', 0);
    }

    private function together(User $user, array $ids, bool $create = false, int $limit = 30, bool $fail = false): array
    {
        $processes = [];
        DB::beginTransaction();
        try {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $parentId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            foreach ($ids as $id) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/AiChatSendWorker.php'), $user->id, $id, $create ? 'create' : 'send', (string) $limit], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_DATABASE' => DB::connection()->getDatabaseName(),
                    'CACHE_DRIVER' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SA02_FAIL_PROVIDER' => $fail ? '1' : '',
                ], timeout: 25);
                $process->start();
                $processes[] = $process;
            }
            $ready = [];
            foreach ($processes as $process) {
                $ready[] = $this->await(fn () => $this->message($process, 'ready'), 'Worker did not connect');
            }
            $connectionIds = array_map(fn ($r) => (int) $r['connection']['id'], $ready);
            $this->assertCount(2, array_unique($connectionIds));
            $this->assertNotContains($parentId, $connectionIds);
            $blocked = $this->await(function () use ($connectionIds) {
                $rows = array_values(array_filter(DB::select('SHOW FULL PROCESSLIST'), fn ($r) => in_array((int) $r->Id, $connectionIds, true) && str_contains(strtolower($r->Info ?? ''), 'for update')));

                return count($rows) === 2 ? $rows : null;
            }, 'Both workers must overlap waiting for the user lock');
            DB::commit();
            $results = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getOutput().$process->getErrorOutput());
                $result = $this->message($process, 'status');
                $this->assertNotNull($result, $process->getOutput());
                $results[] = $result;
            }
            if ($path = getenv('SA02_CONCURRENCY_REPORT')) {
                file_put_contents($path, json_encode(['test' => $this->name(), 'parent_connection_id' => $parentId, 'workers' => $ready, 'blocked_statements' => $blocked, 'results' => $results]).PHP_EOL, FILE_APPEND | LOCK_EX);
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
        $end = microtime(true) + 10;
        do {
            if ($value = $condition()) {
                return $value;
            } usleep(10000);
        } while (microtime(true) < $end);
        $this->fail($failure);
    }

    private function message(Process $process, string $key): ?array
    {
        foreach (explode("\n", $process->getOutput()) as $line) {
            $value = json_decode($line, true);
            if (is_array($value) && array_key_exists($key, $value)) {
                return $value;
            }
        }

        return null;
    }
}
