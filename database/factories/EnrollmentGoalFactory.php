<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EnrollmentGoal> */
class EnrollmentGoalFactory extends Factory
{
    protected $model = EnrollmentGoal::class;

    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'target_date' => now()->addWeek()->toDateString(),
            'achieved_at' => null,
        ];
    }

    public function achieved(): static
    {
        return $this->state(fn () => ['achieved_at' => now()]);
    }

    public function withoutTargetDate(): static
    {
        return $this->state(fn () => ['target_date' => null]);
    }
}
