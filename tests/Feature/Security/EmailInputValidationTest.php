<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailInputValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
    }

    public static function addressesWithLineBreaks(): array
    {
        $cases = [];
        foreach (['CR' => "\r", 'LF' => "\n", 'CRLF' => "\r\n"] as $name => $break) {
            $cases[$name.' quoted'] = ['"local'.$break.'part"@example.test'];
            $cases[$name.' leading'] = [$break.'student@example.test'];
            $cases[$name.' trailing'] = ['student@example.test'.$break];
        }

        return $cases;
    }

    #[DataProvider('addressesWithLineBreaks')]
    public function test_invitation_form_request_rejects_invalid_email_without_persisting_or_queueing(string $email): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->postJson('/admin/invitations', ['email' => $email, 'role' => 'coach'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('invitations', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    #[DataProvider('addressesWithLineBreaks')]
    public function test_reset_link_form_request_rejects_invalid_email_before_user_lookup_and_queueing(string $email): void
    {
        // A legacy malformed row must not make this public endpoint queue mail.
        User::factory()->create(['email' => $email]);
        $this->postJson('/forgot-password', ['email' => $email])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    #[DataProvider('addressesWithLineBreaks')]
    public function test_profile_action_rejects_invalid_email_without_changing_the_account(string $email): void
    {
        $user = User::factory()->create(['email' => 'original@example.test']);
        $this->actingAs($user)->putJson('/user/profile-information', ['name' => 'Updated name', 'email' => $email])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertSame('original@example.test', $user->fresh()->email);
        $this->assertDatabaseCount('jobs', 0);
    }

    #[DataProvider('addressesWithLineBreaks')]
    public function test_password_reset_request_validation_rejects_invalid_email_before_resetting(string $email): void
    {
        $user = User::factory()->create(['email' => $email, 'password' => Hash::make('old-password')]);
        $token = Password::createToken($user);
        $this->postJson('/reset-password', ['email' => $email, 'token' => $token, 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
        $this->assertDatabaseCount('password_reset_tokens', 1);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_browser_validation_keeps_redirect_and_existing_error_bags(): void
    {
        $bad = '"local'."\r\n".'part"@example.test';
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->from('/admin/users')->post('/admin/invitations', ['email' => $bad, 'role' => 'coach'])
            ->assertRedirect('/admin/users')->assertSessionHasErrors('email');
        $this->from('/settings/profile')->put('/user/profile-information', ['name' => 'Audit', 'email' => $bad])
            ->assertRedirect('/settings/profile')->assertSessionHasErrors('email', null, 'updateProfileInformation');
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_spaces_normal_addresses_uniqueness_and_multiline_non_email_text_are_unchanged(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->postJson('/admin/invitations', ['email' => '  coach+tag@example.test  ', 'role' => 'coach'])
            ->assertRedirect();
        $this->assertDatabaseHas('invitations', ['email' => 'coach+tag@example.test']);
        $this->assertDatabaseCount('jobs', 1);

        $this->putJson('/user/profile-information', ['name' => "First\r\nSecond", 'email' => '  admin+tag@example.test  '])->assertSuccessful();
        $this->assertSame('admin+tag@example.test', $admin->fresh()->email);
        $this->assertSame("First\r\nSecond", $admin->fresh()->name);

        User::factory()->create(['email' => 'taken@example.test']);
        $this->putJson('/user/profile-information', ['name' => 'Audit', 'email' => 'taken@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }
}
