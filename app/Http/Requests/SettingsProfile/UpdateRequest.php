<?php

declare(strict_types=1);

namespace App\Http\Requests\SettingsProfile;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRequest extends FormRequest
{
    protected $redirectRoute = 'settings.profile.edit';

    public function authorize(): bool
    {
        return $this->user()?->can('updateOwnProfile', $this->user()) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'meeting_url' => [
                Rule::prohibitedIf($this->user()?->role !== UserRole::Coach),
                'nullable', 'string', 'url', 'max:500',
            ],
        ];
    }
}
