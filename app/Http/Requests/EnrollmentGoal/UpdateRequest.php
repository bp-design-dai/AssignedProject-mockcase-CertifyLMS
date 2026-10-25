<?php

declare(strict_types=1);

namespace App\Http\Requests\EnrollmentGoal;

class UpdateRequest extends StoreRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('goal')) ?? false;
    }
}
