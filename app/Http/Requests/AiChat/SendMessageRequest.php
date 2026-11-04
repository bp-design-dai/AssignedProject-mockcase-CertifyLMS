<?php

declare(strict_types=1);

namespace App\Http\Requests\AiChat;

use App\Models\AiChatConversation;
use Illuminate\Foundation\Http\FormRequest;

final class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', AiChatConversation::class) ?? false;
    }

    public function rules(): array
    {
        return ['content' => ['required', 'string', 'max:2000']];
    }

    public function attributes(): array
    {
        return ['title' => 'タイトル', 'content' => 'メッセージ', 'message' => '最初の質問'];
    }
}
