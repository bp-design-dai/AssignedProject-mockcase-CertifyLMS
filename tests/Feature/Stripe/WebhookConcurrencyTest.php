<?php

declare(strict_types=1);

namespace Tests\Feature\Stripe;

use App\Enums\PaymentStatus;
use App\Models\MeetingQuotaTransaction;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use App\Services\MeetingQuotaService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\StripeFixtures;
use Tests\TestCase;

class WebhookConcurrencyTest extends TestCase
{
    use DatabaseMigrations, StripeFixtures;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL independent connections are required.');
        }
        if (! app()->environment('testing') || ! preg_match('/^testing(?:_\w+)?$/', DB::connection()->getDatabaseName())) {
            throw new \RuntimeException('Only an isolated testing DB may be migrated.');
        }
    }

    #[DataProvider('eventKinds')]
    public function test_parallel_webhooks_share_one_fulfillment_even_with_distinct_success_events(bool $distinct): void
    {
        $this->fakeStripe();
        for ($round = 0; $round < 3; $round++) {
            $buyer = User::factory()->create(['max_meetings' => 0]);
            $payment = $this->pendingPayment(['user_id' => $buyer->id, 'quantity' => 3]);
            $processes = [];
            DB::beginTransaction();
            try {
                User::whereKey($buyer->id)->lockForUpdate()->firstOrFail();
                $parentId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
                foreach ([0, 1] as $index) {
                    $process = new Process([PHP_BINARY, base_path('tests/Support/StripeWebhookWorker.php'), $payment->id,
                        'evt_'.$payment->id.($distinct ? '_'.$index : ''), $distinct && $index === 1 ? 'checkout.session.async_payment_succeeded' : 'checkout.session.completed'], base_path(), [
                            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_DATABASE' => DB::connection()->getDatabaseName(),
                            'SESSION_DRIVER' => 'array', 'CACHE_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                        ], timeout: 35);
                    $process->start();
                    $processes[] = $process;
                }
                $ready = [];
                foreach ($processes as $process) {
                    $ready[] = $this->await(function () use ($process) {
                        $value = $this->message($process, 'ready');
                        if (! $value && ! $process->isRunning()) {
                            $this->fail($process->getOutput().$process->getErrorOutput());
                        }

                        return $value;
                    });
                }
                $ids = array_column($ready, 'connection_id');
                $this->assertCount(2, array_unique($ids));
                $this->assertNotContains($parentId, $ids);
                $this->await(function () use ($ids) {
                    return count(array_filter(DB::select('SHOW FULL PROCESSLIST'), fn ($row) => in_array((int) $row->Id, $ids, true)
                        && $row->Command !== 'Sleep' && str_contains(strtolower($row->Info ?? ''), 'for update'))) === 2;
                });
                foreach ($processes as $process) {
                    $this->assertTrue($process->isRunning());
                }
                DB::commit();
                $results = [];
                foreach ($processes as $process) {
                    $this->assertSame(0, $process->wait(), $process->getOutput().$process->getErrorOutput());
                    $results[] = $this->message($process, 'status');
                }
                $this->assertSame([200, 200], array_column($results, 'status'));
                $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
                $this->assertSame(1, MeetingQuotaTransaction::where('related_payment_id', $payment->id)->count());
                $this->assertSame(3, app(MeetingQuotaService::class)->remaining($buyer));
                $this->assertSame($distinct ? 2 : 1, StripeWebhookEvent::where('payment_id', $payment->id)->count());
                if ($report = getenv('SA03_CONCURRENCY_REPORT')) {
                    file_put_contents($report, json_encode(['distinct_success_events' => $distinct, 'round' => $round,
                        'parent_connection_id' => $parentId, 'worker_connection_ids' => $ids, 'overlapping_lock_waits' => 2,
                        'statuses' => array_column($results, 'status'), 'purchased_rows' => 1, 'credited_count' => 3]).PHP_EOL, FILE_APPEND | LOCK_EX);
                }
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
    }

    public static function eventKinds(): array
    {
        return [[false], [true]];
    }

    private function await(callable $condition): mixed
    {
        $deadline = microtime(true) + 18;
        do {
            if ($value = $condition()) {
                return $value;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Independent workers did not overlap at the locked student row.');
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
}
