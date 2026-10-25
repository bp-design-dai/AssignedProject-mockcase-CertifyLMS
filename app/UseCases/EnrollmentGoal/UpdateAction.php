<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentGoal;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class UpdateAction
{
    public function __invoke(EnrollmentGoal $goal, array $data): void
    {
        DB::transaction(function () use ($goal, $data) {
            Enrollment::query()->lockForUpdate()->findOrFail($goal->enrollment_id);
            $locked = EnrollmentGoal::query()->lockForUpdate()->findOrFail($goal->id);
            $locked->update(Arr::only($data, ['title', 'description', 'target_date']));
        });
    }
}
