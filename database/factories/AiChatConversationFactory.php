<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AiChatConversationFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory()->student()->inProgress(), 'title' => '新しい相談', 'auto_title' => true];
    }
}
