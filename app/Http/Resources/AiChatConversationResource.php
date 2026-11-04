<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\AiChatContextService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiChatConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $context = app(AiChatContextService::class);

        return ['id' => $this->id, 'title' => $this->title, 'auto_title' => $this->auto_title,
            'context' => $context->describe($context->forConversation($this->resource)),
            'last_message_at' => $this->last_message_at?->toIso8601String()];
    }
}
