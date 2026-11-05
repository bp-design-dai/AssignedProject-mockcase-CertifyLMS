<?php

declare(strict_types=1);

namespace App\Http\Requests\MeetingQuota;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;

class CheckoutResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Payment::class) ?? false;
    }

    public function rules(): array
    {
        return ['session_id' => ['nullable', 'string', 'max:255', 'regex:/^cs_test_[A-Za-z0-9_]+$/']];
    }
}
