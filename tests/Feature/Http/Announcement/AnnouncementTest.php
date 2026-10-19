<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Announcement;

use App\Models\Announcement;
use App\Models\AnnouncementDispatch;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\AnnouncementDeliveryService;
use Database\Seeders\AnnouncementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_students_delivers_both_channels_only_to_active_students(): void
    {
        $admin = User::factory()->admin()->create();
        $students = User::factory()->student()->count(2)->create();
        $excluded = collect([User::factory()->coach()->create(), $admin, User::factory()->student()->invited()->create(), User::factory()->student()->graduated()->create(), User::factory()->student()->withdrawn()->create(), User::factory()->student()->create()]);
        $excluded->last()->delete();
        $this->actingAs($admin)->post(route('admin.announcements.store'), $this->payload())->assertSessionHasNoErrors()->assertSessionHas('success')->assertRedirect();
        $history = Announcement::sole();
        $this->assertSame(2, $history->dispatched_count);
        $this->assertSame(0, $history->failed_count);
        $this->assertNotNull($history->dispatched_at);
        $this->assertSame($admin->id, $history->created_by_user_id);
        foreach ($students as $student) {
            $notification = $student->notifications()->sole();
            $this->assertSame('admin_announcement', $notification->data['notification_type']);
            $this->assertSame($history->body, $notification->data['body']);
            $this->assertSame(route('notifications.show', $notification), $notification->data['url']);
        }
        foreach ($excluded as $user) {
            $this->assertSame(0, $user->notifications()->count());
        }
        $this->assertCount(2, $this->mails());
        $this->assertEqualsCanonicalizing($students->pluck('email')->all(), $this->mails()->map(fn ($m) => $m->getOriginalMessage()->getTo()[0]->getAddress())->all());
    }

    public function test_qualification_target_includes_only_registered_active_students_without_duplicates(): void
    {
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();
        $included = User::factory()->student()->create();
        $other = User::factory()->student()->create();
        Enrollment::factory()->for($included)->for($certification)->learning()->create();
        Enrollment::factory()->for($included)->learning()->create();
        Enrollment::factory()->for($other)->learning()->create();
        $this->actingAs($admin)->post(route('admin.announcements.store'), $this->payload(['target_type' => 'certification', 'target_certification_id' => $certification->id]))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, Announcement::sole()->dispatched_count);
        $this->assertSame(1, $included->notifications()->count());
        $this->assertSame(0, $other->notifications()->count());
        $this->assertCount(1, $this->mails());
    }

    public function test_user_target_ignores_irrelevant_fields_and_delivers_only_to_selected_student(): void
    {
        $admin = User::factory()->admin()->create();
        $selected = User::factory()->student()->create();
        $other = User::factory()->student()->create();
        $this->actingAs($admin)->post(route('admin.announcements.store'), $this->payload(['target_type' => 'user', 'target_user_id' => $selected->id, 'target_certification_id' => 'invalid']))->assertSessionHasNoErrors();
        $history = Announcement::sole();
        $this->assertSame($selected->id, $history->target_user_id);
        $this->assertNull($history->target_certification_id);
        $this->assertSame(1, $selected->notifications()->count());
        $this->assertSame(0, $other->notifications()->count());
    }

    public function test_zero_recipients_is_failure_without_history_or_delivery(): void
    {
        $this->actingAs(User::factory()->admin()->create())->from(route('admin.announcements.create'))->post(route('admin.announcements.store'), $this->payload())->assertRedirect(route('admin.announcements.create'))->assertSessionHasErrors('target_type')->assertSessionHas('error');
        $this->assertDatabaseCount('announcements', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertCount(0, $this->mails());
    }

    public function test_empty_qualification_is_also_failure_without_history(): void
    {
        User::factory()->student()->create();
        $certification = Certification::factory()->create();
        $this->actingAs(User::factory()->admin()->create())->postJson(route('admin.announcements.store'), $this->payload(['target_type' => 'certification', 'target_certification_id' => $certification->id]))->assertUnprocessable()->assertJsonValidationErrors('target_type');
        $this->assertDatabaseCount('announcements', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_all_mail_failures_continue_and_leave_no_history_or_database_notifications(): void
    {
        User::factory()->student()->count(3)->create();
        Notification::shouldReceive('sendNow')->times(3)->andThrow(new \RuntimeException('非公開メールアドレス・本文を出力しない'));
        Log::shouldReceive('warning')->times(3)->with('Announcement delivery failed', \Mockery::on(fn ($context) => array_keys($context) === ['dispatch_id', 'exception_class'] && $context['exception_class'] === \RuntimeException::class));
        $data = $this->payload();
        $this->actingAs(User::factory()->admin()->create())->postJson(route('admin.announcements.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('target_type');
        $this->assertDatabaseCount('announcements', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->postJson(route('admin.announcements.store'), $data)->assertConflict();
        $this->assertDatabaseCount('announcement_dispatches', 1);
    }

    public function test_partial_failure_continues_to_later_users_and_records_exact_counts(): void
    {
        $users = User::factory()->student()->count(3)->create()->sortBy('id')->values();
        $attempts = [];
        Notification::shouldReceive('sendNow')->times(3)->andReturnUsing(function ($user) use ($users, &$attempts) {
            $attempts[] = $user->id;
            if ($user->id === $users[1]->id) {
                throw new \RuntimeException('メール失敗');
            }
        });
        $this->actingAs(User::factory()->admin()->create())->post(route('admin.announcements.store'), $this->payload())->assertSessionHasNoErrors()->assertSessionHas('success', 'お知らせを配信しました。（成功2件／失敗1件）');
        $this->assertSame($users->pluck('id')->all(), $attempts);
        $this->assertSame(2, Announcement::sole()->dispatched_count);
        $this->assertSame(1, Announcement::sole()->failed_count);
        $this->assertSame(1, $users[0]->notifications()->count());
        $this->assertSame(0, $users[1]->notifications()->count());
        $this->assertSame(1, $users[2]->notifications()->count());
    }

    public function test_database_failure_is_counted_and_does_not_prevent_other_recipients(): void
    {
        $users = User::factory()->student()->count(2)->create()->sortBy('id')->values();
        DatabaseNotification::creating(function ($notification) use ($users) {
            if ($notification->notifiable_id === $users[0]->id) {
                throw new \RuntimeException('DB保存失敗');
            }
        });
        try {
            $this->actingAs(User::factory()->admin()->create())->post(route('admin.announcements.store'), $this->payload())->assertSessionHasNoErrors();
        } finally {
            DatabaseNotification::flushEventListeners();
        }
        $this->assertSame(1, Announcement::sole()->dispatched_count);
        $this->assertSame(1, Announcement::sole()->failed_count);
        $this->assertCount(1, $this->mails());
    }

    public function test_same_post_is_idempotent_but_changed_payload_or_different_admin_is_rejected(): void
    {
        $student = User::factory()->student()->create();
        $data = $this->payload();
        $this->actingAs(User::factory()->admin()->create())->post(route('admin.announcements.store'), $data)->assertSessionHasNoErrors();
        $history = Announcement::sole();
        $notification = $student->notifications()->sole();
        $notification->markAsRead();
        $readAt = $notification->fresh()->read_at;
        $this->travel(5)->minutes();
        $this->post(route('admin.announcements.store'), $data)->assertRedirect(route('admin.announcements.show', $history));
        $this->postJson(route('admin.announcements.store'), array_merge($data, ['title' => '別の本文']))->assertConflict();
        $this->actingAs(User::factory()->admin()->create())->postJson(route('admin.announcements.store'), $data)->assertConflict();
        $this->assertDatabaseCount('announcements', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertCount(1, $this->mails());
        $this->assertEquals($readAt, $notification->fresh()->read_at);
    }

    public function test_processing_dispatch_cannot_be_started_again(): void
    {
        User::factory()->student()->create();
        $admin = User::factory()->admin()->create();
        $data = $this->payload();
        $payload = $data;
        unset($payload['idempotency_key']);
        ksort($payload);
        AnnouncementDispatch::create(['created_by_user_id' => $admin->id, 'idempotency_key' => $data['idempotency_key'], 'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR))]);
        $this->actingAs($admin)->postJson(route('admin.announcements.store'), $data)->assertConflict();
        $this->assertDatabaseCount('notifications', 0);
        $this->assertCount(0, $this->mails());
    }

    /** @dataProvider forbiddenUserTargets */
    public function test_invalid_user_roles_and_states_cannot_be_targeted(string $role, string $status, bool $deleted): void
    {
        $target = User::factory()->create(['role' => $role, 'status' => $status]);
        if ($deleted) {
            $target->delete();
        }
        $this->actingAs(User::factory()->admin()->create())->postJson(route('admin.announcements.store'), $this->payload(['target_type' => 'user', 'target_user_id' => $target->id]))->assertUnprocessable()->assertJsonValidationErrors('target_user_id');
        $this->assertDatabaseCount('announcements', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('announcement_dispatches', 0);
    }

    public static function forbiddenUserTargets(): array
    {
        return [['admin', 'in_progress', false], ['coach', 'in_progress', false], ['student', 'invited', false], ['student', 'graduated', false], ['student', 'withdrawn', false], ['student', 'in_progress', true]];
    }

    /** @dataProvider invalidInputs */
    public function test_validation_rejects_invalid_inputs(array $override, string $field): void
    {
        $this->actingAs(User::factory()->admin()->create())->postJson(route('admin.announcements.store'), $this->payload($override))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('announcement_dispatches', 0);
    }

    public static function invalidInputs(): array
    {
        return [[['title' => ''], 'title'], [['title' => str_repeat('あ', 201)], 'title'], [['body' => ''], 'body'], [['body' => str_repeat('あ', 5001)], 'body'], [['title' => []], 'title'], [['body' => []], 'body'], [['target_type' => 'coach'], 'target_type'], [['target_type' => 'user'], 'target_user_id'], [['target_type' => 'user', 'target_user_id' => (string) Str::ulid()], 'target_user_id'], [['target_type' => 'certification'], 'target_certification_id'], [['target_type' => 'certification', 'target_certification_id' => (string) Str::ulid()], 'target_certification_id'], [['idempotency_key' => ''], 'idempotency_key'], [['idempotency_key' => 'not-a-uuid'], 'idempotency_key']];
    }

    public function test_create_form_options_only_include_active_students_and_token_changes_per_form(): void
    {
        $student = User::factory()->student()->create();
        User::factory()->coach()->create();
        User::factory()->student()->withdrawn()->create();
        $this->actingAs(User::factory()->admin()->create());
        $first = $this->get(route('admin.announcements.create'))->assertOk()->assertViewHas('students', fn ($users) => $users->modelKeys() === [$student->id])->assertSee('name="idempotency_key"', false);
        $second = $this->get(route('admin.announcements.create'))->assertOk();
        $this->assertNotSame($first->viewData('idempotencyKey'), $second->viewData('idempotencyKey'));
    }

    public function test_history_is_sorted_paginated_and_has_no_edit_delete_or_redispatch_routes(): void
    {
        $admin = User::factory()->admin()->create();
        for ($i = 0; $i < 23; $i++) {
            $history = $this->history($admin, ['title' => '履歴'.$i, 'dispatched_at' => now()->subMinutes($i)]);
        }
        $this->actingAs($admin)->get(route('admin.announcements.index'))->assertOk()->assertSeeInOrder(['履歴0', '履歴1', '履歴2'])->assertViewHas('announcements', fn ($items) => $items->count() === 20 && $items->total() === 23);
        $this->get(route('admin.announcements.index', ['page' => 2]))->assertViewHas('announcements', fn ($items) => $items->count() === 3);
        $this->get(route('admin.announcements.show', $history))->assertOk()->assertSee('成功 1 件 ／ 失敗 0 件');
        $this->get('/admin/announcements/'.$history->id.'/edit')->assertNotFound();
        $this->put('/admin/announcements/'.$history->id)->assertStatus(405);
        $this->delete('/admin/announcements/'.$history->id)->assertStatus(405);
        $this->post('/admin/announcements/'.$history->id.'/dispatch')->assertNotFound();
    }

    /** @dataProvider unauthorizedRoles */
    public function test_non_admin_cannot_create_or_read_history(string $role): void
    {
        $history = $this->history(User::factory()->admin()->create());
        $this->actingAs(User::factory()->create(['role' => $role]));
        foreach (['admin.announcements.index', 'admin.announcements.create'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
        $this->get(route('admin.announcements.show', $history))->assertForbidden();
        $this->post(route('admin.announcements.store'), $this->payload())->assertForbidden();
        $this->assertDatabaseCount('announcement_dispatches', 0);
    }

    public static function unauthorizedRoles(): array
    {
        return [['student'], ['coach']];
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.announcements.index'))->assertRedirect(route('login'));
        $this->post(route('admin.announcements.store'), $this->payload())->assertRedirect(route('login'));
    }

    public function test_maximum_text_lengths_are_accepted_and_direct_detail_access_marks_only_own_notification_read(): void
    {
        $student = User::factory()->student()->create();
        $other = User::factory()->student()->create();
        $this->actingAs(User::factory()->admin()->create())->post(route('admin.announcements.store'), $this->payload(['title' => str_repeat('あ', 200), 'body' => str_repeat('い', 5000)]))->assertSessionHasNoErrors();
        $mine = $student->notifications()->sole();
        $foreign = $other->notifications()->sole();
        $this->actingAs($student)->get(route('notifications.show', $mine))->assertOk()->assertSee(str_repeat('い', 5000));
        $this->assertNotNull($mine->fresh()->read_at);
        $this->assertNull($foreign->fresh()->read_at);
    }

    public function test_a_logging_failure_does_not_interrupt_remaining_recipients(): void
    {
        $users = User::factory()->student()->count(2)->create()->sortBy('id')->values();
        Notification::shouldReceive('sendNow')->twice()->andReturnUsing(function ($user) use ($users) {
            if ($user->id === $users[0]->id) {
                throw new \RuntimeException('メール失敗');
            }
        });
        Log::shouldReceive('warning')->once()->andThrow(new \RuntimeException('ログ書込失敗'));
        $this->actingAs(User::factory()->admin()->create())->post(route('admin.announcements.store'), $this->payload())->assertSessionHasNoErrors();
        $this->assertSame(1, Announcement::sole()->dispatched_count);
        $this->assertSame(1, Announcement::sole()->failed_count);
        $this->assertSame(1, $users[1]->notifications()->count());
    }

    public function test_notification_full_text_is_owner_only_escaped_and_marked_read_idempotently(): void
    {
        $student = User::factory()->student()->create();
        $admin = User::factory()->admin()->create();
        $body = "<script>alert(1)</script>\n".str_repeat('全文の確認', 100);
        $this->actingAs($admin)->post(route('admin.announcements.store'), $this->payload(['body' => $body]))->assertSessionHasNoErrors();
        $notification = $student->notifications()->sole();
        $url = route('notifications.show', $notification);
        $this->get($url)->assertForbidden();
        $this->actingAs(User::factory()->student()->create())->get($url)->assertForbidden();
        $this->post(route('notifications.markAsRead', $notification))->assertForbidden();
        $this->assertNull($notification->fresh()->read_at);
        $this->actingAs($student)->get(route('notifications.index'))->assertOk()->assertSee('通知 (1 件未読)');
        $this->post(route('notifications.markAsRead', $notification))->assertRedirect($url);
        $readAt = $notification->fresh()->read_at;
        $this->travel(5)->minutes();
        $this->get($url)->assertOk()->assertSee(e($body), false)->assertDontSee('<script>alert(1)</script>', false);
        $this->assertEquals($readAt, $notification->fresh()->read_at);
        $this->get(route('notifications.index'))->assertSee('通知 (0 件未読)');
        $html = $this->mails()->sole()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString($url, $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_delivery_rechecks_status_and_idempotent_service_does_not_resend(): void
    {
        $student = User::factory()->student()->create();
        $id = (string) Str::ulid();
        $service = app(AnnouncementDeliveryService::class);
        $service->send($student, $id, '検証', '本文');
        $service->send($student, $id, '検証', '本文');
        $this->assertDatabaseCount('notifications', 1);
        $this->assertCount(1, $this->mails());
        $student->update(['status' => 'withdrawn']);
        $this->expectException(\RuntimeException::class);
        $service->send($student, (string) Str::ulid(), '非対象', '本文');
    }

    public function test_seeder_adds_three_types_without_mail_or_overwriting_read_state(): void
    {
        User::factory()->admin()->create(['email' => 'admin@certify-lms.test']);
        $student = User::factory()->student()->create(['email' => 'student@certify-lms.test']);
        Enrollment::factory()->for($student)->learning()->create();
        $this->seed(AnnouncementSeeder::class);
        $this->assertDatabaseCount('announcements', 3);
        $this->assertSame(3, $student->notifications()->count());
        $notification = $student->notifications()->first();
        $notification->markAsRead();
        $before = $notification->fresh()->getAttributes();
        $this->travel(1)->day();
        $this->seed(AnnouncementSeeder::class);
        $this->assertDatabaseCount('announcements', 3);
        $this->assertDatabaseCount('notifications', 3);
        $this->assertEquals($before, $notification->fresh()->getAttributes());
        $this->assertCount(0, $this->mails());
    }

    private function payload(array $override = []): array
    {
        return array_merge(['title' => '運営からのお知らせ', 'body' => "テスト用のお知らせです。\n2行目の本文。", 'target_type' => 'all_students', 'idempotency_key' => (string) Str::uuid()], $override);
    }

    private function history(User $admin, array $attributes = []): Announcement
    {
        return Announcement::create(array_merge(['title' => '履歴', 'body' => '本文', 'target_type' => 'all_students', 'created_by_user_id' => $admin->id, 'dispatched_count' => 1, 'failed_count' => 0, 'dispatched_at' => now()], $attributes));
    }

    private function mails()
    {
        return Mail::mailer()->getSymfonyTransport()->messages();
    }
}
