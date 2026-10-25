<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentNote;

use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class UpdateAction
{
    public function __invoke(EnrollmentNote $note, User $user, array $data): void
    {
        DB::transaction(function () use ($note, $user, $data) {
            $parent = Enrollment::query()->lockForUpdate()->findOrFail($note->enrollment_id);
            $current = EnrollmentNote::query()->lockForUpdate()->findOrFail($note->id);
            $current->setRelation('enrollment', $parent);
            Gate::forUser($user)->authorize('update', $current);
            $current->update(['body' => $data['body']]);
        });
    }
}
