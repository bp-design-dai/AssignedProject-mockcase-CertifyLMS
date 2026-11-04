<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AiChat\StoreRequest;
use App\Http\Requests\AiChat\UpdateRequest;
use App\Http\Resources\AiChatConversationResource;
use App\Http\Resources\AiChatMessageResource;
use App\UseCases\AiChat\DestroyAction;
use App\UseCases\AiChat\IndexAction;
use App\UseCases\AiChat\ShowAction;
use App\UseCases\AiChat\StoreAction;
use App\UseCases\AiChat\UpdateAction;
use Illuminate\Http\Request;

final class AiChatController extends Controller
{
    public function index(Request $request, IndexAction $action)
    {
        $conversation = $action($request->user());

        return $conversation ? redirect()->route('ai-chat.conversations.show', $conversation) : view('ai-chat.empty-state');
    }

    public function store(StoreRequest $request, StoreAction $action)
    {
        $result = $action($request->user(), $request->validated());
        if (! $request->expectsJson()) {
            return redirect()->route('ai-chat.conversations.show', $result['conversation'])->with($result['status'] === 502 ? 'error' : 'success', $result['status'] === 502 ? 'AIが応答できませんでした。質問は保存しました。同じ内容を再送できます。' : '相談を開始しました。');
        }
        $payload = ['conversation' => (new AiChatConversationResource($result['conversation']))->resolve()];
        if (isset($result['user_message'])) {
            $payload += ['user_message' => (new AiChatMessageResource($result['user_message']))->resolve(), 'assistant_message' => (new AiChatMessageResource($result['assistant_message']))->resolve(), 'upstream_status' => $result['upstream_status']];
        }

        return response()->json($payload, $result['status']);
    }

    public function show(Request $request, string $conversation, ShowAction $action)
    {
        $data = $action($request->user(), $conversation);

        return $request->expectsJson() ? response()->json(['conversation' => (new AiChatConversationResource($data['conversation']))->resolve(), 'messages' => AiChatMessageResource::collection($data['conversation']->messages)->resolve()]) : view('ai-chat.show', $data);
    }

    public function update(UpdateRequest $request, string $conversation, UpdateAction $action)
    {
        $updated = $action($request->user(), $conversation, $request->validated());

        return $request->expectsJson() ? response()->json(['conversation' => (new AiChatConversationResource($updated))->resolve()]) : redirect()->route('ai-chat.conversations.show', $updated)->with('success', 'タイトルの設定を保存しました。');
    }

    public function destroy(Request $request, string $conversation, DestroyAction $action)
    {
        $action($request->user(), $conversation);

        return $request->expectsJson() ? response()->noContent() : redirect()->route('ai-chat.index')->with('success', '会話を削除しました。');
    }
}
