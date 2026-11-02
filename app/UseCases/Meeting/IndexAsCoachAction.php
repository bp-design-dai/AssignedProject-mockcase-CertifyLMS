<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Models\Meeting;
use App\Models\User;

final class IndexAsCoachAction
{
    /** @return array<string, mixed> */
    public function __invoke(User $coach, array $filters): array
    {
        $filter = $filters['filter'] ?? 'upcoming';
        $studentId = $filters['student'] ?? null;
        $enrollmentId = $filters['enrollment'] ?? null;

        $query = Meeting::query()
            ->with(['enrollment.certification', 'student'])
            ->forCoach($coach)
            ->when($studentId, fn ($q, $id) => $q->where('student_id', $id))
            ->when($enrollmentId, fn ($q, $id) => $q->where('enrollment_id', $id));

        // upcoming: 次の面談を一番上に置く (昇順) / past + all: 直近の活動を一番上 (降順)
        $meetings = match ($filter) {
            'past' => $query->past()->orderByDesc('scheduled_at')->paginate(20),
            'all' => $query->orderByDesc('scheduled_at')->paginate(20),
            default => $query->upcoming()->orderBy('scheduled_at')->paginate(20),
        };

        return [
            'meetings' => $meetings,
            'filter' => $filter,
            'studentFilter' => $studentId,
            'enrollmentFilter' => $enrollmentId,
        ];
    }
}
