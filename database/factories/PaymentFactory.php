<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory()->student(), 'meeting_pack_id' => MeetingPack::factory()->published(),
            'pack_name' => '検証用面談パック', 'amount' => 3000, 'quantity' => 1, 'currency' => 'jpy', 'status' => PaymentStatus::Pending];
    }
}
