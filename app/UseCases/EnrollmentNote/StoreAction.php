<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentNote;

use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class StoreAction
{
    public function __invoke(Enrollment $enrollment, User $author, array $data): EnrollmentNote
    {
        return DB::transaction(function () use ($enrollment, $author, $data) {
            $parent = Enrollment::query()->lockForUpdate()->findOrFail($enrollment->id);
            Gate::forUser($author)->authorize('create', [EnrollmentNote::class, $parent]);

            return $parent->notes()->create(['author_id' => $author->id, 'body' => $data['body']]);
        });
    }
}
