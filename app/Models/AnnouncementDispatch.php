<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** 二重送信を防ぐ内部受付記録。配信履歴の一覧・詳細には公開しない。 */
class AnnouncementDispatch extends Model
{
    use HasUlids;

    protected $fillable = ['idempotency_key', 'created_by_user_id', 'payload_hash', 'completed_at', 'payload', 'status', 'prepared_at', 'target_count', 'succeeded_count', 'failed_count', 'exception_class'];

    protected $casts = ['completed_at' => 'datetime', 'prepared_at' => 'datetime', 'payload' => 'array', 'target_count' => 'integer', 'succeeded_count' => 'integer', 'failed_count' => 'integer'];
}
