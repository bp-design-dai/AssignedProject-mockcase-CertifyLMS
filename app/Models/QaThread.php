<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QaThreadStatus;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QaThread extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = ['certification_id', 'user_id', 'title', 'body', 'status', 'resolved_at'];

    protected $casts = [
        'status' => QaThreadStatus::class,
        'resolved_at' => 'datetime',
    ];

    public function certification(): BelongsTo
    {
        return $this->belongsTo(Certification::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(QaReply::class)->orderBy('created_at')->orderBy('id');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role === UserRole::Admin) {
            return $query;
        }

        return $query->whereHas('certification', function (Builder $certifications) use ($user) {
            $certifications->published();

            if ($user->role === UserRole::Coach) {
                $certifications->assignedTo($user);
            }
        });
    }
}
