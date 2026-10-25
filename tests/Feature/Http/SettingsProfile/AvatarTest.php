<?php

declare(strict_types=1);

namespace Tests\Feature\Http\SettingsProfile;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\UseCases\SettingsProfile\StoreAvatarAction;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AvatarTest extends TestCase
{
    use RefreshDatabase;

    private ?UploadedFile $spoofedSource = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public static function formats(): array
    {
        return [['png'], ['jpg'], ['jpeg'], ['webp']];
    }

    /** @dataProvider formats */
    public function test_allowed_images_are_saved_unchanged_with_safe_filename(string $extension): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('user-supplied.'.$extension, 80, 40);
        $bytes = file_get_contents($file->getRealPath());
        $this->actingAs($user)->post(route('settings.avatar.store'), ['avatar' => $file])
            ->assertRedirect(route('settings.profile.edit'))->assertSessionHas('success', 'アイコン画像を更新しました。');
        $paths = Storage::disk('public')->allFiles('avatars/'.$user->id);
        $this->assertCount(1, $paths);
        $this->assertMatchesRegularExpression('~/[0-9A-HJKMNP-TV-Z]{26}\.(png|jpe?g|webp)$~', $paths[0]);
        $this->assertSame($bytes, Storage::disk('public')->get($paths[0]));
        $this->assertSame(Storage::disk('public')->url($paths[0]), $user->fresh()->avatar_url);
        $this->actingAs($user->fresh())->get(route('settings.profile.edit'))->assertOk()->assertSee($user->fresh()->avatar_url);
    }

    public static function roles(): array
    {
        return ProfileTest::roles();
    }

    /** @dataProvider roles */
    public function test_each_role_can_replace_and_delete_only_own_avatar(UserRole $role, UserStatus $status): void
    {
        $user = User::factory()->create(['role' => $role, 'status' => $status]);
        $other = User::factory()->create(['avatar_url' => '/shared-avatar.png']);
        $this->actingAs($user)->post(route('settings.avatar.store'), ['avatar' => UploadedFile::fake()->image('first.png'), 'user_id' => $other->id])
            ->assertSessionHasNoErrors();
        $oldPath = Storage::disk('public')->allFiles()[0];
        $this->post(route('settings.avatar.store'), ['avatar' => UploadedFile::fake()->image('second.png')])->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($oldPath);
        $paths = Storage::disk('public')->allFiles();
        $this->assertCount(1, $paths);
        $this->delete(route('settings.avatar.destroy'), ['user_id' => $other->id])
            ->assertRedirect(route('settings.profile.edit'))->assertSessionHas('success', 'アイコン画像を削除しました。');
        $this->assertNull($user->fresh()->avatar_url);
        Storage::disk('public')->assertMissing($paths[0]);
        $this->assertSame('/shared-avatar.png', $other->fresh()->avatar_url);
        $this->actingAs($user->fresh())->get(route('settings.profile.edit'))->assertOk()->assertDontSee('画像を削除する');
        $this->delete(route('settings.avatar.destroy'))->assertSessionHasNoErrors();
    }

    public function test_two_megabyte_image_is_accepted(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('settings.avatar.store'), ['avatar' => UploadedFile::fake()->image('limit.png')->size(2048)])
            ->assertSessionHasNoErrors();
        $this->assertNotNull($user->fresh()->avatar_url);
    }

    public static function invalidFiles(): array
    {
        return [['missing'], ['oversize'], ['gif'], ['svg'], ['text'], ['fake png']];
    }

    /** @dataProvider invalidFiles */
    public function test_invalid_avatar_does_not_change_database_or_files(string $kind): void
    {
        $file = match ($kind) {
            'missing' => null,
            'oversize' => UploadedFile::fake()->image('large.png')->size(2049),
            'gif' => UploadedFile::fake()->image('animation.gif'),
            'svg' => UploadedFile::fake()->createWithContent('image.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
            'text' => UploadedFile::fake()->createWithContent('code.php', '<?php echo 1;'),
            'fake png' => $this->spoofedPng(),
        };
        $user = User::factory()->create(['avatar_url' => '/existing.png']);
        $this->actingAs($user)->postJson(route('settings.avatar.store'), ['avatar' => $file])
            ->assertUnprocessable()->assertJsonValidationErrors('avatar');
        $this->assertSame('/existing.png', $user->fresh()->avatar_url);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    private function spoofedPng(): UploadedFile
    {
        $this->spoofedSource = UploadedFile::fake()->createWithContent('image.png', '<?php echo 1;');

        // Fake Fileは拡張子からMIMEを返すため、実際のアップロード同様に内容を検出させる。
        return new UploadedFile($this->spoofedSource->getRealPath(), 'image.png', 'image/png', null, true);
    }

    public function test_unmanaged_or_other_user_files_are_never_removed(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $own = 'avatars/'.$user->id.'/'.Str::ulid().'.png';
        $foreign = 'avatars/'.$other->id.'/'.Str::ulid().'.png';
        foreach ([$own, $foreign, 'shared.png'] as $path) {
            Storage::disk('public')->put($path, 'test image');
        }
        foreach ([
            Storage::disk('public')->url($foreign),
            Storage::disk('public')->url('shared.png'),
            Storage::disk('public')->url('avatars/'.$user->id.'/../../shared.png'),
            'https://external.example.test/storage/'.$own,
        ] as $url) {
            $user->forceFill(['avatar_url' => $url])->save();
            $this->actingAs($user)->delete(route('settings.avatar.destroy'))->assertSessionHasNoErrors();
            foreach ([$own, $foreign, 'shared.png'] as $path) {
                Storage::disk('public')->assertExists($path);
            }
        }
    }

    public function test_failed_database_save_rolls_back_and_cleans_new_file_but_keeps_old_avatar(): void
    {
        $user = User::factory()->create();
        $oldPath = 'avatars/'.$user->id.'/'.Str::ulid().'.png';
        Storage::disk('public')->put($oldPath, 'old image');
        $oldUrl = Storage::disk('public')->url($oldPath);
        $user->forceFill(['avatar_url' => $oldUrl])->save();
        $dispatcher = User::getEventDispatcher();
        User::setEventDispatcher(clone $dispatcher);
        User::updating(fn () => throw new \RuntimeException('Simulated save failure.'));

        try {
            app(StoreAvatarAction::class)($user, UploadedFile::fake()->image('new.png'));
            $this->fail('Save should fail.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('avatar', $e->errors());
        } finally {
            User::setEventDispatcher($dispatcher);
        }
        $this->assertSame($oldUrl, $user->fresh()->avatar_url);
        $this->assertSame([$oldPath], Storage::disk('public')->allFiles());
    }

    public function test_storage_failure_does_not_update_avatar(): void
    {
        $user = User::factory()->create(['avatar_url' => '/old.png']);
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('url')->andReturnUsing(fn ($path) => '/storage/'.$path);
        $disk->shouldReceive('putFileAs')->once()->andReturn(false);
        $disk->shouldReceive('delete')->once()->andReturn(true);
        Storage::shouldReceive('disk')->with('public')->andReturn($disk);
        try {
            app(StoreAvatarAction::class)($user, UploadedFile::fake()->image('new.png'));
            $this->fail('Storage should fail.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('avatar', $e->errors());
        }
        $this->assertSame('/old.png', $user->fresh()->avatar_url);
    }
}
