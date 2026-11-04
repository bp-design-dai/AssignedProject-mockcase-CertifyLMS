<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

class AiChatMessageFactory extends Factory
{
    public function definition(): array
    {
        return ['conversation_id' => AiChatConversation::factory(), 'sequence' => fn (array $attributes) => (int) AiChatMessage::where('conversation_id', $attributes['conversation_id'])->max('sequence') + 1,
            'role' => AiChatMessageRole::User, 'status' => AiChatMessageStatus::Completed, 'content' => '学習の進め方を教えてください。'];
    }
}
