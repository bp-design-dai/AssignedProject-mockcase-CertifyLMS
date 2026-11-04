<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\AiChatMessageStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiChatMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'role' => $this->role->value, 'status' => $this->status->value, 'content' => $this->content,
            'created_at' => $this->created_at?->toIso8601String(), 'updated_at' => $this->updated_at?->toIso8601String(),
            'retry_content' => $this->status === AiChatMessageStatus::Error ? $this->replyTo?->content : null];
    }
}
