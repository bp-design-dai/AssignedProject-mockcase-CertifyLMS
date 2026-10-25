<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\EnrollmentNote;

use App\Enums\UserRole;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use App\UseCases\EnrollmentNote\DestroyAction;
use App\UseCases\EnrollmentNote\StoreAction;
use App\UseCases\EnrollmentNote\UpdateAction;
use Database\Seeders\EnrollmentNoteSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MutationTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(): array
    {
        $coach = User::factory()->coach()->create();
        $parent = Enrollment::factory()->create();
        $assignment = CertificationCoachAssignment::factory()->create(['certification_id' => $parent->certification_id, 'user_id' => $coach->id]);
        $note = EnrollmentNote::factory()->for($parent)->create(['author_id' => $coach->id, 'body' => '元の記録']);

        return [$parent, $coach, $note, $assignment];
    }

    public static function operations(): array
    {
        return [['store'], ['update'], ['destroy']];
    }

    private function mutate(string $operation, Enrollment $parent, User $actor, EnrollmentNote $note): void
    {
        match ($operation) {
            'store' => app(StoreAction::class)($parent, $actor, ['body' => '新しい記録']),
            'update' => app(UpdateAction::class)($note, $actor, ['body' => '更新する記録']),
            'destroy' => app(DestroyAction::class)($note, $actor),
        };
    }

    /** @dataProvider operations */
    public function test_action_rechecks_latest_assignment_even_for_cached_models(string $operation): void
    {
        [$parent, $coach, $note, $assignment] = $this->scenario();
        $note->load('enrollment.certification.coaches');
        $assignment->update(['unassigned_at' => now()]);
        try {
            $this->mutate($operation, $parent, $coach, $note);
            $this->fail('Revoked coach must be rejected.');
        } catch (AuthorizationException $e) {
            $this->assertSame(403, $e->status() ?? 403);
        }
        $this->assertDatabaseCount('enrollment_notes', 1);
        $this->assertSame('元の記録', $note->fresh()->body);
    }

    /** @dataProvider operations */
    public function test_deleted_parent_rejects_stale_action_without_mutation(string $operation): void
    {
        [$parent, $coach, $note] = $this->scenario();
        $parent->delete();
        try {
            $this->mutate($operation, $parent, $coach, $note);
            $this->fail('Deleted parent must be rejected.');
        } catch (ModelNotFoundException $e) {
            $this->assertSame(Enrollment::class, $e->getModel());
        }
        $this->assertDatabaseCount('enrollment_notes', 1);
        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id, 'body' => '元の記録']);
    }

    public static function failureEvents(): array
    {
        return [['store', 'created'], ['update', 'updated'], ['destroy', 'deleted']];
    }

    /** @dataProvider failureEvents */
    public function test_database_mutation_is_rolled_back_if_a_later_step_fails(string $operation, string $event): void
    {
        [$parent, $coach, $note] = $this->scenario();
        $level = DB::transactionLevel();
        $dispatcher = EnrollmentNote::getEventDispatcher();
        EnrollmentNote::setEventDispatcher(clone $dispatcher);
        EnrollmentNote::{$event}(fn () => throw new \RuntimeException('Simulated failure after SQL.'));
        try {
            $this->mutate($operation, $parent, $coach, $note);
            $this->fail('Mutation should fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated failure after SQL.', $e->getMessage());
        } finally {
            EnrollmentNote::setEventDispatcher($dispatcher);
        }
        $this->assertSame($level, DB::transactionLevel());
        $this->assertDatabaseCount('enrollment_notes', 1);
        $this->assertSame('元の記録', $note->fresh()->body);
        $this->assertDatabaseHas('enrollments', ['id' => $parent->id, 'deleted_at' => null]);
    }

    public function test_another_authors_note_cannot_be_changed_through_actions(): void
    {
        [$parent, , $note] = $this->scenario();
        $other = User::factory()->coach()->create();
        CertificationCoachAssignment::factory()->create(['certification_id' => $parent->certification_id, 'user_id' => $other->id]);
        foreach (['update', 'destroy'] as $operation) {
            try {
                $this->mutate($operation, $parent, $other, $note);
                $this->fail('Other author must be rejected.');
            } catch (AuthorizationException) {
                $this->assertSame('元の記録', $note->fresh()->body);
            }
        }
    }

    public function test_parent_foreign_key_cascades_only_target_notes_on_physical_deletion(): void
    {
        [$parent, , $note] = $this->scenario();
        $retained = EnrollmentNote::factory()->create();
        $parent->forceDelete();
        $this->assertDatabaseMissing('enrollment_notes', ['id' => $note->id]);
        $this->assertDatabaseHas('enrollment_notes', ['id' => $retained->id]);
    }

    public function test_soft_deleted_author_remains_attributed_without_rewriting_ownership(): void
    {
        [, $coach, $note] = $this->scenario();
        $coach->delete();
        $fresh = $note->fresh();
        $this->assertSame($coach->id, $fresh->author->id);
        $this->assertSame($coach->id, $fresh->author_id);
    }

    public function test_seed_spans_students_certifications_and_coaches_without_changing_assignments_or_overwriting_notes(): void
    {
        [$first, $coach, $note] = $this->scenario();
        $secondCoach = User::factory()->coach()->create();
        $admin = User::query()->where('role', UserRole::Admin)->orderBy('id')->firstOrFail();
        CertificationCoachAssignment::factory()->create(['certification_id' => $first->certification_id, 'user_id' => $secondCoach->id]);
        $second = Enrollment::factory()->create();
        CertificationCoachAssignment::factory()->create(['certification_id' => $second->certification_id, 'user_id' => $secondCoach->id]);
        $assignments = DB::table('certification_coach_assignments')->orderBy('id')->get()->toJson();
        $this->seed(EnrollmentNoteSeeder::class);
        $this->assertDatabaseCount('enrollment_notes', 5);
        $this->assertSame('元の記録', $note->fresh()->body);
        $this->assertCount(3, $first->notes);
        $this->assertCount(2, $second->notes);
        $this->assertTrue($first->notes->contains('author_id', $coach->id));
        $this->assertTrue($first->notes->contains('author_id', $secondCoach->id));
        $this->assertTrue($first->notes->contains('author_id', $admin->id));
        $this->seed(EnrollmentNoteSeeder::class);
        $this->assertDatabaseCount('enrollment_notes', 5);
        $this->assertSame($assignments, DB::table('certification_coach_assignments')->orderBy('id')->get()->toJson());
        $this->assertSame('元の記録', $note->fresh()->body);
    }
}
