<?php

declare(strict_types=1);

namespace App\UseCases\Enrollment;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use Illuminate\Database\Eloquent\Collection;

/**
 * Enrollment 詳細取得 Action。受講生 / コーチ / admin 共通(認可は Controller の Policy で済ませる前提)。
 *
 * 詳細ビューで必要な coaches / 修了証 / 最新の状態遷移ログを eager load する。
 */
final class ShowAction
{
    public function __invoke(Enrollment $enrollment): Enrollment
    {
        $enrollment->loadMissing([
            'certification.category',
            'certification.coaches',
            'certificate',
            'latestStatusLog.changedBy',
        ]);

        if (auth()->user()?->can('viewAny', [EnrollmentGoal::class, $enrollment])) {
            $enrollment->load('goals');
        } else {
            $enrollment->setRelation('goals', new Collection);
        }

        return $enrollment;
    }
}
