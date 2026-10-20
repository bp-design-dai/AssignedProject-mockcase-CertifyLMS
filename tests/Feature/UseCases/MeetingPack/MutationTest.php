<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use App\Models\User;
use App\UseCases\MeetingPack\ChangeStatusAction;
use App\UseCases\MeetingPack\DestroyAction;
use App\UseCases\MeetingPack\StoreAction;
use App\UseCases\MeetingPack\UpdateAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class MutationTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_models_are_rechecked_before_transition_update_and_delete(): void
    {
        $actor = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->draft()->create(['sort_order' => 44]);
        app(ChangeStatusAction::class)($pack, MeetingPackStatus::Published, $actor);
        try {
            app(ChangeStatusAction::class)($pack, MeetingPackStatus::Published, $actor);
            $this->fail('重複公開を許可してはいけない');
        } catch (ConflictHttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        app(UpdateAction::class)($pack, ['name' => '基本情報のみ更新', 'status' => 'draft', 'created_by_user_id' => $actor->id], $actor);
        $this->assertSame(MeetingPackStatus::Published, $pack->fresh()->status);
        $this->assertSame(44, $pack->fresh()->sort_order);
        try {
            app(DestroyAction::class)($pack);
            $this->fail('古いDraftのインスタンスでPublishedを削除してはいけない');
        } catch (ConflictHttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseHas('meeting_packs', ['id' => $pack->id, 'status' => 'published']);
    }

    public function test_store_never_accepts_status_or_audit_fields_from_the_input(): void
    {
        $actor = User::factory()->admin()->create();
        $pack = app(StoreAction::class)(['name' => '下書き固定', 'meeting_count' => 1, 'price' => 0,
            'status' => 'published', 'created_by_user_id' => 'invalid', 'updated_by_user_id' => 'invalid'], $actor);
        $this->assertSame(MeetingPackStatus::Draft, $pack->status);
        $this->assertSame($actor->id, $pack->created_by_user_id);
        $this->assertSame($actor->id, $pack->updated_by_user_id);
    }

    public function test_transition_rolls_back_when_saving_fails(): void
    {
        $actor = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->draft()->create();
        $before = $pack->fresh()->getAttributes();
        $dispatcher = MeetingPack::getEventDispatcher();
        MeetingPack::setEventDispatcher(clone $dispatcher);
        MeetingPack::updated(fn () => throw new RuntimeException('検証用の保存失敗'));
        try {
            app(ChangeStatusAction::class)($pack, MeetingPackStatus::Published, $actor);
            $this->fail('保存失敗を無視してはいけない');
        } catch (RuntimeException $e) {
            $this->assertSame('検証用の保存失敗', $e->getMessage());
        } finally {
            MeetingPack::setEventDispatcher($dispatcher);
        }
        $this->assertSame($before, $pack->fresh()->getAttributes());
    }

    public function test_deletion_rolls_back_when_the_delete_event_fails(): void
    {
        $pack = MeetingPack::factory()->draft()->create();
        $dispatcher = MeetingPack::getEventDispatcher();
        MeetingPack::setEventDispatcher(clone $dispatcher);
        MeetingPack::deleted(fn () => throw new RuntimeException('検証用の削除失敗'));
        try {
            app(DestroyAction::class)($pack);
            $this->fail('削除失敗を無視してはいけない');
        } catch (RuntimeException $e) {
            $this->assertSame('検証用の削除失敗', $e->getMessage());
        } finally {
            MeetingPack::setEventDispatcher($dispatcher);
        }
        $this->assertDatabaseHas('meeting_packs', ['id' => $pack->id]);
    }
}
