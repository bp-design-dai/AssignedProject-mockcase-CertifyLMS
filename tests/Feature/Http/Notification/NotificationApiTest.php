<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Notification;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\ChatMember;
use App\Models\ChatRoom;
use App\Models\User;
use App\Notifications\ActivityNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/notifications';

    public function test_guests_and_bearer_only_requests_are_unauthenticated(): void
    {
        $this->get(self::BASE)->assertUnauthorized()->assertJsonStructure(['message']);
        $this->postJson(self::BASE.'/missing/read')->assertUnauthorized();
        $this->postJson(self::BASE.'/read-all')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer invalid-token')->get(self::BASE)->assertUnauthorized();
    }

    public function test_admin_is_rejected_by_all_api_operations_and_has_no_popover(): void
    {
        $admin = User::factory()->admin()->create();
        $notification = $this->store($admin);
        $this->actingAs($admin)->getJson(self::BASE)->assertForbidden();
        $this->postJson(self::BASE.'/'.$notification->id.'/read')->assertForbidden();
        $this->postJson(self::BASE.'/read-all')->assertForbidden();
        $this->assertNull($notification->fresh()->read_at);
        $this->get(route('settings.profile.edit'))->assertOk()->assertDontSee('data-notification-popover-root', false);
    }

    #[DataProvider('roles')]
    public function test_student_and_coach_get_only_their_latest_ten_notifications(string $role): void
    {
        $this->freezeTime();
        $user = User::factory()->create(['role' => $role]);
        $other = User::factory()->student()->create();
        $this->store($other, ['title' => 'foreign-secret']);
        $mine = collect();
        for ($i = 0; $i < 24; $i++) {
            $mine->push($this->store($user, ['title' => 'notification-'.$i], $i % 2 === 0, now()->subMinutes($i)));
        }
        $all = $this->actingAs($user)->getJson(self::BASE)->assertOk()->assertJsonCount(10, 'notifications')
            ->assertJsonPath('unread_count', 12)->assertJsonMissing(['title' => 'foreign-secret'])
            ->assertJsonPath('notifications.0.id', $mine[0]->id)->assertJsonPath('notifications.9.id', $mine[9]->id)
            ->assertJsonStructure(['notifications' => [['id', 'title', 'preview', 'created_at', 'created_at_human', 'is_read', 'url']]]);
        $this->assertStringContainsString('no-store', $all->headers->get('Cache-Control'));
        $this->assertSame(['id', 'title', 'preview', 'created_at', 'created_at_human', 'is_read', 'url'], array_keys($all->json('notifications.0')));
        $this->getJson(self::BASE.'?tab=unread')->assertOk()->assertJsonCount(10, 'notifications')
            ->assertJsonPath('unread_count', 12)->assertJsonPath('notifications.0.id', $mine[1]->id)
            ->assertJsonPath('notifications.9.id', $mine[19]->id)->assertJsonPath('notifications.0.is_read', false);
        $this->getJson(self::BASE.'?tab=unknown')->assertUnprocessable();
        $this->get(route('settings.profile.edit'))->assertOk()->assertSee('data-notification-popover-root', false);
    }

    public static function roles(): array
    {
        return [['student'], ['coach']];
    }

    public function test_empty_notifications_and_equal_timestamps_have_deterministic_order(): void
    {
        $this->freezeTime();
        $user = User::factory()->student()->create();
        $this->actingAs($user)->getJson(self::BASE)->assertExactJson(['notifications' => [], 'unread_count' => 0]);
        $first = $this->store($user);
        $second = $this->store($user);
        $expected = collect([$first, $second])->sortByDesc('id')->pluck('id')->all();
        $this->assertSame($expected, $this->getJson(self::BASE)->json('notifications.*.id'));
    }

    #[DataProvider('roles')]
    public function test_read_is_owned_idempotent_and_preserves_other_notifications(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $mine = $this->store($user);
        $other = $this->store(User::factory()->student()->create());
        $url = self::BASE.'/'.$mine->id.'/read';
        $this->actingAs($user)->postJson(self::BASE.'/'.$other->id.'/read')->assertNotFound();
        $this->postJson(self::BASE.'/missing/read')->assertNotFound();
        $this->postJson($url)->assertOk()->assertExactJson(['url' => '/notifications', 'unread_count' => 0]);
        $when = $mine->fresh()->read_at;
        $this->assertNotNull($when);
        $this->travel(5)->minutes();
        $this->postJson($url)->assertOk();
        $this->assertEquals($when, $mine->fresh()->read_at);
        $this->assertNull($other->fresh()->read_at);
    }

    public function test_read_all_includes_notifications_outside_the_displayed_ten(): void
    {
        $user = User::factory()->student()->create();
        $other = $this->store(User::factory()->student()->create());
        for ($i = 0; $i < 25; $i++) {
            $this->store($user);
        }
        $this->actingAs($user)->getJson(self::BASE)->assertJsonCount(10, 'notifications');
        $this->postJson(self::BASE.'/read-all')->assertExactJson(['unread_count' => 0]);
        $this->assertSame(25, $user->readNotifications()->count());
        $this->assertNull($other->fresh()->read_at);
        $readDates = $user->notifications()->pluck('read_at', 'id')->map(fn ($date) => $date->toIso8601String())->all();
        $this->travel(1)->hours();
        $this->postJson(self::BASE.'/read-all')->assertOk();
        $this->assertSame($readDates, $user->notifications()->pluck('read_at', 'id')->map(fn ($date) => $date->toIso8601String())->all());
    }

    public function test_destinations_are_rebuilt_and_revoked_access_cannot_mark_read(): void
    {
        $user = User::factory()->student()->create();
        $room = ChatRoom::factory()->create();
        $member = ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $user->id]);
        $notification = $this->store($user, ['notification_type' => 'chat_message_received', 'resource_id' => $room->id, 'url' => 'https://evil.example']);
        $this->actingAs($user)->getJson(self::BASE)->assertJsonPath('notifications.0.url', route('chat.show', $room, false));
        $member->delete();
        $this->getJson(self::BASE)->assertOk()->assertJsonPath('notifications.0.url', null);
        $this->postJson(self::BASE.'/'.$notification->id.'/read')->assertForbidden();
        $this->assertNull($notification->fresh()->read_at);
        $room->delete();
        $this->postJson(self::BASE.'/'.$notification->id.'/read')->assertNotFound();
        $this->assertNull($notification->fresh()->read_at);
    }

    #[DataProvider('badgeCounts')]
    public function test_initial_badge_counts_match_api_and_are_capped_only_for_display(int $count, string $display): void
    {
        $user = User::factory()->student()->create();
        for ($i = 0; $i < $count; $i++) {
            $this->store($user);
        }
        $this->actingAs($user)->getJson(self::BASE)->assertJsonPath('unread_count', $count);
        $html = $this->get(route('settings.profile.edit'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-notification-popover-badge[\s\S]*?>\s*'.preg_quote($display, '/').'\s*<\/span>/', $html);
        if ($count === 0) {
            $this->assertMatchesRegularExpression('/data-notification-popover-badge[\s\S]*?class="[^"]*hidden/', $html);
        }
    }

    public static function badgeCounts(): array
    {
        return [[0, '0'], [1, '1'], [99, '99'], [100, '99+']];
    }

    public function test_csrf_missing_invalid_and_expired_session_do_not_change_notifications(): void
    {
        $this->app->bind(VerifyCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends VerifyCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        config(['sanctum.stateful' => ['localhost']]);
        $user = User::factory()->student()->create();
        $notification = $this->store($user);
        $this->actingAs($user)->withHeader('Origin', 'http://localhost')->withSession(['_token' => 'valid-csrf-token']);
        $this->postJson(self::BASE.'/read-all')->assertStatus(419);
        $this->withHeader('X-CSRF-TOKEN', 'invalid')->postJson(self::BASE.'/'.$notification->id.'/read')->assertStatus(419);
        $this->assertNull($notification->fresh()->read_at);
        $this->getJson('/sanctum/csrf-cookie')->assertNoContent()->assertCookie('XSRF-TOKEN');
        $this->withHeader('X-CSRF-TOKEN', 'valid-csrf-token')->postJson(self::BASE.'/'.$notification->id.'/read')->assertOk();
        auth()->forgetGuards();
        $this->flushSession();
        $this->getJson(self::BASE)->assertUnauthorized();
    }

    private function store(User $user, array $data = [], bool $read = false, $date = null)
    {
        return $user->notifications()->create([
            'id' => (string) Str::uuid(), 'type' => ActivityNotification::class,
            'data' => array_merge(['title' => '通知', 'message' => 'お知らせ'], $data),
            'read_at' => $read ? now() : null, 'created_at' => $date ?? now(), 'updated_at' => $date ?? now(),
        ]);
    }
}
