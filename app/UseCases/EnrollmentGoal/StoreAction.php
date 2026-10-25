<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentGoal;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class StoreAction
{
    public function __invoke(Enrollment $enrollment, array $data): EnrollmentGoal
    {
        return DB::transaction(function () use ($enrollment, $data) {
            $parent = Enrollment::query()->lockForUpdate()->findOrFail($enrollment->id);

            return $parent->goals()->create(Arr::only($data, ['title', 'description', 'target_date']));
        });
    }
}
