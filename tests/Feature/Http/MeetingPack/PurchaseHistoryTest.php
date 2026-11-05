<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseHistoryTest extends TestCase
{
    use DatabaseMigrations;

    public function test_any_purchase_history_blocks_deletion_and_counts_are_scoped_to_the_pack(): void
    {
        $buyer = User::factory()->student()->create();
        $this->actingAs(User::factory()->admin()->create());
        foreach (['draft', 'archived'] as $status) {
            foreach (['pending', 'failed', 'succeeded', 'refunded'] as $paymentStatus) {
                $pack = MeetingPack::factory()->create(['status' => $status]);
                DB::table('payments')->insert(['id' => (string) Str::ulid(), 'meeting_pack_id' => $pack->id, 'user_id' => $buyer->id, 'pack_name' => $pack->name, 'amount' => $pack->price, 'quantity' => $pack->meeting_count, 'status' => $paymentStatus]);
                $this->assertTrue($pack->hasPurchaseHistory());
                $this->deleteJson(route('admin.meeting-packs.destroy', $pack))->assertConflict();
                $this->assertDatabaseHas('meeting_packs', ['id' => $pack->id, 'status' => $status]);
            }
        }
        $unreferenced = MeetingPack::factory()->draft()->create();
        $this->assertFalse($unreferenced->hasPurchaseHistory());
        $this->get('/admin/meeting-packs')->assertOk()->assertViewHas('plans', fn ($p) => $p->count() === 9
            && $p->firstWhere('id', $unreferenced->id)->payments_count === 0
            && $p->where('payments_count', 1)->count() === 8);
        $this->delete(route('admin.meeting-packs.destroy', $unreferenced))->assertRedirect();
        $this->assertDatabaseCount('payments', 8);
        $this->assertDatabaseCount('meeting_packs', 8);

    }
}
