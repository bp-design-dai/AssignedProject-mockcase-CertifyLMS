<?php

declare(strict_types=1);

namespace Tests\Feature\Certificate;

use App\Enums\EnrollmentStatus;
use App\Models\Certificate;
use App\Models\Enrollment;
use App\Models\MockExam;
use App\Models\MockExamSession;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CertificateConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL independent connections are required.');
        }
        if (! app()->environment('testing') || ! preg_match('/^testing(?:_\w+)?$/', DB::connection()->getDatabaseName())) {
            throw new RuntimeException('Only an isolated testing DB may be migrated.');
        }
    }

    public function test_two_simultaneous_receipts_issue_one_certificate_and_one_pdf(): void
    {
        $enrollment = Enrollment::factory()->learning()->create();
        $exam = MockExam::factory()->for($enrollment->certification)->create(['is_published' => true]);
        MockExamSession::factory()->for($enrollment)->for($exam)->create(['pass' => true]);
        config(['filesystems.disks.private.root' => storage_path('app/private/sa04-race-'.$enrollment->id)]);
        $processes = [];
        DB::beginTransaction();
        try {
            Enrollment::lockForUpdate()->findOrFail($enrollment->id);
            $parentId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            foreach ([0, 1] as $index) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/CertificateReceiptWorker.php'), $enrollment->id], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_DATABASE' => DB::connection()->getDatabaseName(),
                    'SESSION_DRIVER' => 'array', 'CACHE_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
                ], timeout: 40);
                $process->start();
                $processes[] = $process;
            }

            $ids = [];
            foreach ($processes as $process) {
                $deadline = microtime(true) + 18;
                do {
                    $ready = $this->message($process, 'connection_id');
                    if ($ready !== null || ! $process->isRunning()) {
                        break;
                    }
                    usleep(10000);
                } while (microtime(true) < $deadline);
                $this->assertNotNull($ready, $process->getErrorOutput());
                $ids[] = $ready['connection_id'];
            }
            $this->assertCount(2, array_unique($ids));
            $this->assertNotContains($parentId, $ids);

            $deadline = microtime(true) + 18;
            do {
                $waiting = count(array_filter(DB::select('SHOW FULL PROCESSLIST'), fn ($row) => in_array((int) $row->Id, $ids, true)
                    && $row->Command !== 'Sleep' && str_contains(strtolower($row->Info ?? ''), 'for update')));
                if ($waiting === 2) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $waiting, 'Both HTTP requests must overlap on the locked enrollment.');

            DB::commit();
            $statuses = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $result = $this->message($process, 'status');
                $statuses[] = $result['status'];
                $this->assertSame(route('enrollments.show', $enrollment), $result['redirect']);
            }

            $this->assertSame([302, 302], $statuses);
            $certificate = Certificate::sole();
            $this->assertSame($enrollment->id, $certificate->enrollment_id);
            $this->assertSame(EnrollmentStatus::Passed, $enrollment->fresh()->status);
            $this->assertSame(1, $enrollment->statusLogs()->where('to_status', EnrollmentStatus::Passed)->count());
            $this->assertSame([$certificate->pdf_path], Storage::disk('private')->allFiles('certificates'));
            $this->assertStringStartsWith('%PDF-', Storage::disk('private')->get($certificate->pdf_path));

            if ($report = getenv('SA04_CONCURRENCY_REPORT')) {
                file_put_contents($report, json_encode(['independent_connections' => count($ids), 'overlapping_lock_waits' => $waiting,
                    'http_statuses' => $statuses, 'certificates' => 1, 'pdfs' => 1, 'passed_logs' => 1], JSON_THROW_ON_ERROR).PHP_EOL);
            }
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0.1);
                }
            }
            Storage::disk('private')->deleteDirectory('certificates');
        }
    }

    private function message(Process $process, string $key): ?array
    {
        foreach (explode("\n", $process->getOutput()) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && array_key_exists($key, $decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
