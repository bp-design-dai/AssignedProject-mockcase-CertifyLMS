<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** 二重送信を防ぐ内部受付記録。配信履歴の一覧・詳細には公開しない。 */
class AnnouncementDispatch extends Model
{
    use HasUlids;

    protected $fillable = ['idempotency_key', 'created_by_user_id', 'payload_hash', 'completed_at'];

    protected $casts = ['completed_at' => 'datetime'];
}
