<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_the_provided_management_screen(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/meeting-packs')->assertOk()->assertViewIs('meeting-pack.management.index');
    }

    public function test_admin_can_create_show_and_edit_a_draft_using_the_provided_forms(): void
    {
        $creator = User::factory()->admin()->create();
        $editor = User::factory()->admin()->create();
        $this->actingAs($creator)->get('/admin/meeting-packs/create')->assertOk();
        $this->post('/admin/meeting-packs', $this->payload() + ['created_by_user_id' => $editor->id])->assertRedirect();
        $pack = MeetingPack::query()->sole();
        $this->assertSame(MeetingPackStatus::Draft, $pack->status);
        $this->assertSame($creator->id, $pack->created_by_user_id);
        $this->assertSame(0, $pack->sort_order);
        $this->get(route('admin.meeting-packs.show', $pack))->assertOk()->assertSee($pack->name)->assertSee('この SKU の購入はまだありません。');
        $this->get(route('admin.meeting-packs.edit', $pack))->assertOk()->assertSee($pack->name);

        $this->actingAs($editor)->patch(route('admin.meeting-packs.update', $pack), array_replace($this->payload(), [
            'name' => '改定パック', 'meeting_count' => 100, 'price' => 1000000, 'sort_order' => 65535,
        ]))->assertRedirect(route('admin.meeting-packs.show', $pack))->assertSessionHas('success', '面談パックを更新しました。');
        $pack->refresh();
        $this->assertSame('改定パック', $pack->name);
        $this->assertSame(100, $pack->meeting_count);
        $this->assertSame(1000000, $pack->price);
        $this->assertSame(65535, $pack->sort_order);
        $this->assertSame($creator->id, $pack->created_by_user_id);
        $this->assertSame($editor->id, $pack->updated_by_user_id);
        $this->assertSame(MeetingPackStatus::Draft, $pack->status);
    }

    public function test_optional_fields_and_lower_bound_values_are_accepted(): void
    {
        $this->actingAs(User::factory()->admin()->create())->post('/admin/meeting-packs', [
            'name' => str_repeat('あ', 100), 'description' => null,
            'meeting_count' => 1, 'price' => 0, 'stripe_price_id' => null, 'sort_order' => null,
        ])->assertRedirect()->assertSessionHas('success', '面談パックを作成しました。');
        $pack = MeetingPack::query()->sole();
        $this->assertSame(1, $pack->meeting_count);
        $this->assertSame(0, $pack->price);
        $this->assertSame(0, $pack->sort_order);
    }

    /** @dataProvider statuses */
    public function test_editing_basic_information_never_changes_the_status(string $status): void
    {
        $pack = MeetingPack::factory()->create(['status' => $status]);
        $this->actingAs(User::factory()->admin()->create())->patch(route('admin.meeting-packs.update', $pack), $this->payload())->assertRedirect();
        $this->assertSame($status, $pack->fresh()->status->value);
    }

    public static function statuses(): array
    {
        return [['draft'], ['published'], ['archived']];
    }

    /** @dataProvider invalidInputs */
    public function test_invalid_create_and_update_do_not_write_data(string $field, mixed $value): void
    {
        $pack = MeetingPack::factory()->create();
        $before = $pack->fresh()->getAttributes();
        $data = array_replace($this->payload(), [$field => $value]);
        $this->actingAs(User::factory()->admin()->create())->postJson('/admin/meeting-packs', $data)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->patchJson(route('admin.meeting-packs.update', $pack), $data)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('meeting_packs', 1);
        $this->assertSame($before, $pack->fresh()->getAttributes());
    }

    public static function invalidInputs(): array
    {
        return [
            ['name', ''], ['name', str_repeat('あ', 101)], ['name', []],
            ['description', str_repeat('あ', 2001)],
            ['meeting_count', null], ['meeting_count', 0], ['meeting_count', 101], ['meeting_count', 1.5],
            ['price', null], ['price', -1], ['price', 1000001], ['price', 1.5],
            ['stripe_price_id', str_repeat('a', 256)],
            ['sort_order', -1], ['sort_order', 65536], ['sort_order', 1.5],
            ['status', 'published'], ['status', 'archived'],
        ];
    }

    /** @dataProvider transitionMatrix */
    public function test_only_the_three_confirmed_transitions_are_allowed(string $before, string $operation, string $after, bool $allowed): void
    {
        $pack = MeetingPack::factory()->create(['status' => $before]);
        $actor = User::factory()->admin()->create();
        $original = $pack->fresh()->getAttributes();
        $response = $this->actingAs($actor)->postJson(route('admin.meeting-packs.'.$operation, $pack));
        if ($allowed) {
            $response->assertRedirect(route('admin.meeting-packs.show', $pack))->assertSessionHas('success');
            $this->assertSame($after, $pack->fresh()->status->value);
            $this->assertSame($actor->id, $pack->fresh()->updated_by_user_id);
        } else {
            $response->assertConflict();
            $this->assertSame($original, $pack->fresh()->getAttributes());
        }
    }

    public static function transitionMatrix(): array
    {
        return [
            ['draft', 'publish', 'published', true],
            ['published', 'archive', 'archived', true],
            ['archived', 'unarchive', 'draft', true],
            ['draft', 'archive', 'archived', false],
            ['draft', 'unarchive', 'draft', false],
            ['published', 'publish', 'published', false],
            ['published', 'unarchive', 'draft', false],
            ['archived', 'publish', 'published', false],
            ['archived', 'archive', 'archived', false],
        ];
    }

    public function test_repeated_publish_is_rejected_and_html_conflicts_return_with_an_error(): void
    {
        $pack = MeetingPack::factory()->draft()->create();
        $url = route('admin.meeting-packs.show', $pack);
        $this->actingAs(User::factory()->admin()->create())->post(route('admin.meeting-packs.publish', $pack))->assertRedirect($url);
        $this->from($url)->post(route('admin.meeting-packs.publish', $pack))->assertRedirect($url)->assertSessionHas('error');
        $this->assertSame(MeetingPackStatus::Published, $pack->fresh()->status);
    }

    public function test_only_non_published_packs_without_purchase_history_can_be_physically_deleted(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        foreach ([MeetingPackStatus::Draft, MeetingPackStatus::Archived] as $status) {
            $pack = MeetingPack::factory()->create(['status' => $status]);
            $this->delete(route('admin.meeting-packs.destroy', $pack))->assertRedirect(route('admin.meeting-packs.index'))
                ->assertSessionHas('success', '面談パックを削除しました。');
            $this->assertDatabaseMissing('meeting_packs', ['id' => $pack->id]);
            $this->deleteJson(route('admin.meeting-packs.destroy', $pack))->assertNotFound();
        }
        $published = MeetingPack::factory()->published()->create();
        $this->deleteJson(route('admin.meeting-packs.destroy', $published))->assertConflict();
        $this->assertDatabaseHas('meeting_packs', ['id' => $published->id]);
        $show = route('admin.meeting-packs.show', $published);
        $this->get($show)->assertOk()->assertDontSee('name="_method" value="DELETE"', false);
        $this->from($show)->delete(route('admin.meeting-packs.destroy', $published))->assertRedirect($show)->assertSessionHas('error');
    }

    public function test_index_filters_names_and_status_and_paginates_twenty_in_existing_order(): void
    {
        for ($i = 0; $i < 23; $i++) {
            MeetingPack::factory()->draft()->create(['name' => sprintf('対象パック%02d', $i), 'sort_order' => $i]);
        }
        MeetingPack::factory()->published()->create(['name' => '対象公開パック']);
        MeetingPack::factory()->draft()->create(['name' => '無関係', 'description' => '対象パック']);
        $this->actingAs(User::factory()->admin()->create());
        $url = route('admin.meeting-packs.index', ['keyword' => '対象', 'status' => 'draft']);
        $response = $this->get($url)->assertOk()->assertDontSee('対象公開パック')->assertDontSee('無関係');
        $plans = $response->viewData('plans');
        $this->assertSame(20, $plans->perPage());
        $this->assertSame(23, $plans->total());
        $this->assertSame(range(0, 19), $plans->pluck('sort_order')->all());
        $this->assertStringContainsString('keyword=', $plans->nextPageUrl());
        $this->assertStringContainsString('status=draft', $plans->nextPageUrl());
        $page2 = $this->get($url.'&page=2')->assertOk()->viewData('plans');
        $this->assertSame([20, 21, 22], $page2->pluck('sort_order')->all());
        $this->get('/admin/meeting-packs')->assertViewHas('plans', fn ($p) => $p->total() === 25);
        foreach (['draft' => 24, 'published' => 1, 'archived' => 0] as $status => $count) {
            $this->get(route('admin.meeting-packs.index', ['status' => $status]))->assertViewHas('plans', fn ($p) => $p->total() === $count);
        }
        foreach ([['status' => 'invalid'], ['keyword' => str_repeat('a', 101)], ['page' => 0], ['page' => 'bad']] as $filter) {
            $this->getJson(route('admin.meeting-packs.index', $filter))->assertUnprocessable();
        }
    }

    /** @dataProvider otherRoles */
    public function test_other_roles_and_guests_cannot_use_any_management_endpoint(?string $role): void
    {
        $pack = MeetingPack::factory()->draft()->create();
        if ($role !== null) {
            $this->actingAs(User::factory()->create(['role' => $role]));
        }
        $base = '/admin/meeting-packs';
        foreach ([['GET', $base], ['GET', $base.'/create'], ['POST', $base], ['GET', $base.'/'.$pack->id],
            ['GET', $base.'/'.$pack->id.'/edit'], ['PATCH', $base.'/'.$pack->id], ['DELETE', $base.'/'.$pack->id],
            ['POST', $base.'/'.$pack->id.'/publish'], ['POST', $base.'/'.$pack->id.'/archive'], ['POST', $base.'/'.$pack->id.'/unarchive']] as [$method, $url]) {
            $response = $this->call($method, $url, $this->payload());
            if ($role === null) {
                $response->assertRedirect('/login');
            } else {
                $response->assertForbidden();
            }
        }
        $this->assertDatabaseCount('meeting_packs', 1);
        $this->assertSame(MeetingPackStatus::Draft, $pack->fresh()->status);
    }

    public static function otherRoles(): array
    {
        return [['student'], ['coach'], [null]];
    }

    private function payload(): array
    {
        return ['name' => '検証パック', 'description' => '検証用', 'meeting_count' => 3, 'price' => 9000];
    }
}
