<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnrollmentGoal extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = ['enrollment_id', 'title', 'description', 'target_date', 'achieved_at'];

    protected $casts = ['target_date' => 'date', 'achieved_at' => 'datetime'];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function isAchieved(): bool
    {
        return $this->achieved_at !== null;
    }

    public function scopeDisplayOrder(Builder $query): Builder
    {
        return $query
            ->orderByRaw('CASE WHEN achieved_at IS NULL THEN 0 ELSE 1 END')
            ->orderByRaw('CASE WHEN achieved_at IS NULL AND target_date IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('CASE WHEN achieved_at IS NULL THEN target_date END ASC')
            ->orderByDesc('achieved_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}
