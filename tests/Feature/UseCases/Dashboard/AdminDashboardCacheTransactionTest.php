<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Dashboard;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\EnrollmentStatusChangeService;
use App\UseCases\Dashboard\FetchAdminDashboardAction;
use App\UseCases\Enrollment\FailAction;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminDashboardCacheTransactionTest extends TestCase
{
    use DatabaseMigrations;

    /** @dataProvider outcomes */
    public function test_independent_connection_invalidates_only_after_its_own_commit(bool $commit): void
    {
        $this->assertSame('testing', DB::connection()->getDatabaseName());
        config(['database.connections.cache_other' => config('database.connections.'.config('database.default'))]);
        $admin = User::factory()->admin()->create();
        $target = Enrollment::factory()->learning()->create();
        Cache::flush();
        $this->fetch($admin);
        $other = DB::connection('cache_other');
        $this->assertNotSame(DB::connection()->getPdo(), $other->getPdo());
        $other->beginTransaction();
        try {
            $updated = Enrollment::on('cache_other')->findOrFail($target->id);
            $updated->update(['status' => EnrollmentStatus::Failed]);
            app(EnrollmentStatusChangeService::class)->recordStatusChange($updated, EnrollmentStatus::Learning, EnrollmentStatus::Failed, $admin);
            $this->assertTrue(Cache::has(config('dashboard.admin_kpi_cache_key')));
            $this->assertTrue(Cache::has(config('dashboard.admin_completion_rate_cache_key')));
            [$during, $sql] = $this->fetch($admin);
            $this->assertSame(0, $sql);
            $this->assertSame(1, $during->kpi['learning_count']);
            $commit ? $other->commit() : $other->rollBack();
            [$after, $sql] = $this->fetch($admin);
            $this->assertSame($commit ? 3 : 0, $sql);
            $this->assertSame($commit ? 0 : 1, $after->kpi['learning_count']);
            $this->assertSame($commit ? 1 : 0, $after->kpi['failed_count']);
            $this->assertSame($commit ? EnrollmentStatus::Failed : EnrollmentStatus::Learning, $target->fresh()->status);
        } finally {
            if ($other->transactionLevel() > 0) {
                $other->rollBack();
            }
            DB::purge('cache_other');
        }
    }

    public static function outcomes(): array
    {
        return [[true], [false]];
    }

    public function test_late_pre_commit_cache_write_cannot_hide_a_committed_status_change(): void
    {
        $admin = User::factory()->admin()->create();
        $target = Enrollment::factory()->learning()->create();
        $store = new class extends ArrayStore
        {
            public ?\Closure $beforeKpiWrite = null;

            public function put($key, $value, $seconds)
            {
                if ($key === config('dashboard.admin_kpi_cache_key') && $this->beforeKpiWrite !== null) {
                    $callback = $this->beforeKpiWrite;
                    $this->beforeKpiWrite = null;
                    $callback();
                }

                return parent::put($key, $value, $seconds);
            }
        };
        Cache::swap(new Repository($store));
        // Reproduce invalidation after the last version check but just before an old result is stored.
        $store->beforeKpiWrite = fn () => app(FailAction::class)($target, $admin, 'concurrent commit');
        [$inFlight] = $this->fetch($admin);
        $this->assertSame(1, $inFlight->kpi['learning_count']);
        [$fresh, $sql] = $this->fetch($admin);
        $this->assertSame(2, $sql);
        $this->assertSame(0, $fresh->kpi['learning_count']);
        $this->assertSame(1, $fresh->kpi['failed_count']);
        [$cached, $sql] = $this->fetch($admin);
        $this->assertSame(0, $sql);
        $this->assertEquals($fresh, $cached);
    }

    private function fetch(User $admin): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $vm = app(FetchAdminDashboardAction::class)($admin);
            $sql = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        return [$vm, $sql];
    }
}
