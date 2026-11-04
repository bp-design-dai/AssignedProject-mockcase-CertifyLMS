<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiChatMessage extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = ['conversation_id', 'reply_to_id', 'sequence', 'role', 'status', 'content', 'model', 'input_tokens', 'output_tokens', 'total_tokens', 'response_time_ms', 'error_code', 'upstream_status'];

    protected $hidden = ['model', 'input_tokens', 'output_tokens', 'total_tokens', 'response_time_ms', 'error_code', 'upstream_status'];

    protected $casts = ['role' => AiChatMessageRole::class, 'status' => AiChatMessageStatus::class];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiChatConversation::class, 'conversation_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }
}
