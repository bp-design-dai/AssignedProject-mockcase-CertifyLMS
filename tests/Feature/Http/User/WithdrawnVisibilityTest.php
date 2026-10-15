<?php

declare(strict_types=1);

namespace Tests\Feature\Http\User;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WithdrawnVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public static function roles(): array
    {
        return [
            'student' => [UserRole::Student],
            'coach' => [UserRole::Coach],
            'admin' => [UserRole::Admin],
        ];
    }

    private function usersForRole(UserRole $role): array
    {
        $users = [];
        foreach (UserStatus::cases() as $status) {
            $users[$status->value] = User::factory()->create([
                'role' => $role,
                'status' => $status,
                'name' => 'B-B-04 '.$status->value,
                'email' => 'bb04-'.$status->value.'-'.$role->value.'@example.test',
            ]);
        }

        $deletedWithdrawn = User::factory()->withdrawn()->create([
            'role' => $role,
            'email' => 'bb04-deleted-withdrawn-'.$role->value.'@example.test',
        ]);
        $deletedWithdrawn->delete();
        $users['deleted_withdrawn'] = $deletedWithdrawn;

        $deletedActive = User::factory()->inProgress()->create([
            'role' => $role,
            'email' => 'bb04-deleted-active-'.$role->value.'@example.test',
        ]);
        $deletedActive->delete();
        $users['deleted_active'] = $deletedActive;

        return $users;
    }

    #[DataProvider('roles')]
    public function test_default_and_empty_status_exclude_withdrawn_for_each_role(UserRole $role): void
    {
        $admin = User::factory()->admin()->create();
        $users = $this->usersForRole($role);
        $filters = ['keyword' => 'bb04-', 'role' => $role->value];

        foreach ([$filters, $filters + ['status' => '']] as $query) {
            $response = $this->actingAs($admin)->get(route('admin.users.index', $query));
            $response->assertOk();
            $this->assertEqualsCanonicalizing([
                $users['invited']->id, $users['in_progress']->id, $users['graduated']->id,
            ], $response->viewData('users')->getCollection()->modelKeys());
            $this->assertSame(3, $response->viewData('users')->total());
        }
    }

    #[DataProvider('roles')]
    public function test_explicit_withdrawn_filter_keeps_search_and_role_filter(UserRole $role): void
    {
        $admin = User::factory()->admin()->create();
        $users = $this->usersForRole($role);
        User::factory()->withdrawn()->create(['email' => 'other-withdrawn@example.test']);

        $response = $this->actingAs($admin)->get(route('admin.users.index', [
            'keyword' => 'bb04-', 'role' => $role->value, 'status' => UserStatus::Withdrawn->value,
        ]));

        $response->assertOk();
        $this->assertEqualsCanonicalizing([
            $users['withdrawn']->id, $users['deleted_withdrawn']->id,
        ], $response->viewData('users')->getCollection()->modelKeys());
        $this->assertSame(2, $response->viewData('users')->total());

        $response = $this->get(route('admin.users.index', [
            'keyword' => 'B-B-04 withdrawn', 'role' => $role->value, 'status' => UserStatus::Withdrawn->value,
        ]));
        $response->assertOk();
        $this->assertSame([$users['withdrawn']->id], $response->viewData('users')->getCollection()->modelKeys());
    }

    #[DataProvider('roles')]
    public function test_non_withdrawn_status_filters_only_return_matching_visible_users(UserRole $role): void
    {
        $admin = User::factory()->admin()->create();
        $users = $this->usersForRole($role);

        foreach ([UserStatus::Invited, UserStatus::InProgress, UserStatus::Graduated] as $status) {
            $response = $this->actingAs($admin)->get(route('admin.users.index', [
                'keyword' => 'bb04-', 'role' => $role->value, 'status' => $status->value,
            ]));
            $response->assertOk();
            $this->assertSame([$users[$status->value]->id], $response->viewData('users')->getCollection()->modelKeys());
            $this->assertSame(1, $response->viewData('users')->total());
        }
    }

    public function test_pagination_excludes_withdrawn_and_retains_filters(): void
    {
        $admin = User::factory()->admin()->create();
        $expectedIds = [];
        for ($index = 0; $index < 25; $index++) {
            $expectedIds[] = User::factory()->student()->inProgress()->create([
                'email' => 'bb04-page-'.$index.'@example.test',
                'created_at' => now()->subMinutes($index),
            ])->id;
        }
        $withdrawn = User::factory()->student()->withdrawn()->create(['email' => 'bb04-page-withdrawn@example.test']);
        $deleted = User::factory()->student()->withdrawn()->create(['email' => 'bb04-page-deleted@example.test']);
        $deleted->delete();

        $filters = ['keyword' => 'bb04-page-', 'role' => UserRole::Student->value];
        $response = $this->actingAs($admin)->get(route('admin.users.index', $filters));
        $response->assertOk();
        $page = $response->viewData('users');
        $this->assertSame(25, $page->total());
        $this->assertSame(20, $page->perPage());
        $this->assertCount(20, $page->items());
        $firstIds = $page->getCollection()->modelKeys();
        parse_str(parse_url($page->nextPageUrl(), PHP_URL_QUERY), $nextQuery);
        $this->assertSame('bb04-page-', $nextQuery['keyword']);
        $this->assertSame('student', $nextQuery['role']);
        $this->assertSame('2', $nextQuery['page']);

        $response = $this->get(route('admin.users.index', $filters + ['page' => 2]));
        $response->assertOk();
        $secondIds = $response->viewData('users')->getCollection()->modelKeys();
        $this->assertCount(5, $secondIds);
        $this->assertEqualsCanonicalizing($expectedIds, array_merge($firstIds, $secondIds));
        $this->assertNotContains($withdrawn->id, array_merge($firstIds, $secondIds));
        $this->assertNotContains($deleted->id, array_merge($firstIds, $secondIds));
    }

    public function test_guest_cannot_access_user_list(): void
    {
        $this->get(route('admin.users.index'))->assertRedirect(route('login'));
    }
}
