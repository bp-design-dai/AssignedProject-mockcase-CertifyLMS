<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Models\AiChatConversation;
use App\Models\User;
use App\Services\AiChatContextService;
use App\Services\AiChatMessageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class StoreAction
{
    public function __construct(private readonly AiChatContextService $context, private readonly AiChatMessageService $messages) {}

    public function __invoke(User $user, array $data): array
    {
        Gate::forUser($user)->authorize('create', AiChatConversation::class);
        $context = $this->context->resolve($user, $data['section_id'] ?? null, $data['certification_id'] ?? null);
        [$conversation, $created] = DB::transaction(function () use ($user, $context) {
            $owner = User::query()->lockForUpdate()->findOrFail($user->id);
            Gate::forUser($owner)->authorize('create', AiChatConversation::class);
            $conversation = $context['section'] ? $owner->aiChatConversations()->where('section_id', $context['section']->id)->first() : null;
            if ($conversation) {
                return [$conversation, false];
            }

            return [$owner->aiChatConversations()->create(['section_id' => $context['section']?->id, 'enrollment_id' => $context['enrollment']?->id, 'public_context' => ! $context['enrollment'] && $context['certification'] ? ['certification_id' => $context['certification']->id] : null, 'title' => '新しい相談', 'auto_title' => true]), true];
        });
        if (isset($data['message']) && trim($data['message']) !== '') {
            return $this->messages->send($user, $conversation, $data['message']) + ['created' => $created];
        }

        return ['conversation' => $conversation, 'created' => $created, 'status' => $created ? 201 : 200];
    }
}
