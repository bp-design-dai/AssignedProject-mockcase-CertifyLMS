<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Certification;

use App\Enums\CertificationDifficulty;
use App\Enums\CertificationStatus;
use App\Models\Certification;
use App\Models\CertificationCategory;
use App\Models\CertificationCoachAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StatusFilterTest extends TestCase
{
    use RefreshDatabase;

    public static function statuses(): array
    {
        return [
            'draft' => [CertificationStatus::Draft],
            'published' => [CertificationStatus::Published],
            'archived' => [CertificationStatus::Archived],
        ];
    }

    #[DataProvider('statuses')]
    public function test_status_filter_returns_only_selected_status(CertificationStatus $status): void
    {
        $admin = User::factory()->admin()->create();
        $certifications = [];
        foreach (CertificationStatus::cases() as $state) {
            $certifications[$state->value] = Certification::factory()->create(['status' => $state]);
        }

        $response = $this->actingAs($admin)->get(route('admin.certifications.index', ['status' => $status->value]));

        $response->assertOk();
        $this->assertSame([$certifications[$status->value]->id], $response->viewData('certifications')->getCollection()->modelKeys());
        $this->assertSame(1, $response->viewData('certifications')->total());
    }

    #[DataProvider('statuses')]
    public function test_status_filter_combines_with_keyword_category_and_difficulty(CertificationStatus $status): void
    {
        $admin = User::factory()->admin()->create();
        $category = CertificationCategory::factory()->create();
        $otherCategory = CertificationCategory::factory()->create();
        $matches = [];
        foreach (CertificationStatus::cases() as $state) {
            $attributes = [
                'name' => 'B-B-11 Matching '.$state->value,
                'category_id' => $category->id,
                'difficulty' => CertificationDifficulty::Beginner,
                'status' => $state,
            ];
            $matches[$state->value] = Certification::factory()->create($attributes);
            Certification::factory()->create(array_replace($attributes, ['name' => 'Unrelated name']));
            Certification::factory()->create(array_replace($attributes, ['category_id' => $otherCategory->id]));
            Certification::factory()->create(array_replace($attributes, ['difficulty' => CertificationDifficulty::Advanced]));
        }

        $response = $this->actingAs($admin)->get(route('admin.certifications.index', [
            'keyword' => 'B-B-11 Matching',
            'status' => $status->value,
            'category_id' => $category->id,
            'difficulty' => CertificationDifficulty::Beginner->value,
        ]));

        $response->assertOk();
        $this->assertSame([$matches[$status->value]->id], $response->viewData('certifications')->getCollection()->modelKeys());
        $this->assertSame(1, $response->viewData('certifications')->total());
    }

    public function test_unspecified_and_empty_status_keep_all_statuses_in_existing_order(): void
    {
        $admin = User::factory()->admin()->create();
        $expectedIds = [];
        foreach ([CertificationStatus::Published, CertificationStatus::Draft, CertificationStatus::Archived] as $status) {
            $older = Certification::factory()->create(['status' => $status, 'updated_at' => now()->subDays(2)]);
            $newer = Certification::factory()->create(['status' => $status, 'updated_at' => now()->subDay()]);
            array_push($expectedIds, $newer->id, $older->id);
        }

        foreach ([[], ['status' => '']] as $filters) {
            $response = $this->actingAs($admin)->get(route('admin.certifications.index', $filters));
            $response->assertOk();
            $this->assertSame($expectedIds, $response->viewData('certifications')->getCollection()->modelKeys());
            $this->assertSame(6, $response->viewData('certifications')->total());
        }
    }

    #[DataProvider('statuses')]
    public function test_filtered_pagination_keeps_filters_and_updated_at_order(CertificationStatus $status): void
    {
        $admin = User::factory()->admin()->create();
        $category = CertificationCategory::factory()->create();
        $attributes = [
            'name' => 'B-B-11 Page',
            'status' => $status,
            'category_id' => $category->id,
            'difficulty' => CertificationDifficulty::Beginner,
        ];
        $expectedIds = [];
        for ($index = 0; $index < 25; $index++) {
            $expectedIds[] = Certification::factory()->create($attributes + ['updated_at' => now()->subMinutes($index)])->id;
        }
        foreach (CertificationStatus::cases() as $otherStatus) {
            if ($otherStatus !== $status) {
                Certification::factory()->create(array_replace($attributes, ['status' => $otherStatus]));
            }
        }
        $filters = [
            'keyword' => 'B-B-11 Page', 'status' => $status->value,
            'category_id' => $category->id, 'difficulty' => CertificationDifficulty::Beginner->value,
        ];

        $response = $this->actingAs($admin)->get(route('admin.certifications.index', $filters));
        $response->assertOk();
        $page = $response->viewData('certifications');
        $this->assertSame(25, $page->total());
        $this->assertSame(20, $page->perPage());
        $this->assertSame(array_slice($expectedIds, 0, 20), $page->getCollection()->modelKeys());
        parse_str(parse_url($page->nextPageUrl(), PHP_URL_QUERY), $nextQuery);
        $this->assertEqualsCanonicalizing($filters + ['page' => '2'], $nextQuery);

        $response = $this->get(route('admin.certifications.index', $filters + ['page' => 2]));
        $response->assertOk();
        $this->assertSame(25, $response->viewData('certifications')->total());
        $this->assertSame(array_slice($expectedIds, 20), $response->viewData('certifications')->getCollection()->modelKeys());
    }

    #[DataProvider('statuses')]
    public function test_coach_status_filter_keeps_assignment_scope(CertificationStatus $status): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $assigned = [];
        foreach (CertificationStatus::cases() as $state) {
            $assigned[$state->value] = Certification::factory()->create(['status' => $state]);
            Certification::factory()->create(['status' => $state]);
            CertificationCoachAssignment::create([
                'id' => (string) Str::ulid(),
                'certification_id' => $assigned[$state->value]->id,
                'user_id' => $coach->id,
                'assigned_by_user_id' => $admin->id,
                'assigned_at' => now(),
            ]);
        }

        $response = $this->actingAs($coach)->get(route('admin.certifications.index', ['status' => $status->value]));
        $response->assertOk();
        $this->assertSame([$assigned[$status->value]->id], $response->viewData('certifications')->getCollection()->modelKeys());
        $this->assertSame(1, $response->viewData('certifications')->total());
    }

    public function test_guest_cannot_access_certification_management_list(): void
    {
        $this->get(route('admin.certifications.index'))->assertRedirect(route('login'));
    }
}
