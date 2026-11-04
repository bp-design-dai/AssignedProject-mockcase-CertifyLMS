<?php

declare(strict_types=1);

namespace App\Http\Requests\AiChat;

use App\Models\AiChatConversation;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', AiChatConversation::class) ?? false;
    }

    public function rules(): array
    {
        return ['title' => ['sometimes', 'required', 'string', 'max:50'], 'auto_title' => ['required_without:title', 'boolean']];
    }

    public function attributes(): array
    {
        return ['title' => 'タイトル', 'content' => 'メッセージ', 'message' => '最初の質問'];
    }
}
