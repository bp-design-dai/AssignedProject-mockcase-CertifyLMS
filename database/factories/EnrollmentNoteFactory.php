<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EnrollmentNote> */
class EnrollmentNoteFactory extends Factory
{
    protected $model = EnrollmentNote::class;

    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'author_id' => User::factory()->coach(),
            'body' => fake()->paragraph(),
        ];
    }
}
