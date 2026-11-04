<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Enums\EnrollmentStatus;
use App\Models\User;
use App\Services\AiChatContextService;
use App\Services\AiChatConversationAccess;

final class ShowAction
{
    public function __construct(private readonly AiChatConversationAccess $access, private readonly AiChatContextService $context) {}

    public function __invoke(User $user, string $id): array
    {
        $conversation = $this->access->find($user, $id)->load('messages.replyTo');
        $history = $user->aiChatConversations()->with([
            'enrollment' => fn ($q) => $q->where('user_id', $user->id)
                ->whereIn('status', [EnrollmentStatus::Learning, EnrollmentStatus::Passed])
                ->whereHas('certification', fn ($c) => $c->published())->with('certification'),
            'section' => fn ($q) => $q->published()
                ->whereHas('chapter.part.certification', fn ($c) => $c->published())
                ->whereHas('chapter.part.certification.enrollments', fn ($e) => $e->where('user_id', $user->id)
                    ->whereIn('status', [EnrollmentStatus::Learning, EnrollmentStatus::Passed])),
        ])->orderByDesc('last_message_at')->orderByDesc('id')->paginate(30, ['*'], 'history_page');

        return ['conversation' => $conversation, 'historyAll' => $history, 'conversationContext' => $this->context->describe($this->context->forConversation($conversation))];
    }
}
