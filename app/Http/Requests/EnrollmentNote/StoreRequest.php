<?php

declare(strict_types=1);

namespace App\Http\Requests\EnrollmentNote;

use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $enrollment = $this->route('enrollment');

        return $enrollment instanceof Enrollment
            && ($this->user()?->can('create', [EnrollmentNote::class, $enrollment]) ?? false);
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
