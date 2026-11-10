<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Dashboard;

use App\Enums\CertificationStatus;
use App\Enums\EnrollmentStatus;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\MockExam;
use App\Models\MockExamSession;
use App\Models\User;
use App\Services\EnrollmentStatsService;
use App\UseCases\Dashboard\FetchAdminDashboardAction;
use App\UseCases\Enrollment\DestroyAction;
use App\UseCases\Enrollment\FailAction;
use App\UseCases\Enrollment\ReceiveCertificateAction;
use App\UseCases\Enrollment\ResumeAction;
use App\UseCases\Enrollment\StoreAction;
use App\UseCases\User\WithdrawAction;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminDashboardCacheRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_first_fetch_saves_both_results_and_later_admin_reuses_them_without_sql(): void
    {
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        Enrollment::factory()->for($cert)->learning()->count(2)->create();
        Enrollment::factory()->for($cert)->passed()->create();
        Enrollment::factory()->for($cert)->failed()->create();
        Enrollment::factory()->for($cert)->passed()->create(['deleted_at' => now()]);
        [$first, $sql] = $this->fetch($admin);
        $this->assertSame(3, $sql);
        $this->assertCounts($first, [2, 1, 1]);
        $this->assertSame(0.25, $first->completionRateByCertification->sole()['completion_rate']);
        $this->assertCached();
        [$second, $sql] = $this->fetch($otherAdmin);
        $this->assertSame(0, $sql);
        $this->assertEquals($first, $second);
    }

    /** @dataProvider ttlBoundaries */
    public function test_ttl_boundaries_control_reaggregation(int $ttl, int $age, bool $expired): void
    {
        config(['dashboard.admin_cache_ttl' => $ttl]);
        $start = CarbonImmutable::parse('2030-06-10 12:00:00', 'Asia/Tokyo');
        $this->clock($start);
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->create();
        Enrollment::factory()->for($cert)->learning()->create();
        $this->fetch($admin);
        // Direct fixture writes intentionally bypass the domain invalidation paths.
        Enrollment::factory()->for($cert)->passed()->create();
        $this->clock($start->addSeconds($age));
        [$vm, $sql] = $this->fetch($admin);
        $this->assertSame($expired ? 3 : 0, $sql);
        $this->assertCounts($vm, [1, $expired ? 1 : 0, 0]);
        $this->assertSame($expired ? 0.5 : 0.0, $vm->completionRateByCertification->sole()['completion_rate']);
    }

    public static function ttlBoundaries(): array
    {
        return [[300, 299, false], [300, 300, true], [300, 301, true], [60, 59, false], [60, 60, true]];
    }

    public function test_missing_new_settings_use_safe_defaults_without_breaking_the_dashboard(): void
    {
        config(['dashboard' => []]);
        $admin = User::factory()->admin()->create();
        Enrollment::factory()->learning()->create();
        [$first, $sql] = $this->fetch($admin);
        $this->assertSame(3, $sql);
        [$second, $sql] = $this->fetch($admin);
        $this->assertSame(0, $sql);
        $this->assertEquals($first, $second);
        $this->assertTrue(Cache::has('dashboard:admin:kpi:v1'));
        $this->assertTrue(Cache::has('dashboard:admin:completion-rate:v1'));
    }

    public function test_ttl_configuration_reads_the_environment(): void
    {
        $this->assertSame(300, config('dashboard.admin_cache_ttl'));
        $env = Env::getRepository();
        $previous = $env->get('DASHBOARD_ADMIN_CACHE_TTL');
        try {
            $env->set('DASHBOARD_ADMIN_CACHE_TTL', '47');
            $values = require config_path('dashboard.php');
            $this->assertSame(47, $values['admin_cache_ttl']);
        } finally {
            if ($previous === null) {
                $env->clear('DASHBOARD_ADMIN_CACHE_TTL');
            } else {
                $env->set('DASHBOARD_ADMIN_CACHE_TTL', $previous);
            }
        }
    }

    public function test_calculation_rounding_and_existing_sorting_are_preserved(): void
    {
        $admin = User::factory()->admin()->create();
        $large = Certification::factory()->draft()->create(['name' => '未公開も既存集計の対象']);
        $small = Certification::factory()->published()->create(['name' => '小さい資格']);
        Certification::factory()->published()->create(['name' => '受講登録なし']);
        Enrollment::factory()->for($large)->passed()->count(3)->create();
        Enrollment::factory()->for($large)->learning()->count(4)->create();
        Enrollment::factory()->for($small)->passed()->create();
        Enrollment::factory()->for($small)->failed()->count(2)->create();
        [$first] = $this->fetch($admin);
        [$second, $sql] = $this->fetch($admin);
        $this->assertSame(0, $sql);
        $this->assertEquals($first, $second);
        $this->assertCounts($second, [4, 4, 2]);
        $this->assertSame([$large->id, $small->id], $second->completionRateByCertification->pluck('certification_id')->all());
        $this->assertSame([0.4286, 0.3333], $second->completionRateByCertification->pluck('completion_rate')->all());
        $this->assertSame([7, 3], $second->byCertificationTop10->pluck('total')->all());
    }

    public function test_publication_and_name_changes_wait_for_ttl_without_immediate_invalidation(): void
    {
        $start = CarbonImmutable::parse('2030-06-10 12:00:00', 'Asia/Tokyo');
        $this->clock($start);
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create(['name' => '変更前']);
        Enrollment::factory()->for($cert)->learning()->create();
        $this->fetch($admin);
        $cert->update(['status' => CertificationStatus::Draft, 'name' => '変更後']);
        [$old, $sql] = $this->fetch($admin);
        $this->assertSame(0, $sql);
        $this->assertSame('変更前', $old->completionRateByCertification->sole()['certification_name']);
        $this->assertCached();
        $this->clock($start->addSeconds(300));
        [$fresh, $sql] = $this->fetch($admin);
        $this->assertSame(3, $sql);
        $this->assertSame('変更後', $fresh->completionRateByCertification->sole()['certification_name']);
        $this->assertCounts($fresh, [1, 0, 0]);
    }

    /** @dataProvider mutationCases */
    public function test_domain_mutations_invalidate_both_results_and_return_current_counts(string $mutation, array $expected): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->inProgress()->create();
        $cert = Certification::factory()->published()->create();
        $target = $mutation === 'register' ? null : Enrollment::factory()->for($student)->for($cert)->create([
            'status' => $mutation === 'resume' ? EnrollmentStatus::Failed : EnrollmentStatus::Learning,
            'exam_date' => $mutation === 'batch' ? now()->subDay()->toDateString() : null,
        ]);
        if ($mutation === 'pass') {
            Storage::fake('private');
            $exam = MockExam::factory()->published()->forCertification($cert)->create();
            MockExamSession::factory()->for($target)->for($exam)->create(['pass' => true]);
        }
        $this->fetch($admin);
        $this->assertCached();
        match ($mutation) {
            'register' => app(StoreAction::class)($student, ['certification_id' => $cert->id]),
            'pass' => app(ReceiveCertificateAction::class)($target),
            'fail' => app(FailAction::class)($target, $admin, '検証'),
            'resume' => app(ResumeAction::class)($target, $student),
            'remove' => app(DestroyAction::class)($target),
            'withdraw' => app(WithdrawAction::class)($student, $admin),
            'batch' => Artisan::call('enrollments:fail-expired'),
        };
        $this->assertNotCached();
        [$fresh, $sql] = $this->fetch($admin);
        $this->assertSame(3, $sql);
        $this->assertCounts($fresh, $expected);
        [$cached, $sql] = $this->fetch($admin);
        $this->assertSame(0, $sql);
        $this->assertEquals($fresh, $cached);
    }

    public static function mutationCases(): array
    {
        return [['register', [1, 0, 0]], ['pass', [0, 1, 0]], ['fail', [0, 0, 1]], ['resume', [1, 0, 0]], ['remove', [0, 0, 0]], ['withdraw', [1, 0, 0]], ['batch', [0, 0, 1]]];
    }

    /** @dataProvider transactionOutcomes */
    public function test_outer_transaction_controls_invalidation_after_nested_action(bool $commit): void
    {
        $admin = User::factory()->admin()->create();
        $target = Enrollment::factory()->learning()->create();
        [$before] = $this->fetch($admin);
        DB::beginTransaction();
        try {
            app(FailAction::class)($target, $admin, 'nested');
            $this->assertCached();
            [$during, $sql] = $this->fetch($admin);
            $this->assertSame(0, $sql);
            $this->assertEquals($before, $during);
        } catch (\Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }
        $commit ? DB::commit() : DB::rollBack();
        [$after, $sql] = $this->fetch($admin);
        $this->assertSame($commit ? 3 : 0, $sql);
        $this->assertCounts($after, $commit ? [0, 0, 1] : [1, 0, 0]);
        $this->assertSame($commit ? EnrollmentStatus::Failed : EnrollmentStatus::Learning, $target->fresh()->status);
    }

    public static function transactionOutcomes(): array
    {
        return [[true], [false]];
    }

    public function test_failure_after_status_record_rolls_back_without_invalidating_cache(): void
    {
        $admin = User::factory()->admin()->create();
        $target = Enrollment::factory()->learning()->create();
        $target->user->update(['default_enrollment_id' => $target->id]);
        [$before] = $this->fetch($admin);
        $throw = true;
        DB::listen(function ($query) use (&$throw): void {
            if ($throw && str_starts_with($query->sql, 'update `users`') && str_contains($query->sql, 'default_enrollment_id')) {
                $throw = false;
                throw new \RuntimeException('Synthetic failure');
            }
        });
        try {
            app(FailAction::class)($target, $admin, null);
            $this->fail('Expected action failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic failure', $exception->getMessage());
        }
        $this->assertFalse($throw, 'The failure must occur after recording the status change.');
        $this->assertSame(EnrollmentStatus::Learning, $target->fresh()->status);
        $this->assertCached();
        [$after, $sql] = $this->fetch($admin);
        $this->assertSame(0, $sql);
        $this->assertEquals($before, $after);
    }

    /** @dataProvider cacheFailures */
    public function test_cache_failure_falls_back_once_and_admin_http_still_renders(string $operation, bool $returnsFalse): void
    {
        $admin = User::factory()->admin()->create();
        Enrollment::factory()->learning()->create();
        $store = $this->faultStore();
        $store->fault = $operation;
        $store->returnsFalse = $returnsFalse;
        Cache::swap(new Repository($store));
        [$vm, $sql] = $this->fetch($admin);
        $this->assertSame(3, $sql);
        $this->assertCounts($vm, [1, 0, 0]);
        $this->actingAs($admin)->get(route('dashboard.index'))->assertOk()->assertDontSee('synthetic-private-value');
    }

    public static function cacheFailures(): array
    {
        return [['get', false], ['put', false], ['put', true]];
    }

    public function test_logging_failure_does_not_break_cache_fallback(): void
    {
        $admin = User::factory()->admin()->create();
        Enrollment::factory()->learning()->create();
        $store = $this->faultStore();
        $store->fault = 'get';
        Cache::swap(new Repository($store));
        Log::shouldReceive('warning')->once()->andThrow(new \RuntimeException('Logger unavailable'));
        [$vm, $sql] = $this->fetch($admin);
        $this->assertSame(3, $sql);
        $this->assertCounts($vm, [1, 0, 0]);
    }

    public function test_generation_rejects_old_payload_when_forget_fails(): void
    {
        $store = $this->faultStore();
        Cache::swap(new Repository($store));
        $admin = User::factory()->admin()->create();
        $target = Enrollment::factory()->learning()->create();
        $this->fetch($admin);
        $store->fault = 'forget';
        app(FailAction::class)($target, $admin, null);
        $this->app->forgetInstance(EnrollmentStatsService::class);
        [$fresh, $sql] = $this->fetch($admin);
        $this->assertSame(3, $sql);
        $this->assertCounts($fresh, [0, 0, 1]);
    }

    private function faultStore(): ArrayStore
    {
        return new class extends ArrayStore
        {
            public string $fault = '';

            public bool $returnsFalse = false;

            public function get($key)
            {
                if ($this->fault === 'get') {
                    throw new \RuntimeException('synthetic-private-value');
                }

                return parent::get($key);
            }

            public function put($key, $value, $seconds)
            {
                if ($this->fault === 'put') {
                    if ($this->returnsFalse) {
                        return false;
                    }
                    throw new \RuntimeException('synthetic-private-value');
                }

                return parent::put($key, $value, $seconds);
            }

            public function forget($key)
            {
                if ($this->fault === 'forget') {
                    throw new \RuntimeException('synthetic-private-value');
                }

                return parent::forget($key);
            }
        };
    }

    private function fetch(User $admin): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $vm = app(FetchAdminDashboardAction::class)($admin);
            $queries = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        return [$vm, $queries];
    }

    private function assertCounts($vm, array $counts): void
    {
        $this->assertSame($counts, [$vm->kpi['learning_count'], $vm->kpi['passed_count'], $vm->kpi['failed_count']]);
        $this->assertSame(array_sum($counts), $vm->kpi['total']);
    }

    private function assertCached(): void
    {
        $this->assertTrue(Cache::has(config('dashboard.admin_kpi_cache_key')));
        $this->assertTrue(Cache::has(config('dashboard.admin_completion_rate_cache_key')));
    }

    private function assertNotCached(): void
    {
        $this->assertFalse(Cache::has(config('dashboard.admin_kpi_cache_key')));
        $this->assertFalse(Cache::has(config('dashboard.admin_completion_rate_cache_key')));
    }

    private function clock(CarbonImmutable $time): void
    {
        Carbon::setTestNow($time);
        CarbonImmutable::setTestNow($time);
    }
}
