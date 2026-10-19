<?php

declare(strict_types=1);

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Validation\Rule;

final class IndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', DatabaseNotification::class);
    }

    public function rules(): array
    {
        return ['tab' => ['sometimes', Rule::in(['all', 'unread'])]];
    }
}
