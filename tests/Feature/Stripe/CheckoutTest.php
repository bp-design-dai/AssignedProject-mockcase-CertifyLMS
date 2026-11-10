<?php

declare(strict_types=1);

namespace Tests\Feature\Stripe;

use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use App\Services\MeetingQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\Support\StripeFixtures;
use Tests\TestCase;

#[Group('external-api')]
class CheckoutTest extends TestCase
{
    use RefreshDatabase, StripeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeStripe();
    }

    public function test_only_purchasable_packs_are_shown_in_existing_order(): void
    {
        $one = MeetingPack::factory()->published()->create(['sort_order' => 1]);
        $two = MeetingPack::factory()->published()->create(['sort_order' => 2]);
        MeetingPack::factory()->draft()->create();
        MeetingPack::factory()->archived()->create();
        MeetingPack::factory()->published()->create(['price' => 0]);
        $this->actingAs(User::factory()->create())->get('/meeting-quota/checkout')->assertOk()
            ->assertViewIs('meeting-quota.checkout-select')->assertViewHas('available', true)
            ->assertViewHas('plans', fn ($p) => $p->modelKeys() === [$one->id, $two->id]);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_create_saves_pending_snapshot_ignores_client_price_and_does_not_credit(): void
    {
        $buyer = User::factory()->create(['max_meetings' => 0]);
        $pack = MeetingPack::factory()->published()->create(['name' => '購入時の名称', 'price' => 6000, 'meeting_count' => 3]);
        $other = User::factory()->create();
        $this->actingAs($buyer)->post('/meeting-quota/checkout', [
            'meeting_pack_id' => $pack->id, 'amount' => 1, 'quantity' => 999, 'user_id' => $other->id,
        ])->assertStatus(303)->assertRedirectContains('https://checkout.stripe.com/c/pay/cs_test_');
        $payment = Payment::sole();
        $this->assertSame([$buyer->id, 6000, 3, '購入時の名称', 'jpy', PaymentStatus::Pending], [$payment->user_id, $payment->amount, $payment->quantity, $payment->pack_name, $payment->currency, $payment->status]);
        $this->assertSame(0, app(MeetingQuotaService::class)->remaining($buyer));
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
        $this->assertSame(1, $this->gateway->creates);
    }

    #[DataProvider('forbiddenBuyers')]
    public function test_roles_and_statuses_are_rejected(string $role, string $status): void
    {
        $buyer = User::factory()->create(['role' => $role, 'status' => $status]);
        $pack = MeetingPack::factory()->published()->create();
        $this->actingAs($buyer)->get('/meeting-quota/checkout')->assertForbidden();
        $this->postJson('/meeting-quota/checkout', ['meeting_pack_id' => $pack->id])->assertForbidden();
        $this->get('/meeting-quota/success')->assertForbidden();
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(0, $this->gateway->creates);
    }

    public static function forbiddenBuyers(): array
    {
        return [['coach', 'in_progress'], ['admin', 'in_progress'], ['student', 'invited'], ['student', 'graduated'], ['student', 'withdrawn']];
    }

    #[DataProvider('forbiddenPacks')]
    public function test_direct_post_cannot_purchase_unpublished_packs(string $status, int $price): void
    {
        $pack = MeetingPack::factory()->create(['status' => $status, 'price' => $price]);
        $this->actingAs(User::factory()->create())->postJson('/meeting-quota/checkout', ['meeting_pack_id' => $pack->id])->assertForbidden();
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(0, $this->gateway->creates);
    }

    public static function forbiddenPacks(): array
    {
        return [['draft', 50], ['archived', 51]];
    }

    #[DataProvider('stripePrices')]
    public function test_stripe_price_boundary_in_listing_and_direct_purchase(int $price, bool $purchasable): void
    {
        $pack = MeetingPack::factory()->published()->create(['price' => $price, 'meeting_count' => 3]);
        $this->actingAs(User::factory()->create())->get('/meeting-quota/checkout')->assertOk()
            ->assertViewHas('plans', fn ($plans) => $plans->modelKeys() === ($purchasable ? [$pack->id] : []));

        $response = $this->postJson('/meeting-quota/checkout', ['meeting_pack_id' => $pack->id]);
        if ($purchasable) {
            $response->assertStatus(303)->assertRedirectContains('https://checkout.stripe.com/');
            $payment = Payment::sole();
            $this->assertSame([$price, 3, 'jpy', PaymentStatus::Pending], [$payment->amount, $payment->quantity, $payment->currency, $payment->status]);
            $this->assertSame(1, $this->gateway->creates);
        } else {
            $response->assertForbidden();
            $this->assertDatabaseCount('payments', 0);
            $this->assertSame(0, $this->gateway->creates);
        }
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public static function stripePrices(): array
    {
        return [
            '0 JPY' => [0, false],
            '1 JPY' => [1, false],
            '49 JPY' => [49, false],
            '50 JPY' => [50, true],
            '51 JPY' => [51, true],
        ];
    }

    #[DataProvider('packChanges')]
    public function test_direct_purchase_rechecks_pack_after_listing(array $changes): void
    {
        $pack = MeetingPack::factory()->published()->create(['price' => 50]);
        $this->actingAs(User::factory()->create())->get('/meeting-quota/checkout')->assertOk()
            ->assertViewHas('plans', fn ($plans) => $plans->modelKeys() === [$pack->id]);
        $pack->update($changes);

        $this->postJson('/meeting-quota/checkout', ['meeting_pack_id' => $pack->id])->assertForbidden();
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(0, $this->gateway->creates);
    }

    public static function packChanges(): array
    {
        return [
            'price falls below minimum' => [['price' => 49]],
            'pack becomes unpublished' => [['status' => 'archived']],
        ];
    }

    public function test_missing_invalid_and_unknown_pack_id_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        $this->postJson('/meeting-quota/checkout', [])->assertUnprocessable()->assertJsonValidationErrors('meeting_pack_id');
        $this->postJson('/meeting-quota/checkout', ['meeting_pack_id' => 'bad'])->assertUnprocessable();
        $this->postJson('/meeting-quota/checkout', ['meeting_pack_id' => (string) Str::ulid()])->assertNotFound();
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_guest_cannot_purchase_or_view_results(): void
    {
        $this->get('/meeting-quota/checkout')->assertRedirect('/login');
        $this->post('/meeting-quota/checkout')->assertRedirect('/login');
        $this->get('/meeting-quota/success')->assertRedirect('/login');
    }

    public function test_unconfigured_checkout_has_safe_notice_and_no_purchase(): void
    {
        $this->gateway->configured = false;
        $pack = MeetingPack::factory()->published()->create();
        $this->actingAs(User::factory()->create())->get('/meeting-quota/checkout')->assertOk()->assertSee('現在、追加面談の決済を利用できません。');
        $this->postJson('/meeting-quota/checkout', ['meeting_pack_id' => $pack->id])->assertStatus(503);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_provider_failure_saves_failed_purchase_without_credit(): void
    {
        $this->gateway->onCreate = fn () => throw new ServiceUnavailableHttpException(null, '安全なエラー');
        $pack = MeetingPack::factory()->published()->create();
        $this->actingAs(User::factory()->create())->postJson('/meeting-quota/checkout', ['meeting_pack_id' => $pack->id])->assertStatus(503);
        $this->assertSame(PaymentStatus::Failed, Payment::sole()->status);
        $this->assertSame('checkout_unavailable', Payment::sole()->failure_code);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public function test_master_changes_do_not_change_the_purchase_or_future_credit(): void
    {
        $buyer = User::factory()->create(['max_meetings' => 0]);
        $pack = MeetingPack::factory()->published()->create(['name' => '購入時名称', 'price' => 6000, 'meeting_count' => 3]);
        $this->gateway->onCreate = function (Payment $payment) use ($pack) {
            $this->assertSame(6000, $payment->amount);
            $pack->update(['name' => '変更後名称', 'price' => 9000, 'meeting_count' => 8, 'status' => 'archived']);
        };
        $this->actingAs($buyer)->post('/meeting-quota/checkout', ['meeting_pack_id' => $pack->id])->assertStatus(303);
        $payment = Payment::sole();
        $this->stripePost($this->stripePayload($payment))->assertOk();
        $this->assertSame(3, app(MeetingQuotaService::class)->remaining($buyer));
        $this->get('/meeting-quota/success?session_id='.$payment->stripe_session_id)->assertOk()->assertSee('購入時名称')->assertDontSee('変更後名称')->assertSee('6,000');
        $this->get('/meeting-quota/history')->assertOk()->assertSee('購入時名称')->assertDontSee('変更後名称');
    }

    public function test_success_get_and_reload_only_display_current_state(): void
    {
        $buyer = User::factory()->create(['max_meetings' => 0]);
        $payment = $this->pendingPayment(['user_id' => $buyer->id, 'quantity' => 2]);
        $url = '/meeting-quota/success?session_id='.$payment->stripe_session_id;
        $this->actingAs($buyer)->get('/meeting-quota/success')->assertOk()->assertSee('決済の反映を確認中');
        for ($i = 0; $i < 2; $i++) {
            $this->get($url)->assertOk()->assertSee('決済の反映を確認中')->assertDontSee('お支払いが完了しました');
        }
        $this->get('/meeting-quota/checkout?canceled=1')->assertOk()->assertSee('お支払いを中断しました');
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->stripePost($this->stripePayload($payment))->assertOk();
        $this->get($url)->assertOk()->assertSee('お支払いが完了しました');
        $this->get($url)->assertOk();
        $this->assertSame(2, app(MeetingQuotaService::class)->remaining($buyer));
        $this->assertDatabaseCount('meeting_quota_transactions', 1);
    }

    public function test_other_students_success_url_is_not_visible(): void
    {
        $payment = $this->pendingPayment();
        $this->actingAs(User::factory()->create())->get('/meeting-quota/success?session_id='.$payment->stripe_session_id)->assertNotFound();
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }
}
