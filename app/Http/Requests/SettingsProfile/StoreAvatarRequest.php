<?php

declare(strict_types=1);

namespace App\Http\Requests\SettingsProfile;

use Illuminate\Foundation\Http\FormRequest;

class StoreAvatarRequest extends FormRequest
{
    protected $redirectRoute = 'settings.profile.edit';

    public function authorize(): bool
    {
        return $this->user()?->can('updateOwnAvatar', $this->user()) ?? false;
    }

    public function rules(): array
    {
        return ['avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']];
    }
}
