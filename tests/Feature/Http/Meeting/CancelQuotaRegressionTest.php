<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Meeting;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\MeetingReminderWindow;
use App\Enums\MeetingStatus;
use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Http\Controllers\MeetingController;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\MeetingQuotaTransaction;
use App\Models\User;
use App\Services\MeetingQuotaService;
use App\UseCases\MeetingQuota\ConsumeQuotaAction;
use App\UseCases\MeetingQuota\RefundQuotaAction;
use App\UseCases\Notification\SendMeetingRemindersAction;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CancelQuotaRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2030-06-10 18:00:00', 'Asia/Tokyo'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2030-06-10 18:00:00', 'Asia/Tokyo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @dataProvider allowedActors */
    public function test_party_cancel_appends_one_refund_for_student_and_preserves_consumption(string $role): void
    {
        $meeting = $this->consumedMeeting();
        $student = $meeting->student;
        $actor = $meeting->$role;
        $original = MeetingQuotaTransaction::query()->sole()->getAttributes();
        $this->assertSame(2, $this->remaining($student));

        $this->actingAs($actor)->post(route('meetings.cancel', $meeting))
            ->assertRedirect(route('meetings.show', $meeting))
            ->assertSessionHas('success', '面談をキャンセルしました。面談回数を返却しました。');

        $this->assertSame(MeetingStatus::Canceled, $meeting->fresh()->status);
        $this->assertSame($actor->id, $meeting->fresh()->canceled_by_user_id);
        $this->assertNotNull($meeting->fresh()->canceled_at);
        $this->assertSame(3, $this->remaining($student));
        $this->assertSame(3, $student->fresh()->max_meetings);
        $this->assertEquals($original, MeetingQuotaTransaction::findOrFail($original['id'])->getAttributes());
        $this->assertDatabaseCount('meeting_quota_transactions', 2);
        $this->assertDatabaseHas('meeting_quota_transactions', ['user_id' => $student->id, 'related_meeting_id' => $meeting->id, 'type' => 'refunded', 'amount' => 1]);
        $this->assertSame(0, MeetingQuotaTransaction::where('user_id', $meeting->coach_id)->count());
        $this->assertDatabaseCount('notifications', 2);
        $this->assertCount(2, Mail::mailer()->getSymfonyTransport()->messages());

        $this->post(route('meetings.cancel', $meeting))->assertForbidden();
        $this->assertSame(3, $this->remaining($student));
        $this->assertDatabaseCount('meeting_quota_transactions', 2);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertCount(2, Mail::mailer()->getSymfonyTransport()->messages());
        $this->actingAs($student)->get(route('meetings.index'))->assertOk()->assertViewHas('meetingsRemaining', 3);
    }

    public static function allowedActors(): array
    {
        return [['student'], ['coach']];
    }

    /** @dataProvider rejectedMeetings */
    public function test_ineligible_meeting_does_not_change_balance_or_history(string $status, int $offset, int $httpStatus): void
    {
        $meeting = $this->consumedMeeting(['status' => $status, 'scheduled_at' => now()->addSeconds($offset)]);
        $attributes = $meeting->getAttributes();
        $this->actingAs($meeting->student)->postJson(route('meetings.cancel', $meeting))->assertStatus($httpStatus);
        $this->assertEquals($attributes, $meeting->fresh()->getAttributes());
        $this->assertSame(2, $this->remaining($meeting->student));
        $this->assertDatabaseCount('meeting_quota_transactions', 1);
        $this->assertDatabaseCount('notifications', 0);
    }

    public static function rejectedMeetings(): array
    {
        return [['reserved', 0, 409], ['reserved', -1, 409], ['canceled', 3600, 403], ['completed', 3600, 403]];
    }

    /** @dataProvider forbiddenActors */
    public function test_other_students_coaches_and_admin_cannot_cancel_or_receive_refund(string $role): void
    {
        $meeting = $this->consumedMeeting();
        $other = User::factory()->create(['role' => $role]);
        $this->actingAs($other)->post(route('meetings.cancel', $meeting))->assertForbidden();
        $this->assertSame(MeetingStatus::Reserved, $meeting->fresh()->status);
        $this->assertSame(2, $this->remaining($meeting->student));
        $this->assertDatabaseCount('meeting_quota_transactions', 1);
        $this->assertDatabaseCount('notifications', 0);
    }

    public static function forbiddenActors(): array
    {
        return [['student'], ['coach'], ['admin']];
    }

    public function test_guest_cannot_cancel(): void
    {
        $meeting = $this->consumedMeeting();
        $this->post(route('meetings.cancel', $meeting))->assertRedirect(route('login'));
        $this->assertSame(MeetingStatus::Reserved, $meeting->fresh()->status);
        $this->assertSame(2, $this->remaining($meeting->student));
        $this->assertDatabaseCount('meeting_quota_transactions', 1);
    }

    public function test_refund_insert_failure_rolls_back_cancel_and_insert_and_does_not_notify(): void
    {
        $meeting = $this->consumedMeeting();
        $original = $meeting->getAttributes();
        MeetingQuotaTransaction::created(function ($transaction) {
            if ($transaction->type === MeetingQuotaTransactionType::Refunded) {
                throw new \RuntimeException('返却INSERT後の検証用障害');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($meeting->student)->post(route('meetings.cancel', $meeting));
            $this->fail('返却処理の障害が伝播しませんでした。');
        } catch (\RuntimeException $exception) {
            $this->assertSame('返却INSERT後の検証用障害', $exception->getMessage());
        } finally {
            MeetingQuotaTransaction::flushEventListeners();
        }
        $this->assertEquals($original, $meeting->fresh()->getAttributes());
        $this->assertSame(2, $this->remaining($meeting->student));
        $this->assertDatabaseCount('meeting_quota_transactions', 1);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertCount(0, Mail::mailer()->getSymfonyTransport()->messages());
    }

    public function test_stale_reserved_instance_is_rechecked_under_lock_and_cannot_double_refund(): void
    {
        $meeting = $this->consumedMeeting();
        $stale = $meeting->fresh();
        $this->actingAs($meeting->student)->post(route('meetings.cancel', $meeting))->assertRedirect();
        try {
            app(MeetingController::class)->cancel($stale, app(RefundQuotaAction::class));
            $this->fail('ロック後の状態確認で二重キャンセルを拒否しませんでした。');
        } catch (MeetingStatusTransitionException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame(3, $this->remaining($meeting->student));
        $this->assertSame(1, MeetingQuotaTransaction::where('related_meeting_id', $meeting->id)->where('type', 'refunded')->count());
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_canceled_meeting_is_not_selected_by_either_reminder_command(): void
    {
        $meeting = $this->consumedMeeting(['scheduled_at' => '2030-06-11 10:00:00']);
        $this->actingAs($meeting->student)->post(route('meetings.cancel', $meeting))->assertRedirect();
        $this->assertSame(['meetings' => 0, 'sent' => 0, 'failed' => 0], app(SendMeetingRemindersAction::class)(MeetingReminderWindow::Eve));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2030-06-11 09:00:00', 'Asia/Tokyo'));
        $this->assertSame(['meetings' => 0, 'sent' => 0, 'failed' => 0], app(SendMeetingRemindersAction::class)(MeetingReminderWindow::OneHourBefore));
        $this->assertDatabaseCount('meeting_reminder_deliveries', 0);
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_booking_then_cancellation_restores_balance_without_rewriting_consumption(): void
    {
        $student = User::factory()->student()->create(['max_meetings' => 3]);
        $coach = User::factory()->coach()->create();
        $certification = Certification::factory()->published()->create();
        $certification->coaches()->attach($coach, ['assigned_by_user_id' => User::factory()->admin()->create()->id, 'assigned_at' => now()]);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        $this->actingAs($student)->post(route('meetings.store', $enrollment), ['scheduled_at' => '2030-06-17T10:00:00', 'topic' => '回数返却の往復検証'])->assertSessionHasNoErrors()->assertRedirect();
        $meeting = Meeting::query()->sole();
        $this->assertSame(2, $this->remaining($student));
        $original = MeetingQuotaTransaction::query()->sole()->getAttributes();
        $this->post(route('meetings.cancel', $meeting))->assertRedirect();
        $this->assertSame(3, $this->remaining($student));
        $this->assertEquals($original, MeetingQuotaTransaction::findOrFail($original['id'])->getAttributes());
        $this->assertDatabaseCount('meeting_quota_transactions', 2);
    }

    private function consumedMeeting(array $attributes = []): Meeting
    {
        $student = User::factory()->student()->create(['max_meetings' => 3]);
        $meeting = Meeting::factory()->forStudent($student)->create(array_merge(['scheduled_at' => now()->addDay()], $attributes));
        $transaction = app(ConsumeQuotaAction::class)($student, $meeting->id);
        $meeting->update(['meeting_quota_transaction_id' => $transaction->id]);

        return $meeting->fresh();
    }

    private function remaining(User $user): int
    {
        return app(MeetingQuotaService::class)->remaining($user);
    }
}
