<?php

declare(strict_types=1);

namespace App\Http\Requests\MeetingPack;

use App\Models\MeetingPack;

class UpdateRequest extends StoreRequest
{
    public function authorize(): bool
    {
        $plan = $this->route('plan');

        return $plan instanceof MeetingPack
            && ($this->user()?->can('update', $plan) ?? false);
    }
}
