<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AiChatConversation;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class AiChatConversationAccess
{
    public function find(User $user, string $id, string $ability = 'view'): AiChatConversation
    {
        $conversation = AiChatConversation::query()->findOrFail($id);
        Gate::forUser($user)->authorize($ability, $conversation);

        return $conversation;
    }
}
