<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Notification;

use App\Events\ChatMessageSent;
use App\Events\MeetingCanceled;
use App\Events\MeetingReserved;
use App\Events\QaReplyCreated;
use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Meeting;
use App\Models\QaReply;
use App\Models\User;
use App\Notifications\ActivityNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_is_scoped_paginated_sorted_and_filters_unread(): void
    {
        $user = User::factory()->student()->create();
        $other = User::factory()->student()->create();
        $this->store($other, ['title' => '他人宛の非公開通知']);
        for ($i = 0; $i < 25; $i++) {
            $this->store($user, ['title' => '検証通知'.$i], $i % 2 === 0, now()->subMinutes($i));
        }
        $this->actingAs($user)->get(route('notifications.index'))->assertOk()->assertDontSee('他人宛の非公開通知')
            ->assertSeeInOrder(['検証通知0', '検証通知1', '検証通知2'])->assertViewHas('unreadCount', 12)
            ->assertViewHas('notifications', fn ($notifications) => $notifications->count() === 20 && $notifications->total() === 25);
        $this->get(route('notifications.index', ['tab' => 'unread']))->assertOk()
            ->assertViewHas('notifications', fn ($notifications) => $notifications->total() === 12 && $notifications->every(fn ($n) => $n->read_at === null));
        $this->get(route('notifications.index', ['page' => 2]))->assertViewHas('notifications', fn ($notifications) => $notifications->count() === 5);
        $this->getJson(route('notifications.index', ['tab' => 'invalid']))->assertUnprocessable();
    }

    public function test_mark_read_rejects_foreign_notification_and_mark_all_never_changes_other_user(): void
    {
        $user = User::factory()->student()->create();
        $other = User::factory()->student()->create();
        $mine = $this->store($user);
        $foreign = $this->store($other);
        $this->actingAs($user)->post(route('notifications.markAsRead', $foreign))->assertForbidden();
        $this->assertNull($foreign->fresh()->read_at);
        $this->post(route('notifications.markAllAsRead'))->assertRedirect(route('notifications.index'));
        $this->assertNotNull($mine->fresh()->read_at);
        $this->assertNull($foreign->fresh()->read_at);
        $readAt = $mine->fresh()->read_at;
        $this->travel(5)->minutes();
        $this->post(route('notifications.markAllAsRead'))->assertRedirect();
        $this->assertEquals($readAt, $mine->fresh()->read_at);
    }

    /** @dataProvider destinationTypes */
    public function test_click_marks_read_and_redirects_to_correct_business_resource(string $type, string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        if ($type === 'chat_message_received') {
            $room = ChatRoom::factory()->create();
            ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $user->id]);
            $event = new ChatMessageSent(ChatMessage::factory()->create(['chat_room_id' => $room->id]));
            $url = route('chat.show', $room);
        } elseif ($type === 'qa_reply_received') {
            $reply = QaReply::factory()->create();
            $reply->thread->update(['user_id' => $user->id]);
            $event = new QaReplyCreated($reply);
            $url = route('qa-board.show', $reply->thread);
        } else {
            $meeting = Meeting::factory()->create([$role === 'coach' ? 'coach_id' : 'student_id' => $user->id]);
            $event = $type === 'meeting_reserved' ? new MeetingReserved($meeting) : new MeetingCanceled($meeting);
            $url = route($role === 'coach' ? 'coach.meetings.index' : 'meetings.index');
        }
        event($event);
        $notification = $user->notifications()->sole();
        // 外部URLが保存されてもリダイレクト先として使用しない。
        $notification->update(['data' => array_merge($notification->data, ['url' => 'https://evil.example/path'])]);
        $this->actingAs($user)->post(route('notifications.markAsRead', $notification))->assertRedirect($url);
        $this->assertNotNull($notification->fresh()->read_at);
        $this->get($url)->assertOk();
        $readAt = $notification->fresh()->read_at;
        $this->travel(5)->minutes();
        $this->post(route('notifications.markAsRead', $notification))->assertRedirect($url);
        $this->assertEquals($readAt, $notification->fresh()->read_at);
    }

    public static function destinationTypes(): array
    {
        return [['chat_message_received', 'student'], ['chat_message_received', 'coach'], ['qa_reply_received', 'student'], ['meeting_reserved', 'student'], ['meeting_reserved', 'coach'], ['meeting_canceled', 'student'], ['meeting_canceled', 'coach']];
    }

    public function test_destination_authorization_is_rechecked_after_chat_membership_is_removed(): void
    {
        $user = User::factory()->student()->create();
        $room = ChatRoom::factory()->create();
        $member = ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $user->id]);
        $notification = $this->store($user, ['notification_type' => 'chat_message_received', 'resource_id' => $room->id]);
        $member->delete();
        $this->actingAs($user)->post(route('notifications.markAsRead', $notification))->assertForbidden();
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_missing_resource_returns_404_without_marking_read(): void
    {
        $user = User::factory()->student()->create();
        $notification = $this->store($user, ['notification_type' => 'qa_reply_received', 'resource_id' => (string) Str::ulid()]);
        $this->actingAs($user)->post(route('notifications.markAsRead', $notification))->assertNotFound();
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_bell_reflects_unread_count_and_notification_html_is_escaped(): void
    {
        $user = User::factory()->student()->create();
        $this->store($user, ['title' => '<script>alert(1)</script>']);
        $this->actingAs($user)->get(route('notifications.index'))->assertOk()->assertSee('通知 (1 件未読)')->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->post(route('notifications.markAllAsRead'));
        $this->get(route('notifications.index'))->assertSee('通知 (0 件未読)');
    }

    public function test_guests_and_admin_cannot_operate_notifications_and_no_out_of_scope_api_or_delete_route_exists(): void
    {
        $notification = $this->store(User::factory()->student()->create());
        $this->get(route('notifications.index'))->assertRedirect(route('login'));
        $this->post(route('notifications.markAsRead', $notification))->assertRedirect(route('login'));
        $this->post(route('notifications.markAllAsRead'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->admin()->create())->get(route('notifications.index'))->assertForbidden();
        $this->post(route('notifications.markAsRead', $notification))->assertForbidden();
        $this->post(route('notifications.markAllAsRead'))->assertForbidden();
        $this->get('/api/notifications')->assertNotFound();
        // S-B-08で同じURLのGET詳細を追加。DELETEは依然未実装のため405となる。
        $this->delete('/notifications/'.$notification->id)->assertStatus(405);
        $this->assertDatabaseHas('notifications', ['id' => $notification->id]);
    }

    private function store(User $user, array $data = [], bool $read = false, $date = null)
    {
        return $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => ActivityNotification::class,
            'data' => array_merge(['title' => '通知', 'message' => 'お知らせ'], $data),
            'read_at' => $read ? now() : null,
            'created_at' => $date ?? now(),
            'updated_at' => $date ?? now(),
        ]);
    }
}
