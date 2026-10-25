<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentNote;

use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class DestroyAction
{
    public function __invoke(EnrollmentNote $note, User $user): void
    {
        DB::transaction(function () use ($note, $user) {
            $parent = Enrollment::query()->lockForUpdate()->findOrFail($note->enrollment_id);
            $current = EnrollmentNote::query()->lockForUpdate()->findOrFail($note->id);
            $current->setRelation('enrollment', $parent);
            Gate::forUser($user)->authorize('delete', $current);
            $current->delete();
        });
    }
}
