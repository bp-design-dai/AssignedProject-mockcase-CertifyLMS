<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentGoal;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use Illuminate\Support\Facades\DB;

final class UnmarkAchievedAction
{
    public function __invoke(EnrollmentGoal $goal): void
    {
        DB::transaction(function () use ($goal) {
            Enrollment::query()->lockForUpdate()->findOrFail($goal->enrollment_id);
            $locked = EnrollmentGoal::query()->lockForUpdate()->findOrFail($goal->id);
            if ($locked->isAchieved()) {
                $locked->update(['achieved_at' => null]);
            }
        });
    }
}
