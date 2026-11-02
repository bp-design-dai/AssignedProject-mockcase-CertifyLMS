<?php

declare(strict_types=1);

namespace App\Http\Requests\GoogleCalendar;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

final class CallbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Coach;
    }

    public function rules(): array
    {
        return ['state' => ['nullable', 'string', 'max:512'], 'code' => ['nullable', 'string', 'max:4096'], 'error' => ['nullable', 'string', 'max:255']];
    }
}
