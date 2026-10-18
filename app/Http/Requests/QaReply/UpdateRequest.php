<?php

declare(strict_types=1);

namespace App\Http\Requests\QaReply;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('reply')) ?? false;
    }

    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:5000']];
    }

    public function attributes(): array
    {
        return ['body' => '回答の本文'];
    }
}
