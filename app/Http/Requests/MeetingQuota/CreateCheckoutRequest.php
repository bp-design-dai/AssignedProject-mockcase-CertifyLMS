<?php

declare(strict_types=1);

namespace App\Http\Requests\MeetingQuota;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;

class CreateCheckoutRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // Do not retain unsolicited card fields, prices or credentials in validation flash data.
        $this->replace($this->only('meeting_pack_id'));
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', Payment::class) ?? false;
    }

    public function rules(): array
    {
        return ['meeting_pack_id' => ['required', 'ulid']];
    }
}
