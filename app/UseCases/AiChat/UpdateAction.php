<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Models\AiChatConversation;
use App\Models\User;
use App\Services\AiChatConversationAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class UpdateAction
{
    public function __construct(private readonly AiChatConversationAccess $access) {}

    public function __invoke(User $user, string $id, array $data): AiChatConversation
    {
        $this->access->find($user, $id, 'update');

        return DB::transaction(function () use ($user, $id, $data) {
            $owner = User::query()->lockForUpdate()->findOrFail($user->id);
            $conversation = AiChatConversation::query()->lockForUpdate()->findOrFail($id);
            Gate::forUser($owner)->authorize('update', $conversation);
            if (isset($data['title']) && $data['title'] !== $conversation->title) {
                $conversation->title = $data['title'];
                $conversation->auto_title = false;
            } elseif (array_key_exists('auto_title', $data)) {
                $conversation->auto_title = (bool) $data['auto_title'];
            }
            $conversation->save();

            return $conversation;
        });
    }
}
