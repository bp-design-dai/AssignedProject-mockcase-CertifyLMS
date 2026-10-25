<?php

declare(strict_types=1);

namespace Tests\Feature\Http\SettingsProfile;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public static function roles(): array
    {
        return [
            'student' => [UserRole::Student, UserStatus::InProgress],
            'coach' => [UserRole::Coach, UserStatus::InProgress],
            'admin' => [UserRole::Admin, UserStatus::InProgress],
            'graduated' => [UserRole::Student, UserStatus::Graduated],
        ];
    }

    /** @dataProvider roles */
    public function test_own_profile_is_available_and_updatable_for_each_role(UserRole $role, UserStatus $status): void
    {
        $user = User::factory()->create(['role' => $role, 'status' => $status]);
        $this->actingAs($user)->get(route('settings.profile.edit'))
            ->assertOk()->assertViewIs('settings.profile')->assertSee($user->email)
            ->assertSee($role->label())->assertSee($status->label());

        $response = $this->get(route('settings.profile.edit'));
        if ($role === UserRole::Coach) {
            $response->assertSee('name="meeting_url"', false);
        } else {
            $response->assertDontSee('name="meeting_url"', false);
        }

        $this->patch(route('settings.profile.update'), ['name' => '設定確認', 'bio' => '自己紹介を更新'])
            ->assertRedirect(route('settings.profile.edit'))
            ->assertSessionHas('success', 'プロフィールを更新しました。');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => '設定確認', 'bio' => '自己紹介を更新']);
        $this->actingAs($user->fresh())->get(route('settings.profile.edit'))->assertOk()->assertSee('自己紹介を更新');
    }

    public function test_length_boundaries_and_empty_bio_are_accepted(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->patch(route('settings.profile.update'), ['name' => str_repeat('名', 50), 'bio' => str_repeat('文', 1000)])
            ->assertSessionHasNoErrors();
        $this->assertSame(50, mb_strlen($user->fresh()->name));
        $this->assertSame(1000, mb_strlen($user->fresh()->bio));
        $this->patch(route('settings.profile.update'), ['name' => '氏名', 'bio' => ''])->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->bio);
    }

    public static function invalidProfiles(): array
    {
        return [
            'missing name' => [['name' => null], 'name'],
            'blank name' => [['name' => '   '], 'name'],
            'long name' => [['name' => str_repeat('a', 51)], 'name'],
            'array name' => [['name' => ['invalid']], 'name'],
            'long bio' => [['bio' => str_repeat('文', 1001)], 'bio'],
            'array bio' => [['bio' => ['invalid']], 'bio'],
        ];
    }

    /** @dataProvider invalidProfiles */
    public function test_invalid_profile_is_rejected_without_saving(array $input, string $field): void
    {
        $user = User::factory()->create(['name' => '元の名前', 'bio' => '元の紹介']);
        $this->actingAs($user)->patchJson(route('settings.profile.update'), array_replace(['name' => '変更', 'bio' => '変更'], $input))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => '元の名前', 'bio' => '元の紹介']);
    }

    public function test_html_validation_returns_to_profile_and_displays_errors(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->patch(route('settings.profile.update'), ['name' => ''])
            ->assertRedirect(route('settings.profile.edit'))->assertSessionHasErrors('name');
        $this->get(route('settings.profile.edit'))->assertOk()->assertSee('氏名');
    }

    public function test_coach_can_save_and_clear_meeting_url(): void
    {
        $user = User::factory()->coach()->create();
        $url = 'https://example.test/'.str_repeat('a', 479);
        $this->assertSame(500, strlen($url));
        $this->actingAs($user)->patch(route('settings.profile.update'), ['name' => 'コーチ', 'meeting_url' => $url])
            ->assertSessionHasNoErrors();
        $this->assertSame($url, $user->fresh()->meeting_url);
        $this->patch(route('settings.profile.update'), ['name' => 'コーチ', 'meeting_url' => ''])->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->meeting_url);
    }

    public static function invalidMeetingUrls(): array
    {
        return ['not URL' => ['not-a-url'], 'too long' => ['https://example.test/'.str_repeat('a', 482)], 'array' => [['invalid']]];
    }

    /** @dataProvider invalidMeetingUrls */
    public function test_invalid_coach_meeting_url_is_rejected(mixed $url): void
    {
        $user = User::factory()->coach()->create(['meeting_url' => 'https://example.test/original']);
        $this->actingAs($user)->patchJson(route('settings.profile.update'), ['name' => 'コーチ', 'meeting_url' => $url])
            ->assertUnprocessable()->assertJsonValidationErrors('meeting_url');
        $this->assertSame('https://example.test/original', $user->fresh()->meeting_url);
    }

    public static function nonCoachRoles(): array
    {
        return array_diff_key(self::roles(), ['coach' => true]);
    }

    /** @dataProvider nonCoachRoles */
    public function test_only_coach_can_submit_meeting_url(UserRole $role, UserStatus $status): void
    {
        $user = User::factory()->create(['role' => $role, 'status' => $status]);
        $this->actingAs($user)->patchJson(route('settings.profile.update'), ['name' => '変更', 'meeting_url' => 'https://example.test/forged'])
            ->assertUnprocessable()->assertJsonValidationErrors('meeting_url');
        $this->assertNull($user->fresh()->meeting_url);
    }

    public function test_forged_identity_and_privileged_fields_cannot_change_other_users_or_account_state(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create(['name' => '他の本人']);
        $originalHash = $user->password;
        $this->actingAs($user)->patch(route('settings.profile.update', ['user_id' => $other->id]), [
            'id' => $other->id, 'user_id' => $other->id, 'name' => '自分のみ更新',
            'email' => 'forged@example.test', 'role' => 'admin', 'status' => 'graduated',
            'password' => 'forged-password', 'avatar_url' => 'https://example.test/forged.png', 'plan_id' => 1,
        ])->assertSessionHasNoErrors();
        $fresh = $user->fresh();
        $this->assertSame('自分のみ更新', $fresh->name);
        $this->assertSame($user->email, $fresh->email);
        $this->assertSame($user->role, $fresh->role);
        $this->assertSame($user->status, $fresh->status);
        $this->assertTrue(hash_equals($originalHash, $fresh->password));
        $this->assertNull($fresh->avatar_url);
        $this->assertNull($fresh->plan_id);
        $this->assertSame('他の本人', $other->fresh()->name);

        foreach (['viewOwnProfile', 'updateOwnProfile', 'updateOwnAvatar', 'updateOwnPassword'] as $ability) {
            $this->assertTrue(Gate::forUser($user)->allows($ability, $user));
            $this->assertFalse(Gate::forUser($user)->allows($ability, $other));
        }
        $this->patch('/settings/profile/'.$other->id, ['name' => '不正'])->assertNotFound();
    }

    public function test_guest_cannot_access_any_settings_endpoint(): void
    {
        foreach ([['GET', 'settings.profile.edit'], ['PATCH', 'settings.profile.update'], ['POST', 'settings.avatar.store'], ['DELETE', 'settings.avatar.destroy'], ['PUT', 'settings.password.update']] as [$method, $route]) {
            $this->call($method, route($route))->assertRedirect(route('login'));
        }
    }

    public function test_password_tab_and_invalid_tab_use_provided_views(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('settings.profile.edit', ['tab' => 'password']))
            ->assertOk()->assertSee('name="current_password"', false)->assertDontSee('name="meeting_url"', false);
        $this->get(route('settings.profile.edit', ['tab' => 'unknown']))
            ->assertOk()->assertSee('name="bio"', false)->assertDontSee('name="current_password"', false);
    }

    public function test_seed_data_contains_all_roles_graduated_and_mixed_avatar_states(): void
    {
        $this->seed(UserSeeder::class);
        foreach (UserRole::cases() as $role) {
            $this->assertTrue(User::query()->where('role', $role)->exists());
        }
        $this->assertTrue(User::query()->where('status', UserStatus::Graduated)->exists());
        $this->assertTrue(User::query()->whereNotNull('avatar_url')->exists());
        $this->assertTrue(User::query()->whereNull('avatar_url')->exists());
    }
}
