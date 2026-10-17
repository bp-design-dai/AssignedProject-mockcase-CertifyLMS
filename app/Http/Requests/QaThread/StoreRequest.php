<?php

declare(strict_types=1);

namespace App\Http\Requests\QaThread;

use App\Enums\CertificationStatus;
use App\Models\QaThread;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', QaThread::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'certification_id' => ['required', 'ulid', Rule::exists('certifications', 'id')->where('status', CertificationStatus::Published->value)],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
        ];
    }

    public function attributes(): array
    {
        return ['certification_id' => '資格', 'title' => 'タイトル', 'body' => '本文'];
    }
}
