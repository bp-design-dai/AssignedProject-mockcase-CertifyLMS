<?php

declare(strict_types=1);

namespace Tests\Feature\Stripe;

use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use App\Models\User;
use App\Services\MeetingQuotaService;
use App\UseCases\MeetingQuota\CreateCheckoutAction;
use Database\Seeders\PaymentSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\StripeFixtures;
use Tests\TestCase;

class CommitAndMigrationTest extends TestCase
{
    use DatabaseMigrations, StripeFixtures;

    protected function beforeRefreshingDatabase(): void
    {
        if (! app()->environment('testing') || ! preg_match('/^testing(?:_\w+)?$/', DB::connection()->getDatabaseName())) {
            throw new \RuntimeException('Only an isolated testing DB may be migrated.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeStripe();
    }

    public function test_snapshot_is_committed_and_visible_to_another_connection_before_network_call(): void
    {
        $buyer = User::factory()->create();
        $pack = MeetingPack::factory()->published()->create(['price' => 6000]);
        config(['database.connections.stripe_observer' => config('database.connections.mysql')]);
        $this->gateway->onCreate = function (Payment $payment) {
            $this->assertSame(0, DB::transactionLevel());
            $observer = DB::connection('stripe_observer');
            $this->assertNotSame(DB::selectOne('SELECT CONNECTION_ID() AS id')->id, $observer->selectOne('SELECT CONNECTION_ID() AS id')->id);
            $this->assertSame(6000, (int) $observer->table('payments')->where('id', $payment->id)->value('amount'));
            $this->assertSame(0, $observer->table('meeting_quota_transactions')->count());
        };
        try {
            app(CreateCheckoutAction::class)($buyer, $pack->id);
        } finally {
            DB::disconnect('stripe_observer');
        }
    }

    public function test_fast_webhook_before_create_response_is_saved_without_duplicate_or_downgrade(): void
    {
        $buyer = User::factory()->create(['max_meetings' => 0]);
        $pack = MeetingPack::factory()->published()->create(['meeting_count' => 3]);
        $this->gateway->onCreate = function (Payment $payment) {
            $this->assertNull($payment->fresh()->stripe_session_id);
            $this->stripePost($this->stripePayload($payment))->assertOk();
        };
        app(CreateCheckoutAction::class)($buyer, $pack->id);
        $this->assertSame(PaymentStatus::Succeeded, Payment::sole()->status);
        $this->assertSame(3, app(MeetingQuotaService::class)->remaining($buyer));
        $this->assertDatabaseCount('meeting_quota_transactions', 1);
    }

    public function test_stale_authenticated_user_is_rechecked_under_lock(): void
    {
        $buyer = User::factory()->create();
        $pack = MeetingPack::factory()->published()->create();
        DB::table('users')->where('id', $buyer->id)->update(['status' => 'graduated']);
        $this->actingAs($buyer)->postJson('/meeting-quota/checkout', ['meeting_pack_id' => $pack->id])->assertForbidden();
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_migration_can_rollback_and_reapply_without_changing_unrelated_data(): void
    {
        $buyer = User::factory()->create(['max_meetings' => 7]);
        MeetingQuotaTransaction::factory()->adminGrant(User::factory()->admin()->create(), 2)->create(['user_id' => $buyer->id]);
        $migration = require database_path('migrations/2026_11_05_000001_create_payments_and_stripe_webhook_events.php');
        $before = DB::table('users')->orderBy('id')->get()->toJson();
        $ledger = DB::table('meeting_quota_transactions')->get()->toJson();
        $migration->down();
        $this->assertFalse(Schema::hasTable('payments'));
        $this->assertFalse(Schema::hasTable('stripe_webhook_events'));
        $this->gateway->configured = false;
        $this->actingAs($buyer)->get('/meeting-quota/checkout')->assertOk();
        $this->get('/meeting-quota/history')->assertOk();
        $migration->up();
        $this->assertTrue(Schema::hasTable('payments'));
        $this->assertSame($before, DB::table('users')->orderBy('id')->get()->toJson());
        $this->assertSame($ledger, DB::table('meeting_quota_transactions')->get()->toJson());
    }

    public function test_demo_seed_is_idempotent_has_multiple_states_and_credits_only_successes(): void
    {
        User::factory()->create(['email' => 'student@certify-lms.test', 'max_meetings' => 0]);
        User::factory()->count(2)->create(['max_meetings' => 0]);
        MeetingPack::factory()->published()->create(['meeting_count' => 3]);
        $this->seed(PaymentSeeder::class);
        $this->assertDatabaseCount('payments', 12);
        $this->assertDatabaseCount('meeting_quota_transactions', 3);
        foreach (User::where('role', 'student')->get() as $user) {
            $this->assertSame(3, app(MeetingQuotaService::class)->remaining($user));
        }
        $snapshot = Payment::orderBy('id')->get()->toJson();
        $this->seed(PaymentSeeder::class);
        $this->assertSame($snapshot, Payment::orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('meeting_quota_transactions', 3);
        $this->assertSame(0, $this->gateway->creates);
        $this->assertSame(0, $this->gateway->retrieves);
    }
}
