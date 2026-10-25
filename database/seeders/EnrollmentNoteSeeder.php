<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use Illuminate\Database\Seeder;

final class EnrollmentNoteSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->where('role', UserRole::Admin)->orderBy('id')->first();
        Enrollment::query()->whereHas('user', fn ($q) => $q->where('role', UserRole::Student))
            ->with('certification.coaches')->each(function (Enrollment $enrollment) use ($admin): void {
                foreach ($enrollment->certification->coaches as $coach) {
                    EnrollmentNote::firstOrCreate(
                        ['enrollment_id' => $enrollment->id, 'author_id' => $coach->id],
                        ['body' => 'デモメモ: 学習状況を確認し、次回のフォローで復習する論点を整理する。'],
                    );
                }
                if ($admin !== null) {
                    EnrollmentNote::firstOrCreate(
                        ['enrollment_id' => $enrollment->id, 'author_id' => $admin->id],
                        ['body' => 'デモメモ: 運営側の継続観察。コーチ間で申し送り事項を共有する。'],
                    );
                }
            });
    }
}
