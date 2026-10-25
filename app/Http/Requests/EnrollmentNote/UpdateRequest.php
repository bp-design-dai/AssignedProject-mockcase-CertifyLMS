<?php

declare(strict_types=1);

namespace App\Http\Requests\EnrollmentNote;

use App\Models\EnrollmentNote;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $note = $this->route('note');

        return $note instanceof EnrollmentNote && ($this->user()?->can('update', $note) ?? false);
    }

    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:2000']];
    }

    public function attributes(): array
    {
        return ['body' => 'メモ本文'];
    }
}
