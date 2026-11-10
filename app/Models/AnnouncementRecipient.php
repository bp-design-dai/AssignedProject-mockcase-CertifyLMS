<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class AnnouncementRecipient extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $attributes = ['status' => 'pending'];
}
