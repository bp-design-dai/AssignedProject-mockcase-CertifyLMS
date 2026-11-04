<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Models\AiChatConversation;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class IndexAction
{
    public function __invoke(User $user): ?AiChatConversation
    {
        Gate::forUser($user)->authorize('viewAny', AiChatConversation::class);

        return $user->aiChatConversations()->orderByDesc('last_message_at')->orderByDesc('id')->first();
    }
}
