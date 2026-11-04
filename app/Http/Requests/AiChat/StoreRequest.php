<?php

declare(strict_types=1);

namespace App\Http\Requests\AiChat;

use App\Models\AiChatConversation;
use Illuminate\Foundation\Http\FormRequest;

final class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', AiChatConversation::class) ?? false;
    }

    public function rules(): array
    {
        return ['section_id' => ['nullable', 'ulid'], 'certification_id' => ['nullable', 'ulid'], 'source' => ['nullable', 'in:widget,full-screen'], 'message' => ['nullable', 'string', 'max:2000']];
    }

    public function attributes(): array
    {
        return ['title' => 'タイトル', 'content' => 'メッセージ', 'message' => '最初の質問'];
    }
}
