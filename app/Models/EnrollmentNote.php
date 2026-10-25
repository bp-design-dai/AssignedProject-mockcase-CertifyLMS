<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 受講登録単位の内部業務メモ。面談メモとは別データで、受講生には公開しない。
 * メモ自体は物理削除。SoftDeleteされた受講登録のメモは取得・URL解決から除外する。
 */
class EnrollmentNote extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = ['enrollment_id', 'author_id', 'body'];

    protected static function booted(): void
    {
        static::addGlobalScope('existingEnrollment', fn (Builder $query) => $query->whereHas('enrollment'));
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id')->withTrashed();
    }
}
