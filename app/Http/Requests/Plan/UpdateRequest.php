<?php

declare(strict_types=1);

namespace App\Http\Requests\Plan;

use App\Models\Plan;

class UpdateRequest extends StoreRequest
{
    public function authorize(): bool
    {
        $plan = $this->route('plan');

        return $plan instanceof Plan
            && ($this->user()?->can('update', $plan) ?? false);
    }
}
