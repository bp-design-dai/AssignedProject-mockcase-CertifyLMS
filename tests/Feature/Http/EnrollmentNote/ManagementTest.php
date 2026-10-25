<?php

declare(strict_types=1);

namespace Tests\Feature\Http\EnrollmentNote;

use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagementTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(): array
    {
        $coach = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        $enrollment = Enrollment::factory()->create();
        foreach ([$coach, $otherCoach] as $author) {
            CertificationCoachAssignment::factory()->create([
                'certification_id' => $enrollment->certification_id,
                'user_id' => $author->id, 'assigned_by_user_id' => $admin->id,
            ]);
        }

        return [$enrollment, $coach, $otherCoach, $admin];
    }

    public function test_assigned_coach_can_add_note_with_server_controlled_parent_and_author(): void
    {
        [$parent, $coach, $other] = $this->scenario();
        $outside = Enrollment::factory()->create();
        $this->actingAs($coach)->post(route('enrollments.notes.store', $parent), [
            'body' => "S-B-07の観察\n次回の確認事項", 'author_id' => $other->id,
            'enrollment_id' => $outside->id, 'created_at' => '2000-01-01',
        ])->assertRedirect(route('enrollments.show', $parent))->assertSessionHas('success', 'メモを追加しました。');
        $note = EnrollmentNote::query()->sole();
        $this->assertSame($parent->id, $note->enrollment_id);
        $this->assertSame($coach->id, $note->author_id);
        $this->assertSame("S-B-07の観察\n次回の確認事項", $note->body);
        $this->assertTrue($note->created_at->isToday());
        $this->assertDatabaseCount('meeting_memos', 0);
    }

    public function test_admin_can_add_to_any_enrollment_without_coach_assignment(): void
    {
        $parent = Enrollment::factory()->create();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('enrollments.notes.store', $parent), ['body' => '運営の観察'])
            ->assertRedirect(route('enrollments.show', $parent));
        $this->assertDatabaseHas('enrollment_notes', ['enrollment_id' => $parent->id, 'author_id' => $admin->id]);
    }

    public function test_coaches_share_chronological_notes_but_only_own_note_has_edit_and_delete_controls(): void
    {
        [$parent, $coach, $other, $admin] = $this->scenario();
        $old = EnrollmentNote::factory()->for($parent)->create(['author_id' => $coach->id, 'body' => '古い本人メモ', 'created_at' => now()->subDays(2)]);
        $middle = EnrollmentNote::factory()->for($parent)->create(['author_id' => $other->id, 'body' => '別コーチの共有メモ', 'created_at' => now()->subDay()]);
        $new = EnrollmentNote::factory()->for($parent)->create(['author_id' => $admin->id, 'body' => '新しい管理者メモ']);
        $response = $this->actingAs($coach)->get(route('enrollments.show', $parent));
        $response->assertOk()->assertSeeInOrder(['新しい管理者メモ', '別コーチの共有メモ', '古い本人メモ'])
            ->assertSee(route('enrollment-notes.edit', $old), false)
            ->assertSee(route('enrollment-notes.destroy', $old), false)
            ->assertDontSee(route('enrollment-notes.edit', $middle), false)
            ->assertDontSee(route('enrollment-notes.destroy', $middle), false)
            ->assertDontSee(route('enrollment-notes.edit', $new), false);
        $this->actingAs($other)->get(route('enrollments.show', $parent))->assertOk()->assertSee('古い本人メモ');
    }

    public function test_owner_can_edit_body_without_reassigning_or_changing_creation_date(): void
    {
        [$parent, $coach, $other] = $this->scenario();
        $note = EnrollmentNote::factory()->for($parent)->create(['author_id' => $coach->id, 'created_at' => now()->subDays(2)]);
        $outside = Enrollment::factory()->create();
        $this->actingAs($coach)->get(route('enrollment-notes.edit', $note))->assertOk()->assertViewIs('enrollment-note.edit');
        $this->patch(route('enrollment-notes.update', $note), [
            'body' => '更新した本文', 'author_id' => $other->id, 'enrollment_id' => $outside->id, 'created_at' => now(),
        ])->assertRedirect(route('enrollments.show', $parent))->assertSessionHas('success', 'メモを更新しました。');
        $fresh = $note->fresh();
        $this->assertSame('更新した本文', $fresh->body);
        $this->assertSame($coach->id, $fresh->author_id);
        $this->assertSame($parent->id, $fresh->enrollment_id);
        $this->assertTrue($fresh->created_at->equalTo($note->created_at));
    }

    public function test_owner_can_physically_delete_only_target_note_and_second_delete_is_404(): void
    {
        [$parent, $coach, $other] = $this->scenario();
        $note = EnrollmentNote::factory()->for($parent)->create(['author_id' => $coach->id]);
        $retained = EnrollmentNote::factory()->for($parent)->create(['author_id' => $other->id]);
        $this->actingAs($coach)->delete(route('enrollment-notes.destroy', $note))
            ->assertRedirect(route('enrollments.show', $parent))->assertSessionHas('success', 'メモを削除しました。');
        $this->assertDatabaseMissing('enrollment_notes', ['id' => $note->id]);
        $this->assertDatabaseHas('enrollment_notes', ['id' => $retained->id]);
        $this->assertDatabaseHas('enrollments', ['id' => $parent->id, 'deleted_at' => null]);
        $this->delete(route('enrollment-notes.destroy', $note))->assertNotFound();
    }

    public function test_admin_can_view_edit_and_delete_another_coachs_note(): void
    {
        [$parent, $coach, , $admin] = $this->scenario();
        $note = EnrollmentNote::factory()->for($parent)->create(['author_id' => $coach->id]);
        $this->actingAs($admin)->get(route('enrollments.show', $parent))->assertOk()->assertSee(route('enrollment-notes.edit', $note), false);
        $this->get(route('enrollment-notes.edit', $note))->assertOk();
        $this->patch(route('enrollment-notes.update', $note), ['body' => '管理者による是正'])->assertRedirect();
        $this->assertSame('管理者による是正', $note->fresh()->body);
        $this->assertSame($coach->id, $note->fresh()->author_id);
        $this->delete(route('enrollment-notes.destroy', $note))->assertRedirect();
        $this->assertDatabaseMissing('enrollment_notes', ['id' => $note->id]);
    }

    public static function invalidBodies(): array
    {
        return ['empty' => [''], 'whitespace' => ['   '], 'null' => [null], 'array' => [['invalid']], 'over limit' => [str_repeat('文', 2001)]];
    }

    /** @dataProvider invalidBodies */
    public function test_invalid_body_is_rejected_on_creation_and_update_without_mutation(mixed $body): void
    {
        [$parent, $coach] = $this->scenario();
        $note = EnrollmentNote::factory()->for($parent)->create(['author_id' => $coach->id, 'body' => '既存の本文']);
        $this->actingAs($coach)->postJson(route('enrollments.notes.store', $parent), ['body' => $body])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->patchJson(route('enrollment-notes.update', $note), ['body' => $body])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->assertDatabaseCount('enrollment_notes', 1);
        $this->assertSame('既存の本文', $note->fresh()->body);
    }

    public function test_body_is_required_and_2000_multibyte_characters_are_accepted(): void
    {
        [$parent, $coach] = $this->scenario();
        $this->actingAs($coach)->postJson(route('enrollments.notes.store', $parent), [])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->post(route('enrollments.notes.store', $parent), ['body' => str_repeat('文', 2000)])->assertSessionHasNoErrors();
        $note = EnrollmentNote::query()->sole();
        $this->patchJson(route('enrollment-notes.update', $note), [])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->patch(route('enrollment-notes.update', $note), ['body' => str_repeat('字', 2000)])->assertSessionHasNoErrors();
        $this->assertSame(str_repeat('字', 2000), $note->fresh()->body);
    }

    public function test_html_errors_return_to_form_with_input_and_japanese_validation_message(): void
    {
        [$parent, $coach] = $this->scenario();
        $this->actingAs($coach)->from(route('enrollments.show', $parent))->post(route('enrollments.notes.store', $parent), ['body' => ''])
            ->assertRedirect(route('enrollments.show', $parent))->assertSessionHasErrors('body');
        $this->get(route('enrollments.show', $parent))->assertOk()->assertSee('メモ本文');
        $note = EnrollmentNote::factory()->for($parent)->create(['author_id' => $coach->id]);
        $this->from(route('enrollment-notes.edit', $note))->patch(route('enrollment-notes.update', $note), ['body' => str_repeat('a', 2001)])
            ->assertRedirect(route('enrollment-notes.edit', $note))->assertSessionHasErrors('body');
        $this->assertSame(str_repeat('a', 2001), session()->getOldInput('body'));
    }

    public function test_body_is_escaped_and_notes_from_another_enrollment_do_not_leak(): void
    {
        [$parent, $coach] = $this->scenario();
        $note = EnrollmentNote::factory()->for($parent)->create(['author_id' => $coach->id, 'body' => '<script>alert("internal")</script>']);
        EnrollmentNote::factory()->create(['body' => '対象外の秘密メモ']);
        $this->actingAs($coach)->get(route('enrollments.show', $parent))->assertOk()
            ->assertSee(e($note->body), false)->assertDontSee($note->body, false)->assertDontSee('対象外の秘密メモ');
    }
}
