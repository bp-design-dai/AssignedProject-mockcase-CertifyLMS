<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use Illuminate\Database\Seeder;

final class EnrollmentGoalSeeder extends Seeder
{
    public function run(): void
    {
        Enrollment::query()->whereHas('user', fn ($q) => $q->where('role', UserRole::Student))->each(function (Enrollment $enrollment): void {
            $goals = [
                ['title' => 'デモ目標: 基礎を復習する', 'target_date' => now()->addWeek()->toDateString(), 'achieved_at' => null],
                ['title' => 'デモ目標: 学習計画を見直す', 'target_date' => null, 'achieved_at' => null],
                ['title' => 'デモ目標: 最初の教材を読む', 'target_date' => null, 'achieved_at' => now()->subDay()],
            ];
            foreach ($goals as $goal) {
                EnrollmentGoal::firstOrCreate(
                    ['enrollment_id' => $enrollment->id, 'title' => $goal['title']],
                    [...$goal, 'description' => '個人目標の表示・操作を確認するためのデモデータです。'],
                );
            }
        });
    }
}
