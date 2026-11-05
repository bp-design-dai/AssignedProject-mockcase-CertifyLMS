<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => '決済の反映を確認中',
            self::Succeeded => '支払確定・回数反映済み',
            self::Failed => '決済失敗',
            self::Expired => '決済期限切れ・中断',
        };
    }
}
