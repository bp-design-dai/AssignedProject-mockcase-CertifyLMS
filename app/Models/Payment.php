<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payment extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = ['user_id', 'meeting_pack_id', 'pack_name', 'amount', 'quantity', 'currency', 'status', 'stripe_session_id', 'stripe_payment_intent_id', 'failure_code', 'fulfilled_at'];

    protected $casts = ['status' => PaymentStatus::class, 'amount' => 'integer', 'quantity' => 'integer', 'fulfilled_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function meetingPack(): BelongsTo
    {
        return $this->belongsTo(MeetingPack::class);
    }

    public function quotaTransaction(): HasOne
    {
        return $this->hasOne(MeetingQuotaTransaction::class, 'related_payment_id');
    }
}
