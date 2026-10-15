<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Chat;

use App\Enums\UserRole;
use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\ChatUnreadCountService;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UnreadCountsRegressionTest extends TestCase
{
    use RefreshDatabase;

    public static function roles(): array
    {
        return ['student' => [UserRole::Student], 'coach' => [UserRole::Coach]];
    }

    public static function rolesAndReadStates(): array
    {
        return [
            'student never read' => [UserRole::Student, false],
            'student previously read' => [UserRole::Student, true],
            'coach never read' => [UserRole::Coach, false],
            'coach previously read' => [UserRole::Coach, true],
        ];
    }

    private function roomFor(User $student, User $viewer, User $other, ?CarbonInterface $lastReadAt): ChatRoom
    {
        $room = ChatRoom::factory()->for(Enrollment::factory()->for($student))->create();
        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $viewer->id, 'last_read_at' => $lastReadAt]);
        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $other->id, 'last_read_at' => null]);

        return $room;
    }

    #[DataProvider('rolesAndReadStates')]
    public function test_own_messages_do_not_create_unread_counts_or_room_badges(UserRole $role, bool $previouslyRead): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        [$viewer, $other] = $role === UserRole::Student ? [$student, $coach] : [$coach, $student];
        $lastReadAt = $previouslyRead ? now()->startOfSecond()->subMinutes(5) : null;
        $room = $this->roomFor($student, $viewer, $other, $lastReadAt);
        $currentRoom = $this->roomFor($student, $viewer, $other, null);
        ChatMessage::factory()->count(2)->create([
            'chat_room_id' => $room->id, 'sender_user_id' => $viewer->id, 'created_at' => now()->subMinutes(2),
        ]);
        $service = app(ChatUnreadCountService::class);

        $this->assertSame(0, $service->messageCountInRoom($room, $viewer));
        $this->assertSame([$room->id => 0, $currentRoom->id => 0], $service->messageCountsByRoomForUser([$room, $currentRoom], $viewer));
        $this->assertSame(0, $service->roomCountForUser($viewer));
        $this->assertEquals($lastReadAt, ChatMember::forRoom($room)->forUser($viewer)->first()->last_read_at);

        $response = $this->actingAs($viewer)->get(route('chat.show', $currentRoom));
        $response->assertOk();
        $this->assertSame(0, $response->viewData('navRoomUnreadCounts')[$room->id]);
        $response->assertDontSee('aria-label="未読 ', false);
    }

    #[DataProvider('roles')]
    public function test_multiple_rooms_count_only_other_senders_after_each_viewers_read_time(UserRole $role): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        [$viewer, $other] = $role === UserRole::Student ? [$student, $coach] : [$coach, $student];
        $readAt = now()->startOfSecond()->subMinutes(10);
        $roomA = $this->roomFor($student, $viewer, $other, $readAt);
        foreach ([$readAt->copy()->subSecond(), $readAt, $readAt->copy()->addSecond()] as $createdAt) {
            ChatMessage::factory()->create(['chat_room_id' => $roomA->id, 'sender_user_id' => $other->id, 'created_at' => $createdAt]);
        }
        ChatMessage::factory()->create(['chat_room_id' => $roomA->id, 'sender_user_id' => $viewer->id, 'created_at' => $readAt->copy()->addSecond()]);
        $roomB = $this->roomFor($student, $viewer, $other, null);
        ChatMessage::factory()->count(2)->create(['chat_room_id' => $roomB->id, 'sender_user_id' => $other->id]);
        ChatMessage::factory()->create(['chat_room_id' => $roomB->id, 'sender_user_id' => $viewer->id]);
        $roomC = $this->roomFor($student, $viewer, $other, null);
        ChatMessage::factory()->create(['chat_room_id' => $roomC->id, 'sender_user_id' => $viewer->id]);
        $nonMemberRoom = ChatRoom::factory()->create();
        ChatMessage::factory()->count(3)->create(['chat_room_id' => $nonMemberRoom->id, 'sender_user_id' => $other->id]);
        $service = app(ChatUnreadCountService::class);

        $this->assertSame(1, $service->messageCountInRoom($roomA, $viewer));
        $this->assertSame(2, $service->messageCountInRoom($roomB, $viewer));
        $this->assertSame(0, $service->messageCountInRoom($roomC, $viewer));
        $this->assertSame(0, $service->messageCountInRoom($nonMemberRoom, $viewer));
        $this->assertSame([
            $roomA->id => 1, $roomB->id => 2, $roomC->id => 0, $nonMemberRoom->id => 0,
        ], $service->messageCountsByRoomForUser([$roomA, $roomB, $roomC, $nonMemberRoom], $viewer));
        $this->assertSame(2, $service->roomCountForUser($viewer));
        $this->assertSame(1, $service->messageCountInRoom($roomB, $other));
    }

    #[DataProvider('roles')]
    public function test_opening_room_clears_only_viewers_unread_state(UserRole $role): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        [$viewer, $other] = $role === UserRole::Student ? [$student, $coach] : [$coach, $student];
        $room = $this->roomFor($student, $viewer, $other, null);
        ChatMessage::factory()->create(['chat_room_id' => $room->id, 'sender_user_id' => $other->id, 'created_at' => now()->subMinute()]);
        $service = app(ChatUnreadCountService::class);
        $this->assertSame(1, $service->messageCountInRoom($room, $viewer));

        $this->actingAs($viewer)->get(route('chat.show', $room))->assertOk();

        $this->assertNotNull(ChatMember::forRoom($room)->forUser($viewer)->first()->last_read_at);
        $this->assertNull(ChatMember::forRoom($room)->forUser($other)->first()->last_read_at);
        $this->assertSame(0, $service->messageCountInRoom($room, $viewer));
        $this->assertSame([$room->id => 0], $service->messageCountsByRoomForUser([$room], $viewer));
        $this->assertSame(0, $service->roomCountForUser($viewer));
    }
}
