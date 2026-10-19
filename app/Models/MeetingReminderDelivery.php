<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MeetingReminderWindow;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingReminderDelivery extends Model
{
    use HasUlids;

    protected $fillable = ['meeting_id', 'user_id', 'window', 'channel', 'sent_at'];

    protected $casts = ['window' => MeetingReminderWindow::class, 'sent_at' => 'datetime'];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
