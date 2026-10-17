<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<QaThread> */
class QaThreadFactory extends Factory
{
    protected $model = QaThread::class;

    public function definition(): array
    {
        return [
            'certification_id' => Certification::factory()->published(),
            'user_id' => User::factory()->student()->inProgress(),
            'title' => fake()->sentence(),
            'body' => fake()->paragraph(),
            'status' => QaThreadStatus::Open,
            'resolved_at' => null,
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn () => ['status' => QaThreadStatus::Resolved, 'resolved_at' => now()]);
    }
}
