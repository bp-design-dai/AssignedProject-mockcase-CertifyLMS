<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\InvitationStatus;
use App\Enums\UserRole;
use App\Models\Invitation;
use App\Models\Plan;
use App\Models\User;
use App\Services\InvitationTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InvitationReuseTest extends TestCase
{
    use RefreshDatabase;

    private function invitation(UserRole $role = UserRole::Student): Invitation
    {
        $factory = User::factory()->invited()->state(['role' => $role]);
        if ($role === UserRole::Student) {
            $factory = $factory->withPlan(Plan::factory()->published()->create());
        }

        return Invitation::factory()->forUser($factory->create())->pending()->create()->refresh();
    }

    private function postUrl(Invitation $invitation): string
    {
        return URL::temporarySignedRoute('onboarding.store', now()->addDay(), [
            'invitation' => $invitation->id,
        ]);
    }

    private function payload(): array
    {
        return [
            'name' => '招待再利用検証',
            'bio' => '初回プロフィール',
            'password' => 'test-only-confirmed-password',
            'password_confirmation' => 'test-only-confirmed-password',
            'meeting_url' => 'https://example.test/meeting',
        ];
    }

    public static function roles(): array
    {
        return [
            'student' => [UserRole::Student],
            'coach' => [UserRole::Coach],
        ];
    }

    #[DataProvider('roles')]
    public function test_completed_invitation_cannot_be_reused_or_overwrite_registration(UserRole $role): void
    {
        $invitation = $this->invitation($role);
        $showUrl = app(InvitationTokenService::class)->generateUrl($invitation);
        $postUrl = $this->postUrl($invitation);

        $this->get($showUrl)->assertOk()->assertViewIs('auth.onboarding');
        $this->post($postUrl, $this->payload())->assertRedirect(route('dashboard.index'));
        $this->assertAuthenticatedAs($invitation->user);
        $invitation->refresh();
        $this->assertSame(InvitationStatus::Accepted, $invitation->status);
        $this->assertNotNull($invitation->accepted_at);
        $this->assertEqualsWithDelta(now()->timestamp, $invitation->accepted_at->timestamp, 5);

        $invitationBefore = $invitation->getRawOriginal();
        $logsBefore = DB::table('user_status_logs')->where('user_id', $invitation->user_id)->count();
        $quotaBefore = DB::table('meeting_quota_transactions')->where('user_id', $invitation->user_id)->count();

        $this->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();
        $userBefore = $invitation->user->fresh()->getRawOriginal();
        $this->get($showUrl)->assertStatus(410)->assertViewIs('auth.invitation-invalid');
        $this->post($postUrl, array_replace($this->payload(), ['name' => '上書き不可']))->assertStatus(410);
        $this->assertGuest();
        $this->assertSame($userBefore, $invitation->user->fresh()->getRawOriginal());
        $this->assertSame($invitationBefore, $invitation->fresh()->getRawOriginal());
        $this->assertSame($logsBefore, DB::table('user_status_logs')->where('user_id', $invitation->user_id)->count());
        $this->assertSame($quotaBefore, DB::table('meeting_quota_transactions')->where('user_id', $invitation->user_id)->count());
    }

    public function test_validation_failure_keeps_invitation_unused_and_allows_a_valid_retry(): void
    {
        $invitation = $this->invitation();
        $userBefore = $invitation->user->fresh()->getRawOriginal();
        $this->postJson($this->postUrl($invitation), array_replace($this->payload(), [
            'password_confirmation' => 'does-not-match',
        ]))->assertUnprocessable()->assertJsonValidationErrors('password');

        $invitation->refresh();
        $this->assertSame(InvitationStatus::Pending, $invitation->status);
        $this->assertNull($invitation->accepted_at);
        $this->assertSame($userBefore, $invitation->user->fresh()->getRawOriginal());
        $this->assertGuest();

        $this->post($this->postUrl($invitation), $this->payload())->assertRedirect(route('dashboard.index'));
        $this->assertSame(InvitationStatus::Accepted, $invitation->fresh()->status);
    }

    public function test_accepted_invitation_is_rejected_even_when_user_is_still_invited(): void
    {
        $invitation = $this->invitation();
        $invitation->update(['status' => InvitationStatus::Accepted, 'accepted_at' => now()]);
        $userBefore = $invitation->user->fresh()->getRawOriginal();

        $this->get(app(InvitationTokenService::class)->generateUrl($invitation))
            ->assertStatus(410)->assertViewIs('auth.invitation-invalid');
        $this->post($this->postUrl($invitation), $this->payload())->assertStatus(410);
        $this->assertSame($userBefore, $invitation->user->fresh()->getRawOriginal());
        $this->assertGuest();
    }

    public static function invalidStatuses(): array
    {
        return [
            'expired' => [InvitationStatus::Expired],
            'revoked' => [InvitationStatus::Revoked],
        ];
    }

    #[DataProvider('invalidStatuses')]
    public function test_invalid_invitation_cannot_complete_onboarding(InvitationStatus $status): void
    {
        $invitation = $this->invitation();
        $invitation->update(['status' => $status]);
        $userBefore = $invitation->user->fresh()->getRawOriginal();

        $this->post($this->postUrl($invitation), $this->payload())->assertStatus(410);
        $this->assertNull($invitation->fresh()->accepted_at);
        $this->assertSame($userBefore, $invitation->user->fresh()->getRawOriginal());
        $this->assertGuest();
    }
}
