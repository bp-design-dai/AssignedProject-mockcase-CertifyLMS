<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Models\User;
use App\Services\AiChatConversationAccess;
use App\Services\AiChatMessageService;

final class SendMessageAction
{
    public function __construct(private readonly AiChatConversationAccess $access, private readonly AiChatMessageService $messages) {}

    public function __invoke(User $user, string $id, array $data): array
    {
        return $this->messages->send($user, $this->access->find($user, $id, 'update'), $data['content']);
    }
}
