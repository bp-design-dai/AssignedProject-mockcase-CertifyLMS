<?php

declare(strict_types=1);

namespace Tests\Feature\Http\EnrollmentNote;

use App\Enums\UserStatus;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    private function assignedNote(): array
    {
        $coach = User::factory()->coach()->create();
        $parent = Enrollment::factory()->create();
        $assignment = CertificationCoachAssignment::factory()->create(['certification_id' => $parent->certification_id, 'user_id' => $coach->id]);
        $note = EnrollmentNote::factory()->for($parent)->create(['author_id' => $coach->id, 'body' => '内部の観察記録']);

        return [$parent, $coach, $note, $assignment];
    }

    public function test_other_assigned_coach_can_read_but_cannot_edit_or_delete_note(): void
    {
        [$parent, , $note] = $this->assignedNote();
        $other = User::factory()->coach()->create();
        CertificationCoachAssignment::factory()->create(['certification_id' => $parent->certification_id, 'user_id' => $other->id]);
        $this->actingAs($other)->get(route('enrollments.show', $parent))->assertOk()->assertSee('内部の観察記録');
        $this->assertTrue(Gate::forUser($other)->allows('view', $note));
        $this->get(route('enrollment-notes.edit', $note))->assertForbidden();
        $this->patchJson(route('enrollment-notes.update', $note), ['body' => '改変'])->assertForbidden();
        $this->deleteJson(route('enrollment-notes.destroy', $note))->assertForbidden();
        $this->assertSame('内部の観察記録', $note->fresh()->body);
    }

    public function test_outside_coach_is_denied_all_paths_even_with_forged_ids_or_filters(): void
    {
        [$parent, , $note] = $this->assignedNote();
        $outside = User::factory()->coach()->create();
        $this->actingAs($outside)->get(route('enrollments.show', $parent, false).'?certification_id='.$parent->certification_id)->assertForbidden();
        $this->postJson(route('enrollments.notes.store', $parent), ['body' => '不正'])->assertForbidden();
        $this->get(route('enrollment-notes.edit', $note))->assertForbidden();
        $this->patchJson(route('enrollment-notes.update', $note), ['body' => null, 'author_id' => $outside->id])->assertForbidden();
        $this->deleteJson(route('enrollment-notes.destroy', $note))->assertForbidden();
        $this->assertDatabaseCount('enrollment_notes', 1);
        $this->assertSame('内部の観察記録', $note->fresh()->body);
    }

    public function test_unassignment_revokes_even_the_original_authors_access(): void
    {
        [$parent, $coach, $note, $assignment] = $this->assignedNote();
        $this->assertTrue(Gate::forUser($coach)->allows('update', $note));
        $assignment->update(['unassigned_at' => now()]);
        $this->assertFalse(Gate::forUser($coach)->allows('update', $note));
        $this->actingAs($coach)->get(route('enrollments.show', $parent))->assertForbidden();
        $this->postJson(route('enrollments.notes.store', $parent), ['body' => '不正'])->assertForbidden();
        $this->get(route('enrollment-notes.edit', $note))->assertForbidden();
        $this->patchJson(route('enrollment-notes.update', $note), ['body' => '不正'])->assertForbidden();
        $this->delete(route('enrollment-notes.destroy', $note))->assertForbidden();
        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id]);
    }

    public static function studentStates(): array
    {
        return [[UserStatus::InProgress], [UserStatus::Graduated]];
    }

    /** @dataProvider studentStates */
    public function test_owner_student_never_sees_notes_or_can_use_note_routes(UserStatus $status): void
    {
        [$parent, , $note] = $this->assignedNote();
        $student = $parent->user;
        $student->update(['status' => $status]);
        $this->actingAs($student)->get(route('enrollments.show', $parent))->assertOk()->assertDontSee('コーチメモ')->assertDontSee('内部の観察記録');
        $this->assertFalse(Gate::forUser($student)->allows('view', $note));
        $this->postJson(route('enrollments.notes.store', $parent), ['body' => '不正'])->assertForbidden();
        $this->get(route('enrollment-notes.edit', $note))->assertForbidden();
        $this->patchJson(route('enrollment-notes.update', $note), ['body' => '不正'])->assertForbidden();
        $this->delete(route('enrollment-notes.destroy', $note))->assertForbidden();
    }

    public function test_other_student_and_guest_cannot_access_notes(): void
    {
        [$parent, , $note] = $this->assignedNote();
        $student = User::factory()->create();
        $this->actingAs($student)->get(route('enrollments.show', $parent))->assertForbidden();
        $this->get(route('enrollment-notes.edit', $note))->assertForbidden();
        $this->app['auth']->forgetGuards();
        foreach ([['POST', route('enrollments.notes.store', $parent)], ['GET', route('enrollment-notes.edit', $note)], ['PATCH', route('enrollment-notes.update', $note)], ['DELETE', route('enrollment-notes.destroy', $note)]] as [$method, $url]) {
            $this->call($method, $url)->assertRedirect(route('login'));
        }
    }

    public function test_deleted_parent_excludes_notes_from_list_and_all_direct_urls(): void
    {
        [$parent, $coach, $note] = $this->assignedNote();
        $parent->delete();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('enrollments.show', $parent))->assertOk()->assertDontSee('コーチメモ')->assertDontSee('内部の観察記録');
        $this->assertSame(0, EnrollmentNote::query()->count());
        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id]);
        foreach ([$admin, $coach] as $actor) {
            $this->actingAs($actor)->post(route('enrollments.notes.store', $parent), ['body' => '不正'])->assertNotFound();
            $this->get(route('enrollment-notes.edit', $note))->assertNotFound();
            $this->patch(route('enrollment-notes.update', $note), ['body' => '不正'])->assertNotFound();
            $this->delete(route('enrollment-notes.destroy', $note))->assertNotFound();
        }
    }

    public function test_same_student_in_another_certification_does_not_extend_coach_access(): void
    {
        [$parent, $coach] = $this->assignedNote();
        $otherParent = Enrollment::factory()->for($parent->user)->create();
        $outsideNote = EnrollmentNote::factory()->for($otherParent)->create(['body' => '別資格限定メモ']);
        $this->actingAs($coach)->get(route('enrollments.show', $parent))->assertOk()->assertDontSee('別資格限定メモ');
        $this->get(route('enrollments.show', $otherParent))->assertForbidden();
        $this->get(route('enrollment-notes.edit', $outsideNote))->assertForbidden();
        $this->patch(route('enrollment-notes.update', $outsideNote), ['body' => '不正'])->assertForbidden();
        $this->delete(route('enrollment-notes.destroy', $outsideNote))->assertForbidden();
    }

    public function test_nonexistent_note_and_enrollment_return_404(): void
    {
        $admin = User::factory()->admin()->create();
        $id = (string) Str::ulid();
        $this->actingAs($admin)->get(route('enrollment-notes.edit', $id))->assertNotFound();
        $this->patch(route('enrollment-notes.update', $id), ['body' => '本文'])->assertNotFound();
        $this->delete(route('enrollment-notes.destroy', $id))->assertNotFound();
        $this->post(route('enrollments.notes.store', $id), ['body' => '本文'])->assertNotFound();
    }
}
