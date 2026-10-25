<?php

declare(strict_types=1);

namespace Tests\Feature\Http\SettingsProfile;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordTest extends TestCase
{
    use RefreshDatabase;

    public static function roles(): array
    {
        return ProfileTest::roles();
    }

    /** @dataProvider roles */
    public function test_current_password_is_required_and_new_password_can_be_used_to_login(UserRole $role, UserStatus $status): void
    {
        $user = User::factory()->create(['role' => $role, 'status' => $status]);
        $other = User::factory()->create();
        $otherHash = $other->password;
        $this->actingAs($user)->put(route('settings.password.update'), [
            'current_password' => 'password', 'password' => 'new-pass', 'password_confirmation' => 'new-pass',
            'user_id' => $other->id, 'role' => 'admin', 'email' => 'forged@example.test',
        ])->assertRedirect(route('settings.profile.edit', ['tab' => 'password']))
            ->assertSessionHas('success', 'パスワードを変更しました。');
        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('new-pass', $fresh->password));
        $this->assertFalse(Hash::check('password', $fresh->password));
        $this->assertSame($user->role, $fresh->role);
        $this->assertSame($user->status, $fresh->status);
        $this->assertSame($user->email, $fresh->email);
        $this->assertTrue(hash_equals($otherHash, $other->fresh()->password));
        $this->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => 'new-pass'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public static function invalidPasswords(): array
    {
        return [
            'wrong current' => [['current_password' => 'wrong'], 'current_password'],
            'missing current' => [['current_password' => null], 'current_password'],
            'array current' => [['current_password' => ['invalid']], 'current_password'],
            'missing new' => [['password' => null], 'password'],
            'short' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
            'array password' => [['password' => ['invalid']], 'password'],
            'mismatch' => [['password_confirmation' => 'different'], 'password'],
            'missing confirmation' => [['password_confirmation' => null], 'password'],
        ];
    }

    /** @dataProvider invalidPasswords */
    public function test_invalid_password_change_is_rejected_and_hash_unchanged(array $input, string $field): void
    {
        $user = User::factory()->create();
        $originalHash = $user->password;
        $this->actingAs($user)->putJson(route('settings.password.update'), array_replace([
            'current_password' => 'password', 'password' => 'new-password', 'password_confirmation' => 'new-password',
        ], $input))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertTrue(hash_equals($originalHash, $user->fresh()->password));
    }

    public function test_html_errors_use_password_tab_and_never_flash_password_fields(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->put(route('settings.password.update'), [
            'current_password' => 'wrong', 'password' => 'new-password', 'password_confirmation' => 'different',
        ])->assertRedirect(route('settings.profile.edit', ['tab' => 'password']))
            ->assertSessionHasErrors(['current_password', 'password'], null, 'updatePassword');
        foreach (['current_password', 'password', 'password_confirmation'] as $field) {
            $this->assertArrayNotHasKey($field, session()->getOldInput());
        }
        $this->get(route('settings.profile.edit', ['tab' => 'password']))->assertOk()->assertSee('パスワード');
    }
}
