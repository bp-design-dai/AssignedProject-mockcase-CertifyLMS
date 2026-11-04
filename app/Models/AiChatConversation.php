<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiChatConversation extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = ['user_id', 'enrollment_id', 'section_id', 'title', 'auto_title', 'question_count', 'title_question_count', 'title_metadata', 'public_context', 'last_message_at'];

    protected $hidden = ['title_metadata', 'public_context'];

    protected $casts = ['auto_title' => 'boolean', 'question_count' => 'integer', 'title_question_count' => 'integer', 'title_metadata' => 'array', 'public_context' => 'array', 'last_message_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiChatMessage::class, 'conversation_id')->orderBy('sequence');
    }
}
