<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Enums\CertificationStatus;
use App\Enums\UserRole;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;

final class IndexAction
{
    public function __invoke(User $user, array $filters): array
    {
        $certifications = Certification::query()->orderBy('name');

        if ($user->role !== UserRole::Admin) {
            $certifications->published();
        }
        if ($user->role === UserRole::Coach) {
            $certifications->assignedTo($user);
        }

        $threads = QaThread::query()->visibleTo($user)
            ->when($filters['certification_id'] ?? null, fn ($query, $id) => $query->where('certification_id', $id))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when(isset($filters['keyword']) && $filters['keyword'] !== '', fn ($query) => $query->where('body', 'like', '%'.$filters['keyword'].'%'))
            ->with(['user', 'certification'])
            ->withCount('replies')
            ->latest()->orderByDesc('id')
            ->paginate(20)->withQueryString();

        return [
            'threads' => $threads,
            'filters' => $filters,
            'certifications' => $certifications->get(),
            'publishedStatus' => CertificationStatus::Published,
        ];
    }
}
