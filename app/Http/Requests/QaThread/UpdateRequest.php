<?php

declare(strict_types=1);

namespace App\Http\Requests\QaThread;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('thread')) ?? false;
    }

    public function rules(): array
    {
        return ['title' => ['required', 'string', 'max:200'], 'body' => ['required', 'string', 'max:5000']];
    }

    public function attributes(): array
    {
        return ['title' => 'タイトル', 'body' => '本文'];
    }
}
