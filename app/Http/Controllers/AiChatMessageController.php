<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AiChat\SendMessageRequest;
use App\Http\Resources\AiChatConversationResource;
use App\Http\Resources\AiChatMessageResource;
use App\UseCases\AiChat\SendMessageAction;

final class AiChatMessageController extends Controller
{
    public function store(SendMessageRequest $request, string $conversation, SendMessageAction $action)
    {
        $result = $action($request->user(), $conversation, $request->validated());

        return response()->json(['conversation' => (new AiChatConversationResource($result['conversation']))->resolve(),
            'user_message' => (new AiChatMessageResource($result['user_message']))->resolve(),
            'assistant_message' => (new AiChatMessageResource($result['assistant_message']))->resolve(),
            'upstream_status' => $result['upstream_status']], $result['status']);
    }
}
