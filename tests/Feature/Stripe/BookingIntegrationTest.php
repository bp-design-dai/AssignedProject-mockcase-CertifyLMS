<?php

declare(strict_types=1);

namespace Tests\Feature\Stripe;

use App\Events\MeetingCanceled;
use App\Events\MeetingReserved;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\MeetingPack;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\MeetingQuotaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\StripeFixtures;
use Tests\TestCase;

class BookingIntegrationTest extends TestCase
{
    use RefreshDatabase, StripeFixtures;

    public function test_zero_quota_purchase_is_visible_in_dashboard_and_booking_then_cancel_refunds_only_consumption(): void
    {
        $this->fakeStripe();
        Carbon::setTestNow(Carbon::parse('2030-06-10 18:00:00', 'Asia/Tokyo'));
        Event::fake([MeetingReserved::class, MeetingCanceled::class]);
        config(['services.google_calendar.client_id' => null]);
        try {
            $buyer = User::factory()->withPlan(Plan::factory()->create())->create(['max_meetings' => 0]);
            $coach = User::factory()->coach()->create();
            $cert = Certification::factory()->published()->create();
            $cert->coaches()->attach($coach, ['assigned_by_user_id' => User::factory()->admin()->create()->id, 'assigned_at' => now()]);
            CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
            $enrollment = Enrollment::factory()->for($buyer)->for($cert)->learning()->create();
            $data = ['scheduled_at' => '2030-06-17T10:00:00', 'topic' => '追加購入後の面談'];
            $this->actingAs($buyer)->postJson(route('meetings.store', $enrollment), $data)->assertStatus(409);
            $pack = MeetingPack::factory()->published()->create(['meeting_count' => 2]);
            $this->post('/meeting-quota/checkout', ['meeting_pack_id' => $pack->id])->assertStatus(303);
            $payment = Payment::sole();
            $this->stripePost($this->stripePayload($payment))->assertOk();
            $this->get('/dashboard')->assertOk()->assertViewHas('viewModel', fn ($vm) => $vm->planInfo?->meetingsRemaining === 2);
            $this->get(route('meetings.create', $enrollment))->assertOk()->assertViewHas('meetingsRemaining', 2);
            $purchaseRow = MeetingQuotaTransaction::where('related_payment_id', $payment->id)->sole()->getRawOriginal();
            $this->post(route('meetings.store', $enrollment), $data)->assertRedirect();
            $meeting = Meeting::sole();
            $this->assertSame(1, app(MeetingQuotaService::class)->remaining($buyer));
            $this->post(route('meetings.cancel', $meeting))->assertRedirect();
            $this->assertSame(2, app(MeetingQuotaService::class)->remaining($buyer));
            $this->assertSame($purchaseRow, MeetingQuotaTransaction::where('related_payment_id', $payment->id)->sole()->getRawOriginal());
            $this->assertDatabaseCount('meeting_quota_transactions', 3);
            Event::assertDispatched(MeetingReserved::class, 1);
            Event::assertDispatched(MeetingCanceled::class, 1);
        } finally {
            Carbon::setTestNow();
        }
    }
}
