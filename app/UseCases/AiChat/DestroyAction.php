<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Models\AiChatConversation;
use App\Models\User;
use App\Services\AiChatConversationAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class DestroyAction
{
    public function __construct(private readonly AiChatConversationAccess $access) {}

    public function __invoke(User $user, string $id): void
    {
        $this->access->find($user, $id, 'delete');
        DB::transaction(function () use ($user, $id) {
            $owner = User::query()->lockForUpdate()->findOrFail($user->id);
            $conversation = AiChatConversation::query()->lockForUpdate()->findOrFail($id);
            Gate::forUser($owner)->authorize('delete', $conversation);
            $conversation->delete();
        });
    }
}
