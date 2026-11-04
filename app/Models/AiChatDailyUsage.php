<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiChatDailyUsage extends Model
{
    protected $fillable = ['user_id', 'usage_date', 'attempts'];

    protected $casts = ['attempts' => 'integer', 'usage_date' => 'date'];
}
