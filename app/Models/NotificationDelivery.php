<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class NotificationDelivery extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $casts = ['sent_at' => 'datetime', 'failed_at' => 'datetime', 'skipped_at' => 'datetime'];
}
